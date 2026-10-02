<?php

namespace App\Http\Controllers\Admin;

use App\Models\Host;
use App\Models\Product;
use App\Models\ProductConfigGroup;
use App\Models\ProductConfigLink;
use App\Models\ProductConfigOption;
use App\Models\Pricing;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\ModuleRegistry;
use App\Services\Admin\SettingService;
use App\Services\PricingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 商品设置 — product list/tree, the product editor (all ten tabs), product
 * groups, download files and the upstream price sync helpers.
 */
class ProductController extends AdminController
{
    /**
     * `GET product_list_page` — first-level groups → groups → products tree.
     */
    public function listPage(Request $request)
    {
        $firstGroups = DB::table('product_first_groups')->orderBy('order')->get();
        $groups = DB::table('product_groups')->orderBy('order')->get();
        $products = Product::query()->orderBy('order')->get();

        $tree = $firstGroups->map(function ($first) use ($groups, $products) {
            $children = $groups
                ->where('gid', $first->id)
                ->map(function ($group) use ($products) {
                    return $this->groupNode($group, $products->where('gid', $group->id));
                })
                ->values()
                ->all();

            return [
                'id' => (int) $first->id,
                'name' => $first->name,
                'hidden' => (int) $first->hidden,
                'order' => (int) $first->order,
                'create_time' => (int) $first->create_time,
                'update_time' => (int) $first->update_time,
                'is_upstream' => (int) $first->is_upstream,
                'zjmf_api_id' => (int) $first->zjmf_api_id,
                'groups' => $children,
            ];
        })->values()->all();

        // Products whose group was deleted still need to be listed somewhere.
        $orphans = $products->whereNotIn('gid', $groups->pluck('id')->all());

        if ($orphans->isNotEmpty()) {
            $tree[] = [
                'id' => 0,
                'name' => '未分组',
                'hidden' => 0,
                'order' => 0,
                'create_time' => 0,
                'update_time' => 0,
                'is_upstream' => 0,
                'zjmf_api_id' => 0,
                'groups' => [[
                    'id' => 0,
                    'name' => '未分组',
                    'headline' => '',
                    'tagline' => '',
                    'order_frm_tpl' => '',
                    'disabled_gateways' => '',
                    'hidden' => 0,
                    'order' => 0,
                    'type' => 1,
                    'create_time' => 0,
                    'update_time' => 0,
                    'gid' => 0,
                    'tpl_type' => 'default',
                    'alias' => '',
                    'is_upstream' => 0,
                    'zjfm_api_id' => 0,
                    'products' => $orphans->map(fn (Product $p) => $this->productNode($p))->values()->all(),
                ]],
            ];
        }

        return $this->okFlat('请求成功', [
            'data' => $tree,
            'total' => count($tree),
        ]);
    }

    /**
     * `GET add_product_page` — the 新增商品 dialog metadata.
     */
    public function addPage(Request $request)
    {
        return $this->ok([
            'groupdata' => DB::table('product_groups')->orderBy('order')->get(['id', 'name'])->toArray(),
            'type' => AdminMeta::PRODUCT_TYPES,
            'ptype' => DB::table('nav')
                ->whereIn('nav_type', [2, 3])
                ->orderBy('order')
                ->get()
                ->map(fn ($n) => (array) $n)
                ->all(),
            'first_groups' => DB::table('product_first_groups')->orderBy('order')->get(['id', 'name'])->toArray(),
        ]);
    }

    /**
     * `POST create_product` — 添加商品.
     *
     * Returns `{status:200, msg:"添加成功", id:N}` exactly as the original does,
     * because the SPA reads `data.id` to jump straight into the editor.
     */
    public function create(Request $request)
    {
        $name = trim((string) $request->input('productname', $request->input('name', '')));
        $type = (string) $request->input('type', 'other');
        $gid = (int) $request->input('gid', 0);
        $ptype = $request->input('ptype');

        if ($name === '') {
            return $this->validationFail('商品名称不能为空');
        }

        if (! isset(AdminMeta::PRODUCT_TYPES[$type])) {
            return $this->validationFail('商品类型错误');
        }

        $group = DB::table('product_groups')->where('id', $gid)->first();

        if ($group === null) {
            return $this->validationFail('商品组不存在');
        }

        $now = time();

        $product = Product::query()->create([
            'name' => $name,
            'type' => $type,
            'gid' => $gid,
            'groupid' => (int) ($group->gid ?? 0),
            'description' => '',
            'hidden' => 0,
            'retired' => 0,
            'pay_type' => json_encode([
                'pay_type' => 'free',
                'pay_hour_cycle' => 720,
                'pay_day_cycle' => 30,
                'pay_ontrial_status' => 0,
                'pay_ontrial_cycle' => 0,
                'pay_ontrial_num' => 1,
                'pay_ontrial_condition' => [],
                'pay_ontrial_cycle_type' => 'day',
                'pay_ontrial_num_rule' => 0,
                'clientscount_rule' => 0,
            ]),
            'pay_method' => 'prepayment',
            'auto_setup' => '',
            'api_type' => '',
            'server_group' => 0,
            'upstream_price_type' => 'percent',
            'upstream_price_value' => 0,
            'order' => (int) (Product::query()->max('order') ?? 0) + 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        // A new product starts with one zeroed pricing row per currency so the
        // 定价 tab has something to bind to.
        foreach (DB::table('currencies')->pluck('id') as $currencyId) {
            $this->ensurePricing((int) $product->id, (int) $currencyId);
        }

        if ($ptype !== null && $ptype !== '') {
            DB::table('nav')->where('id', (int) $ptype)->update(['relid' => $this->appendCsv((string) DB::table('nav')->where('id', (int) $ptype)->value('relid'), (int) $product->id)]);
        }

        $this->log('添加商品：'.$name, (int) $product->id);

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '添加成功',
            'id' => (int) $product->id,
            'data' => ['id' => (int) $product->id],
        ] + $this->envelope());
    }

    /**
     * `GET edit_product_page/<id>` — every tab of the product editor.
     */
    public function editPage(Request $request, $id)
    {
        $product = Product::query()->find((int) $id);

        if ($product === null) {
            return $this->notFound('商品不存在');
        }

        $currencies = DB::table('currencies')->orderBy('id')->get(['id', 'code', 'prefix', 'rate']);

        $pricing = $currencies->map(function ($currency) use ($product) {
            return $this->pricingRow($product, (int) $currency->id);
        })->values()->all();

        $configGroups = DB::table('product_config_groups')->orderBy('id')->get();

        $configLinks = DB::table('product_config_links')->where('pid', $product->id)->pluck('gid')->all();

        $customFields = DB::table('customfields')
            ->where('type', 'product')
            ->where('relid', $product->id)
            ->orderBy('sortorder')
            ->get()
            ->map(fn ($f) => (array) $f)
            ->all();

        $upgradeProducts = DB::table('product_upgrade_products')
            ->where('product_id', $product->id)
            ->pluck('upgrade_product_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $serverGroups = $this->serverGroupOptions($product);

        return $this->okFlat('请求成功', [
            'data' => null,
            'type' => AdminMeta::PRODUCT_TYPES,
            'product_paytype' => AdminMeta::PAY_TYPES,
            'product' => $this->productPayload($product),
            'product_group' => DB::table('product_groups')->orderBy('order')->get(['id', 'name'])->toArray(),
            'modules' => ModuleRegistry::modules(),
            'server_group' => $serverGroups,
            'currencies' => $currencies->toArray(),
            'pricing' => $pricing,
            'download_files' => $this->productDownloads((int) $product->id),
            'hierarchy_cats' => DB::table('downloadcats')->orderBy('sort')->get()->toArray(),
            'custom_brokerage' => AdminMeta::CUSTOM_BROKERAGE,
            'customfields_type' => AdminMeta::CUSTOM_FIELD_TYPES,
            'customfields' => $customFields,
            'config_groups' => $configGroups->map(fn ($g) => (array) $g)->all(),
            'config_links' => $configLinks,
            'config_options' => $this->configOptionsFor($product),
            'all_product_data' => DB::table('product_groups')->orderBy('order')->get(['id', 'name', 'headline', 'tagline', 'order'])->map(function ($g) {
                $row = (array) $g;
                $row['product'] = DB::table('products')->where('gid', $g->id)->get(['id', 'name'])->toArray();

                return $row;
            })->all(),
            'upgrade_product_ids' => $upgradeProducts,
            'pay_ontrial_condition' => AdminMeta::PAY_ON_TRIAL_CONDITION,
            'upstream_product_pricings' => $this->upstreamProductPricings($product),
            'upstream_pay_ontrial_condition' => [],
            'api_type' => AdminMeta::API_TYPES,
            'pgrouplist' => $this->navGroupList((int) $product->id),
            'ptype' => $this->productNavId((int) $product->id),
        ]);
    }

    /**
     * `POST edit_product` — save every tab at once.
     */
    public function update(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $product = Product::query()->find($id);

        if ($product === null) {
            return $this->notFound('商品不存在');
        }

        $data = [];

        // --- 详情 tab ---------------------------------------------------
        $simple = [
            'name', 'type', 'gid', 'description', 'host_show', 'host_prefix',
            'host_rule_num', 'host_rule_len_num', 'password_show',
            'password_rule_len_num', 'password_rule_upper', 'password_rule_lower',
            'password_rule_num', 'password_rule_special', 'welcome_email',
            'stock_control', 'qty', 'allow_qty', 'is_featured', 'hidden',
            'is_truename', 'retired', 'is_bind_phone', 'clientscount',
            'clientscount_rule', 'cancel_control', 'auto_terminate_email',
            'pay_method', 'api_type', 'server_group', 'upstream_pid',
            'upstream_price_type', 'upstream_price_value', 'zjmf_api_id',
            'config_options_upgrade', 'upgrade_email', 'affiliateonetime',
            'affiliate_pay_type', 'affiliate_pay_amount', 'order',
            'auto_terminate_days', 'tax', 'is_domain', 'allow_qty',
            'billing_cycle_upgrade', 'prorata_billing', 'prorata_date',
            'prorata_charge_next_month', 'auto_create_config_options',
            'upstream_auto_setup', 'upstream_ontrial_status', 'rate',
        ];

        foreach ($simple as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        // `host` holds the hostname-rule block, `password` the password rules.
        if ($request->has('host_prefix') || $request->has('host_rule_num')) {
            $data['host'] = json_encode([
                'prefix' => (string) $request->input('host_prefix', ''),
                'rule' => [
                    'num' => (int) $request->input('host_rule_num', 0),
                    'len_num' => (int) $request->input('host_rule_len_num', 0),
                ],
            ]);
        }

        if ($request->has('password_rule_len_num')) {
            $data['password'] = json_encode([
                'len_num' => (int) $request->input('password_rule_len_num', 0),
                'rule' => [
                    'upper' => (int) $request->input('password_rule_upper', 0),
                    'lower' => (int) $request->input('password_rule_lower', 0),
                    'num' => (int) $request->input('password_rule_num', 0),
                    'special' => (int) $request->input('password_rule_special', 0),
                ],
            ]);
        }

        // `show_domain_options` mirrors the 主机名 switch.
        if ($request->has('host_show')) {
            $data['show_domain_options'] = (int) $request->input('host_show');
        }

        // --- 试用 / 付款类型 tab (all stored in the `pay_type` JSON blob) --
        if ($request->has('pay_type') || $request->has('pay_ontrial_status')) {
            $existing = $product->payType();

            $payType = is_array($request->input('pay_type'))
                ? $request->input('pay_type')
                : [
                    'pay_type' => (string) ($request->input('pay_type') ?: ($existing['pay_type'] ?? 'free')),
                ];

            foreach ([
                'pay_hour_cycle', 'pay_day_cycle', 'pay_ontrial_status',
                'pay_ontrial_cycle', 'pay_ontrial_num', 'pay_ontrial_condition',
                'pay_ontrial_cycle_type', 'pay_ontrial_num_rule', 'clientscount_rule',
            ] as $key) {
                if ($request->has($key)) {
                    $payType[$key] = $request->input($key);
                }
            }

            $data['pay_type'] = json_encode(array_merge($existing, $payType));
        }

        // --- 商品推介计划 tab --------------------------------------------
        foreach ([
            'affiliate_enabled', 'affiliate_type', 'affiliate_bates',
            'affiliate_is_renew', 'affiliate_renew_type', 'affiliate_renew',
        ] as $key) {
            if ($request->has($key)) {
                $this->saveAffiliateSetting((int) $product->id, $key, $request->input($key));
            }
        }

        if ($data !== []) {
            $data['update_time'] = time();
            $product->fill($data)->save();
        }

        // --- 定价 tab ---------------------------------------------------
        $pricingRows = $request->input('pricing', $request->input('priceData'));

        if (is_array($pricingRows)) {
            $this->savePricingRows((int) $product->id, $pricingRows);
        }

        // --- 产品配置项组链接 --------------------------------------------
        if ($request->has('config_groups')) {
            $this->saveConfigLinks((int) $product->id, (array) $request->input('config_groups', []));
        }

        // --- 升级商品 ---------------------------------------------------
        if ($request->has('upgradepackages')) {
            $this->saveUpgradeProducts((int) $product->id, (array) $request->input('upgradepackages', []));
        }

        // --- 自定义字段 --------------------------------------------------
        if ($request->has('customfields')) {
            $this->saveCustomFields((int) $product->id, (array) $request->input('customfields', []));
        }

        // --- 会员中心导航分类 --------------------------------------------
        if ($request->filled('ptype')) {
            $this->syncNavRelation((int) $product->id, (int) $request->input('ptype'));
        }

        // The 链接 tab's URLs are derived, not posted.
        $product->refresh();
        $product->product_shopping_url = url('/cart?action=configureproduct&pid='.$product->id);
        $product->product_group_url = url('/cart?gid='.$product->gid.'&fid='.$this->firstGroupOf((int) $product->gid));
        $product->save();

        $this->log('编辑商品：'.$product->name, (int) $product->id);

        return $this->ok(['id' => (int) $product->id], '保存成功');
    }

    /**
     * `GET del_product {id}`
     */
    public function delete(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $product = Product::query()->find($id);

        if ($product === null) {
            return $this->notFound('商品不存在');
        }

        $activeHosts = DB::table('host')->where('productid', $id)->whereIn('domainstatus', ['Pending', 'Active', 'Suspended'])->count();

        if ($activeHosts > 0) {
            return $this->fail('该商品下还有已开通的产品，不能删除');
        }

        $name = $product->name;

        DB::transaction(function () use ($id) {
            Product::query()->whereKey($id)->delete();
            DB::table('pricing')->where('type', 'product')->where('relid', $id)->delete();
            DB::table('product_config_links')->where('pid', $id)->delete();
            DB::table('product_upgrade_products')->where('product_id', $id)->delete();
            DB::table('product_downloads')->where('product_id', $id)->delete();
            DB::table('customfields')->where('type', 'product')->where('relid', $id)->delete();
        });

        $this->log('删除商品：'.$name, $id);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET product_duplicate_page` — the 复制商品 dialog metadata.
     */
    public function duplicatePage(Request $request)
    {
        return $this->ok([
            'products' => Product::query()->orderBy('id')->get(['id', 'name', 'gid', 'type'])->toArray(),
            'groups' => DB::table('product_groups')->orderBy('order')->get(['id', 'name'])->toArray(),
        ]);
    }

    /**
     * `POST product_duplicate` — deep-copy a product and everything attached.
     */
    public function duplicate(Request $request)
    {
        $existing = (int) $request->input('existingproduct', $request->input('id', 0));
        $newName = trim((string) $request->input('newproductname', $request->input('name', '')));

        $source = Product::query()->find($existing);

        if ($source === null) {
            return $this->notFound('商品不存在');
        }

        if ($newName === '') {
            $newName = $source->name.'-副本';
        }

        $newId = DB::transaction(function () use ($source, $newName, $request) {
            $copy = $source->replicate();
            $copy->name = $newName;
            $copy->uuid = (string) Str::uuid();

            if ($request->filled('gid')) {
                $copy->gid = (int) $request->input('gid');
            }

            $copy->product_shopping_url = '';
            $copy->product_group_url = '';
            $copy->create_time = time();
            $copy->update_time = time();
            $copy->save();

            // Pricing rows carry the whole money matrix.
            foreach (DB::table('pricing')->where('type', 'product')->where('relid', $source->id)->get() as $pricing) {
                $row = (array) $pricing;
                unset($row['id']);
                $row['relid'] = $copy->id;
                DB::table('pricing')->insert($row);
            }

            foreach (DB::table('product_config_links')->where('pid', $source->id)->pluck('gid') as $gid) {
                DB::table('product_config_links')->insert(['pid' => $copy->id, 'gid' => $gid]);
            }

            foreach (DB::table('product_upgrade_products')->where('product_id', $source->id)->pluck('upgrade_product_id') as $upgradePid) {
                DB::table('product_upgrade_products')->insert([
                    'product_id' => $copy->id,
                    'upgrade_product_id' => $upgradePid,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            foreach (DB::table('product_downloads')->where('product_id', $source->id)->pluck('download_id') as $downloadId) {
                DB::table('product_downloads')->insert([
                    'product_id' => $copy->id,
                    'download_id' => $downloadId,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            foreach (DB::table('customfields')->where('type', 'product')->where('relid', $source->id)->get() as $field) {
                $row = (array) $field;
                unset($row['id']);
                $row['relid'] = $copy->id;
                $row['create_time'] = time();
                $row['update_time'] = time();
                DB::table('customfields')->insert($row);
            }

            return (int) $copy->id;
        });

        $this->log('复制商品：'.$source->name.' → '.$newName, $newId);

        return $this->ok(['id' => $newId], '复制成功');
    }

    /**
     * `POST update_productsort` — drag-sorted product list.
     */
    public function updateSort(Request $request)
    {
        $items = $request->input('data', $request->input('list', $request->input('ids', [])));

        if (! is_array($items)) {
            return $this->validationFail('参数错误');
        }

        foreach (array_values($items) as $index => $item) {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : (int) $item;
            $order = is_array($item) && isset($item['order']) ? (int) $item['order'] : $index;

            if ($id > 0) {
                DB::table('products')->where('id', $id)->update([
                    'order' => $order,
                    'update_time' => time(),
                ]);
            }
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST update_groupsort`
     */
    public function updateGroupSort(Request $request)
    {
        $items = $request->input('data', $request->input('ids', []));

        if (! is_array($items)) {
            return $this->validationFail('参数错误');
        }

        foreach (array_values($items) as $index => $item) {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : (int) $item;
            $order = is_array($item) && isset($item['order']) ? (int) $item['order'] : $index;

            if ($id > 0) {
                DB::table('product_groups')->where('id', $id)->update(['order' => $order, 'update_time' => time()]);
            }
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST update_firstgroupsort`
     */
    public function updateFirstGroupSort(Request $request)
    {
        $items = $request->input('data', $request->input('ids', []));

        if (! is_array($items)) {
            return $this->validationFail('参数错误');
        }

        foreach (array_values($items) as $index => $item) {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : (int) $item;
            $order = is_array($item) && isset($item['order']) ? (int) $item['order'] : $index;

            if ($id > 0) {
                DB::table('product_first_groups')->where('id', $id)->update(['order' => $order, 'update_time' => time()]);
            }
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST edit_stock` — inline 库存 edit in the product table.
     */
    public function editStock(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $qty = (int) $request->input('qty', 0);

        $product = Product::query()->find($id);

        if ($product === null) {
            return $this->notFound('商品不存在');
        }

        if ($qty < 0) {
            return $this->validationFail('库存不能小于0');
        }

        $product->qty = $qty;
        $product->stock_control = $request->has('stock_control')
            ? (int) $request->input('stock_control')
            : (int) $product->stock_control;
        $product->update_time = time();
        $product->save();

        return $this->ok(['qty' => $qty], '保存成功');
    }

    /**
     * `POST check_product_as` — alias uniqueness check for the group dialog.
     */
    public function checkAlias(Request $request)
    {
        $alias = trim((string) $request->input('alias', $request->input('data', '')));
        $id = (int) $request->input('id', 0);

        if ($alias === '') {
            return $this->validationFail('访问别名不能为空');
        }

        $exists = DB::table('product_groups')
            ->where('alias', $alias)
            ->when($id > 0, fn ($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            return $this->fail('该访问别名已存在');
        }

        // `shd_product_first_groups` shares the alias namespace.
        if (DB::table('product_first_groups')->where('name', $alias)->exists()) {
            return $this->fail('该访问别名已存在');
        }

        return $this->ok(['alias' => $alias], '可以使用');
    }

    /**
     * `GET getApiList` — the 接口 dropdown of the 自动开通 tab.
     */
    public function apiList(Request $request)
    {
        $servers = DB::table('servers')
            ->leftJoin('server_groups', 'server_groups.id', '=', 'servers.gid')
            ->orderBy('servers.id')
            ->get([
                'servers.id', 'servers.name', 'servers.type', 'servers.gid',
                'servers.hostname', 'servers.ip_address', 'servers.active',
                'servers.disabled', 'servers.max_accounts', 'servers.link_status',
                'server_groups.name as gname',
            ])
            ->map(fn ($s) => (array) $s)
            ->all();

        $upstreams = DB::table('zjmf_finance_api')
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'status', 'hostname'])
            ->map(function ($api) {
                $row = (array) $api;
                $row['system_type'] = $api->type === 'manual' ? 'manual' : 'zjmf_api';

                return $row;
            })
            ->all();

        return $this->ok([
            'normal' => $servers,
            'zjmf_api' => $upstreams,
            'modules' => ModuleRegistry::modules(),
        ]);
    }

    /**
     * `GET get_upstream_products {id}` — proxy the supplier catalogue.
     */
    public function upstreamProducts(Request $request)
    {
        $apiId = (int) $request->input('id', 0);
        $api = \App\Models\FinanceApi::query()->find($apiId);

        if ($api === null) {
            return $this->fail('供应商不存在');
        }

        try {
            $client = new \App\Integrations\Upstream\SupplierClient($api);
            $result = $client->products();
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '获取上游商品失败');
        }

        $defaultCurrency = DB::table('currencies')->orderBy('id')->first();
        $upstreamCurrency = $result['upstream_currency'] ?? null;

        return $this->okFlat('请求成功', [
            'data' => $result['data'] ?? $result['list'] ?? $result,
            'upstream_currency' => $upstreamCurrency,
            'currency' => $defaultCurrency,
            'rate' => $result['rate'] ?? ($upstreamCurrency->rate ?? 1),
            'product_type' => AdminMeta::PRODUCT_TYPES,
        ]);
    }

    /**
     * `GET product/get_upstream_price` — preview the downstream price of an
     * upstream product at the configured markup.
     */
    public function upstreamPrice(Request $request)
    {
        $apiId = (int) $request->input('id', $request->input('zjmf_api_id', 0));
        $upstreamPid = (int) $request->input('upstream_pid', $request->input('pid', 0));

        $api = \App\Models\FinanceApi::query()->find($apiId);

        if ($api === null) {
            return $this->fail('供应商不存在');
        }

        try {
            $detail = (new \App\Integrations\Upstream\SupplierClient($api))->productDetail($upstreamPid);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '获取上游价格失败');
        }

        $value = (float) ($request->input('upstream_price_value') ?: 100);
        $factor = $value / 100;

        $pricing = [];

        foreach (($detail['pricing'] ?? []) as $row) {
            $entry = is_array($row) ? $row : (array) $row;

            foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
                if (isset($entry[$cycle]) && (float) $entry[$cycle] >= 0) {
                    $entry[$cycle] = PricingService::money((float) $entry[$cycle] * $factor);
                }
            }

            $pricing[] = $entry;
        }

        return $this->ok([
            'pricing' => $pricing,
            'upstream_price_value' => $value,
            'raw' => $detail,
        ], '请求成功');
    }

    /**
     * `POST product/sync_product_info` — re-pull name/pricing from the supplier.
     */
    public function syncProductInfo(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_map('intval', $ids) : [(int) $ids];

        $results = [];

        foreach (Product::query()->whereIn('id', $ids ?: [-1])->get() as $product) {
            if (! $product->zjmf_api_id || ! $product->upstream_pid) {
                $results[] = ['id' => (int) $product->id, 'name' => $product->name, 'type' => $product->type, 'msg' => '该商品未对接上游'];

                continue;
            }

            $api = \App\Models\FinanceApi::query()->find($product->zjmf_api_id);

            if ($api === null) {
                $results[] = ['id' => (int) $product->id, 'name' => $product->name, 'type' => $product->type, 'msg' => '供应商不存在'];

                continue;
            }

            try {
                $detail = (new \App\Integrations\Upstream\SupplierClient($api))->productDetail((int) $product->upstream_pid);
            } catch (\Throwable $e) {
                $results[] = ['id' => (int) $product->id, 'name' => $product->name, 'type' => $product->type, 'msg' => $e->getMessage() ?: '同步失败'];

                continue;
            }

            $product->upstream_price = (float) ($detail['price'] ?? $product->upstream_price);
            $product->upstream_qty = (int) ($detail['qty'] ?? $product->upstream_qty);
            $product->update_time = time();
            $product->save();

            $results[] = ['id' => (int) $product->id, 'name' => $product->name, 'type' => $product->type, 'msg' => '同步成功'];
        }

        return $this->ok($results, '同步完成');
    }

    /**
     * `GET product/select_type` — product types for the pickers.
     */
    public function selectType(Request $request)
    {
        return $this->ok(AdminMeta::PRODUCT_TYPES);
    }

    /**
     * `GET product/add_productgrouppage` — group → product tree for the
     * group-management dialogs.
     */
    public function productGroupTree(Request $request)
    {
        $groups = DB::table('product_groups')->orderBy('order')->get();

        $tree = $groups->map(function ($group) {
            return [
                'id' => (int) $group->id,
                'pid' => (int) $group->gid,
                'name' => $group->name,
                'children' => DB::table('products')->where('gid', $group->id)->get(['id', 'name'])->toArray(),
            ];
        })->all();

        return $this->respond([
            'status' => ApiResponse::OK,
            'data' => ['group' => $tree],
            'msg' => '请求成功',
        ] + $this->envelope());
    }

    /**
     * `GET provision/list` — module list for the 自动开通 tab.
     */
    public function provisionList(Request $request)
    {
        return $this->ok(ModuleRegistry::modules());
    }

    /**
     * `GET provision/<id>` — the module config fields for a product's server.
     */
    public function provisionMetadata(Request $request, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));
        $product = Product::query()->find($id);

        $module = (string) ($product->server_type ?? '');
        $server = $product && $product->server_group
            ? DB::table('servers')->where('id', $product->server_group)->first()
            : null;

        $config = [];

        if ($server !== null && $server->type) {
            $module = (string) $server->type;
            $decoded = json_decode((string) $server->username, true);
            $stored = is_array($decoded) ? $decoded : [];
            $config = json_decode((string) $server->password, true);
            $config = is_array($config) ? $config : [];
        }

        $fields = ModuleRegistry::configFields($module);

        foreach ($fields as $index => $field) {
            $fields[$index]['value'] = $config[$field['name']] ?? ($stored[$field['name']] ?? $field['default']);
        }

        return $this->ok([
            'module' => $module,
            'config' => $fields,
            'module_name' => ModuleRegistry::label($module),
        ]);
    }

    /**
     * `GET product/zklist_page` — 折扣设置 tab of the client-group page.
     */
    public function discountListPage(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $rows = DB::table('user_product_groups')
            ->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return $this->paginated($rows, DB::table('user_product_groups')->count(), $page, $limit);
    }

    // -----------------------------------------------------------------
    // 商品组
    // -----------------------------------------------------------------

    /**
     * `GET edit_product_group_page {id}` — 商品组 dialog.
     */
    public function editGroupPage(Request $request)
    {
        $id = (int) $request->input('id', 0);

        $data = [
            // `shd_payment_gateways` is a key/value table: `value` is the
            // display title and `setting` the config blob.
            'activeGatewayArr' => DB::table('payment_gateways')
                ->pluck('value', 'gateway')
                ->all(),
            'firstGroups' => DB::table('product_first_groups')->orderBy('order')->get()->toArray(),
            'default_page' => 'default',
            'cart_themes' => AdminMeta::CART_THEMES,
            'group' => null,
            'customfields' => [],
        ];

        if ($id > 0) {
            $group = DB::table('product_groups')->where('id', $id)->first();

            if ($group === null) {
                return $this->notFound('商品组不存在');
            }

            $data['group'] = (array) $group;
            $data['customfields'] = DB::table('product_groups_customfields')
                ->where('relid', $id)
                ->get()
                ->map(fn ($f) => (array) $f)
                ->all();
        }

        return $this->respond([
            'data' => $data,
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
        ] + $this->envelope());
    }

    /**
     * `GET edit_product_first_group_page {id}` — 一级分组 dialog.
     */
    public function editFirstGroupPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $group = $id > 0 ? DB::table('product_first_groups')->where('id', $id)->first() : null;

        return $this->okFlat('请求成功', [
            'data' => $group ? (array) $group : null,
            'firstGroups' => DB::table('product_first_groups')->orderBy('order')->get()->toArray(),
        ]);
    }

    /**
     * `POST save_product_group` — create/update a 商品组 or 一级分组.
     */
    public function saveGroup(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('商品组名称不能为空');
        }

        $data = [
            'name' => $name,
            'headline' => (string) $request->input('headline', ''),
            'tagline' => (string) $request->input('tagline', ''),
            'alias' => (string) $request->input('alias', ''),
            'tpl_type' => (string) $request->input('tpl_type', 'default'),
            'order_frm_tpl' => (string) $request->input('order_frm_tpl', ''),
            'hidden' => (int) $request->input('hidden', 0),
            'gid' => (int) $request->input('gid', 0),
            'type' => (int) $request->input('type', 1),
            'disabled_gateways' => is_array($request->input('disabled_gateways'))
                ? implode(',', $request->input('disabled_gateways'))
                : (string) $request->input('disabled_gateways', ''),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('product_groups')->where('id', $id)->update($data);
            $this->saveGroupCustomFields($id, $request);
            $this->log('编辑商品组：'.$name, $id);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $newId = (int) DB::table('product_groups')->insertGetId($data);

        $this->saveGroupCustomFields($newId, $request);
        $this->log('添加商品组：'.$name, $newId);

        return $this->ok(['id' => $newId], '添加成功');
    }

    /**
     * `POST save_product_first_group`
     */
    public function saveFirstGroup(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('分组名称不能为空');
        }

        $data = [
            'name' => $name,
            'hidden' => (int) $request->input('hidden', 0),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('product_first_groups')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['order'] = (int) $request->input('order', 0);
        $data['create_time'] = time();
        $newId = (int) DB::table('product_first_groups')->insertGetId($data);

        return $this->ok(['id' => $newId], '添加成功');
    }

    /**
     * `GET del_product_group {id}`
     */
    public function deleteGroup(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if (DB::table('products')->where('gid', $id)->exists()) {
            return $this->fail('该分组下还有商品，不能删除');
        }

        DB::table('product_groups')->where('id', $id)->delete();
        DB::table('product_groups_customfields')->where('relid', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET del_product_first_group {id}`
     */
    public function deleteFirstGroup(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if (DB::table('product_groups')->where('gid', $id)->exists()) {
            return $this->fail('该分组下还有商品组，不能删除');
        }

        DB::table('product_first_groups')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 文件下载
    // -----------------------------------------------------------------

    /**
     * `GET product_selectcates {productid}` — categories + the files already
     * attached to the product.
     */
    public function selectCates(Request $request)
    {
        $productId = (int) $request->input('productid', 0);

        return $this->ok([
            'cats' => DB::table('downloadcats')->orderBy('sort')->get()->toArray(),
            'files' => $this->productDownloads($productId),
            'all_files' => DB::table('downloads')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'category', 'title', 'location', 'locationname'])
                ->toArray(),
        ]);
    }

    /**
     * `GET product_downloadcates`
     */
    public function downloadCates(Request $request)
    {
        $cats = DB::table('downloadcats')->orderBy('sort')->get()->map(function ($cat) {
            $row = (array) $cat;
            $row['count'] = DB::table('downloads')->where('category', $cat->id)->count();

            return $row;
        })->all();

        return $this->ok([
            'cat' => $cats,
            'cats' => $cats,
            'file' => DB::table('downloads')->orderByDesc('id')->get()->toArray(),
        ]);
    }

    /**
     * `POST product_downloadcats` — add/edit/delete a download category.
     */
    public function downloadCats(Request $request)
    {
        $action = (string) $request->input('action', 'add');
        $id = (int) $request->input('id', 0);

        if ($action === 'delete') {
            if (DB::table('downloads')->where('category', $id)->exists()) {
                return $this->fail('该分类下还有文件，不能删除');
            }

            DB::table('downloadcats')->where('id', $id)->delete();

            return $this->ok(null, '删除成功');
        }

        $title = trim((string) $request->input('title', $request->input('name', '')));

        if ($title === '') {
            return $this->validationFail('分类名称不能为空');
        }

        $data = [
            'name' => $title,
            'description' => (string) $request->input('description', ''),
            'parentid' => (int) $request->input('parentid', 0),
            'hidden' => (int) $request->input('hidden', 0),
            'sort' => (int) $request->input('sort', 0),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('downloadcats')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $newId = (int) DB::table('downloadcats')->insertGetId($data);

        return $this->ok(['id' => $newId], '添加成功');
    }

    /**
     * `POST product_manage_downloads` — attach / create / detach a file.
     */
    public function manageDownloads(Request $request)
    {
        $productId = (int) $request->input('product_id', $request->input('productid', 0));
        $action = (string) $request->input('action', 'add');

        if ($productId <= 0) {
            return $this->validationFail('商品ID不能为空');
        }

        if ($action === 'delete' || $action === 'delFile') {
            $downloadId = (int) $request->input('id', $request->input('download_id', 0));

            DB::table('product_downloads')->where('product_id', $productId)->where('download_id', $downloadId)->delete();

            return $this->ok(null, '删除成功');
        }

        $downloadId = (int) $request->input('download_id', $request->input('id', 0));

        if ($downloadId <= 0) {
            // A brand-new file row posted straight from the dialog.
            $title = trim((string) $request->input('title', ''));

            if ($title === '') {
                return $this->validationFail('文件名称不能为空');
            }

            $downloadId = (int) DB::table('downloads')->insertGetId([
                'category' => (int) $request->input('catid', 0),
                'type' => (string) $request->input('type', ''),
                'title' => $title,
                'description' => (string) $request->input('description', ''),
                'location' => (string) $request->input('location', ''),
                'locationname' => (string) $request->input('locationname', ''),
                'filetype' => (string) $request->input('filetype', ''),
                'url' => (string) $request->input('url', ''),
                'clientsonly' => (int) $request->input('clientsonly', 1),
                'hidden' => (int) $request->input('hidden', 0),
                'productdownload' => 1,
                'downloads' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $exists = DB::table('product_downloads')
            ->where('product_id', $productId)
            ->where('download_id', $downloadId)
            ->exists();

        if (! $exists) {
            DB::table('product_downloads')->insert([
                'product_id' => $productId,
                'download_id' => $downloadId,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        return $this->ok(['id' => $downloadId], '保存成功');
    }

    /**
     * `POST product_del_custom` — remove a product custom field row.
     */
    public function deleteCustomField(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->validationFail('ID错误');
        }

        DB::table('customfields')->where('id', $id)->where('type', 'product')->delete();
        DB::table('customfieldsvalues')->where('fieldid', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * One product row inside the tree.
     */
    private function productNode(Product $product): array
    {
        $active = DB::table('host')->where('productid', $product->id)->whereIn('domainstatus', Host::LIVE_STATUSES)->count();

        return [
            'id' => (int) $product->id,
            'name' => $product->name,
            'gid' => (int) $product->gid,
            'type' => $product->type,
            'type_zh' => AdminMeta::productTypeLabel($product->type),
            'pay_type' => AdminMeta::productPayLabel($product),
            'qty' => (int) $product->qty,
            'stock_control' => (int) $product->stock_control,
            'auto_setup' => (string) $product->auto_setup,
            'hidden' => (int) $product->hidden,
            'retired' => (int) $product->retired,
            'api_name' => $this->apiName($product),
            'zjmf_api_id' => (int) $product->zjmf_api_id,
            'count' => $active,
            'count_active' => $active,
            'guid' => (string) $product->uuid,
            'order' => (int) $product->order,
        ];
    }

    private function groupNode($group, $products): array
    {
        $row = (array) $group;
        $row['id'] = (int) $group->id;
        $row['products'] = $products->map(fn (Product $p) => $this->productNode($p))->values()->all();

        return $row;
    }

    private function apiName(Product $product): ?string
    {
        if ($product->api_type === 'zjmf_api' || $product->zjmf_api_id) {
            return (string) (DB::table('zjmf_finance_api')->where('id', $product->zjmf_api_id)->value('name') ?? '');
        }

        if ($product->server_group) {
            return (string) (DB::table('servers')->where('id', $product->server_group)->value('name') ?? '');
        }

        return null;
    }

    /**
     * The 编辑商品 payload — the shape the SPA binds its form to.
     */
    private function productPayload(Product $product): array
    {
        $host = json_decode((string) $product->host, true);
        $host = is_array($host) ? $host : [];
        $password = json_decode((string) $product->password, true);
        $password = is_array($password) ? $password : [];

        $payType = $product->payType();

        return array_merge($product->toArray(), [
            'pay_type' => array_merge([
                'pay_type' => 'free',
                'pay_hour_cycle' => 720,
                'pay_day_cycle' => 30,
                'pay_ontrial_status' => 0,
                'pay_ontrial_cycle' => 0,
                'pay_ontrial_num' => 1,
                'pay_ontrial_condition' => [],
                'pay_ontrial_cycle_type' => 'day',
                'pay_ontrial_num_rule' => 0,
                'clientscount_rule' => 0,
            ], $payType),
            'host_prefix' => $host['prefix'] ?? '',
            'host_rule_num' => $host['rule']['num'] ?? 0,
            'host_rule_len_num' => $host['rule']['len_num'] ?? 0,
            'host_show' => (int) $product->show_domain_options,
            'password_show' => 1,
            'password_rule_len_num' => $password['len_num'] ?? 0,
            'password_rule_upper' => $password['rule']['upper'] ?? 0,
            'password_rule_lower' => $password['rule']['lower'] ?? 0,
            'password_rule_num' => $password['rule']['num'] ?? 0,
            'password_rule_special' => $password['rule']['special'] ?? 0,
            'password' => ['rule' => $password['rule'] ?? ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 0]],
            'pay_method' => $product->pay_method ?: 'prepayment',
            'api_type' => (string) $product->api_type,
            'upstream_price_type' => $product->upstream_price_type ?: 'percent',
            'zjmf_api_id' => (int) $product->zjmf_api_id,
            'upstream_pid' => (int) $product->upstream_pid,
            'server_group' => (int) $product->server_group,
            'upper_reaches_id' => (int) $product->zjmf_api_id,
            'is_resource' => $product->zjmf_api_id ? 1 : 0,
            'first_gid' => $this->firstGroupOf((int) $product->gid),
            'product_shopping_url' => url('/cart?action=configureproduct&pid='.$product->id),
            'product_group_url' => url('/cart?gid='.$product->gid.'&fid='.$this->firstGroupOf((int) $product->gid)),
        ]);
    }

    /**
     * One pricing row, filled out to the full 17-cycle matrix.
     */
    private function pricingRow(Product $product, int $currencyId): array
    {
        $row = $this->ensurePricing((int) $product->id, $currencyId);

        foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
            $row[$cycle] = isset($row[$cycle]) ? (float) $row[$cycle] : -1.0;
        }

        foreach (AdminMeta::SETUP_COLUMNS as $column) {
            $row[$column] = isset($row[$column]) ? (float) $row[$column] : 0.0;
        }

        return $row;
    }

    /**
     * Fetch or create the `shd_pricing` row for a product/currency pair.
     */
    private function ensurePricing(int $productId, int $currencyId): array
    {
        $row = DB::table('pricing')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where('currency', $currencyId)
            ->first();

        if ($row !== null) {
            return (array) $row;
        }

        $data = [
            'type' => 'product',
            'relid' => $productId,
            'currency' => $currencyId,
        ];

        foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
            $data[$cycle] = -1;
        }

        foreach (AdminMeta::SETUP_COLUMNS as $column) {
            $data[$column] = 0;
        }

        $data['id'] = DB::table('pricing')->insertGetId($data);

        return $data;
    }

    /**
     * Persist the 定价 tab. A cycle value of -1 means "not offered".
     */
    private function savePricingRows(int $productId, array $rows): void
    {
        $now = time();

        foreach ($rows as $currencyId => $row) {
            if (! is_array($row)) {
                continue;
            }

            // The SPA posts either `{1: {monthly: 10, ...}}` or a flat list
            // whose entries carry their own `currency`.
            $currency = (int) ($row['currency'] ?? (is_numeric($currencyId) ? $currencyId : 0));

            if ($currency <= 0) {
                continue;
            }

            $this->ensurePricing($productId, $currency);

            $update = [];

            foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
                if (array_key_exists($cycle, $row) && $row[$cycle] !== null && $row[$cycle] !== '') {
                    $update[$cycle] = $this->money((float) $row[$cycle]);
                }
            }

            foreach (AdminMeta::SETUP_COLUMNS as $cycle => $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
                    $update[$column] = $this->money((float) $row[$column]);
                }
            }

            if ($update !== []) {
                DB::table('pricing')
                    ->where('type', 'product')
                    ->where('relid', $productId)
                    ->where('currency', $currency)
                    ->update($update);
            }
        }

        unset($now);
    }

    /**
     * Store the 商品推介计划 tab. The per-product affiliate settings live in
     * `shd_affiliates_product_setting`, one row per product.
     */
    private function saveAffiliateSetting(int $productId, string $key, mixed $value): void
    {
        $column = match ($key) {
            'affiliate_enabled' => 'affiliate_enabled',
            'affiliate_type' => 'affiliate_type',
            'affiliate_bates' => 'affiliate_bates',
            'affiliate_is_renew' => 'affiliate_is_renew',
            'affiliate_renew_type' => 'affiliate_renew_type',
            'affiliate_renew' => 'affiliate_renew',
            default => null,
        };

        if ($column === null) {
            return;
        }

        $exists = DB::table('affiliates_product_setting')->where('pid', $productId)->exists();

        if ($exists) {
            DB::table('affiliates_product_setting')->where('pid', $productId)->update([$column => $value]);
        } else {
            DB::table('affiliates_product_setting')->insert([
                'pid' => $productId,
                'create_time' => time(),
                $column => $value,
            ]);
        }
    }

    /**
     * The 产品配置 tab — link a product to global config-option groups.
     */
    private function saveConfigLinks(int $productId, array $groupIds): void
    {
        DB::table('product_config_links')->where('pid', $productId)->delete();

        foreach (array_unique(array_filter(array_map('intval', $groupIds))) as $gid) {
            DB::table('product_config_links')->insert(['pid' => $productId, 'gid' => $gid]);
        }
    }

    /**
     * The 升级选项 tab — which products this one may be upgraded to.
     */
    private function saveUpgradeProducts(int $productId, array $upgradeIds): void
    {
        DB::table('product_upgrade_products')->where('product_id', $productId)->delete();

        foreach (array_unique(array_filter(array_map('intval', $upgradeIds))) as $upgradePid) {
            if ($upgradePid !== $productId) {
                DB::table('product_upgrade_products')->insert([
                    'product_id' => $productId,
                    'upgrade_product_id' => $upgradePid,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
        }
    }

    /**
     * The 自定义字段 tab.
     */
    private function saveCustomFields(int $productId, array $fields): void
    {
        foreach ($fields as $key => $field) {
            if (! is_array($field)) {
                continue;
            }

            $data = [
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => (string) ($field['fieldname'] ?? ''),
                'fieldtype' => (string) ($field['fieldtype'] ?? 'text'),
                'description' => (string) ($field['description'] ?? ''),
                'fieldoptions' => (string) ($field['fieldoptions'] ?? ''),
                'regexpr' => (string) ($field['regexpr'] ?? ''),
                'sortorder' => (int) ($field['sortorder'] ?? 0),
                'update_time' => time(),
            ];

            $id = (int) ($field['id'] ?? (is_numeric($key) ? $key : 0));

            if ($data['fieldname'] === '') {
                continue;
            }

            if ($id > 0 && DB::table('customfields')->where('id', $id)->exists()) {
                DB::table('customfields')->where('id', $id)->update($data);
            } else {
                $data['create_time'] = time();
                DB::table('customfields')->insert($data);
            }
        }
    }

    /**
     * The 商品组 dialog's repeatable `{name, value}` custom-field rows.
     */
    private function saveGroupCustomFields(int $groupId, Request $request): void
    {
        $rows = $request->input('customfields');

        if (! is_array($rows)) {
            return;
        }

        DB::table('product_groups_customfields')->where('relid', $groupId)->delete();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $value = (string) ($row['value'] ?? '');

            if ($name === '') {
                continue;
            }

            DB::table('product_groups_customfields')->insert([
                'relid' => $groupId,
                'name' => $name,
                'value' => $value,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    /**
     * 会员中心导航分类 — `shd_nav.relid` holds the linked product ids.
     */
    private function syncNavRelation(int $productId, int $navId): void
    {
        // Detach from every other nav entry first.
        foreach (DB::table('nav')->get(['id', 'relid']) as $nav) {
            $ids = $this->csvToArray($nav->relid);

            if (! in_array($productId, $ids, true)) {
                continue;
            }

            DB::table('nav')->where('id', $nav->id)->update([
                'relid' => implode(',', array_diff($ids, [$productId])),
            ]);
        }

        if ($navId <= 0) {
            return;
        }

        $current = $this->csvToArray(DB::table('nav')->where('id', $navId)->value('relid'));

        if (! in_array($productId, $current, true)) {
            $current[] = $productId;
        }

        DB::table('nav')->where('id', $navId)->update(['relid' => implode(',', $current)]);
    }

    private function productNavId(int $productId): int
    {
        foreach (DB::table('nav')->get(['id', 'relid']) as $nav) {
            if (in_array($productId, $this->csvToArray($nav->relid), true)) {
                return (int) $nav->id;
            }
        }

        return 0;
    }

    private function navGroupList(int $productId): array
    {
        $active = $this->productNavId($productId);

        return DB::table('nav')
            ->whereIn('nav_type', [2, 3])
            ->orderBy('order')
            ->get()
            ->map(function ($nav) use ($active) {
                $row = (array) $nav;
                $row['is_active'] = (int) $nav->id === $active ? 1 : 0;
                $row['templatePage'] = 'service';
                $row['orderFuc'] = 1;
                $row['is_custom'] = 0;
                $row['orderFucUrl'] = url('/cart');

                return $row;
            })
            ->all();
    }

    /**
     * The 自动开通 tab's supplier dropdown: `zjmf_api` and `normal` buckets.
     */
    private function serverGroupOptions(Product $product): array
    {
        $upstream = DB::table('zjmf_finance_api')
            ->orderBy('id')
            ->get(['id', 'name', 'type'])
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'system_type' => 'zjmf_api',
            ])
            ->all();

        $normal = DB::table('server_groups')
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'system_type'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'system_type' => $g->system_type ?: 'normal',
            ])
            ->all();

        array_unshift($normal, ['id' => 0, 'name' => '无', 'type' => '', 'system_type' => 'normal']);

        return ['zjmf_api' => $upstream, 'normal' => $normal];
    }

    /**
     * 上下游 price preview rows attached to the 定价 tab.
     */
    private function upstreamProductPricings(Product $product): array
    {
        if (! $product->zjmf_api_id || ! $product->upstream_pid) {
            return [];
        }

        $api = \App\Models\FinanceApi::query()->find($product->zjmf_api_id);

        if ($api === null) {
            return [];
        }

        try {
            $detail = (new \App\Integrations\Upstream\SupplierClient($api))->productDetail((int) $product->upstream_pid);
        } catch (\Throwable) {
            return [];
        }

        return $detail['pricing'] ?? [];
    }

    private function productDownloads(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        return DB::table('downloads')
            ->join('product_downloads', 'product_downloads.download_id', '=', 'downloads.id')
            ->where('product_downloads.product_id', $productId)
            ->get([
                'downloads.id', 'downloads.category', 'downloads.title',
                'downloads.description', 'downloads.location', 'downloads.filetype',
                'downloads.hidden', 'downloads.clientsonly',
            ])
            ->map(fn ($d) => (array) $d)
            ->all();
    }

    /**
     * Every config option reachable from the product's linked groups, plus the
     * global ones — this feeds the 产品配置/升级选项 table.
     */
    private function configOptionsFor(Product $product): array
    {
        $groupIds = DB::table('product_config_links')->where('pid', $product->id)->pluck('gid')->all();

        $globalIds = DB::table('product_config_groups')->where('global', 1)->pluck('id')->all();

        $ids = array_unique(array_merge($groupIds, $globalIds));

        if ($ids === []) {
            return [];
        }

        return DB::table('product_config_options')
            ->whereIn('gid', $ids)
            ->orderBy('order')
            ->get()
            ->map(function ($option) {
                $row = (array) $option;
                $row['sub'] = DB::table('product_config_options_sub')
                    ->where('config_id', $option->id)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn ($s) => (array) $s)
                    ->all();

                return $row;
            })
            ->all();
    }

    private function firstGroupOf(int $groupId): int
    {
        return (int) (DB::table('product_groups')->where('id', $groupId)->value('gid') ?? 0);
    }

    private function appendCsv(string $csv, int $value): string
    {
        $ids = $this->csvToArray($csv);

        if (! in_array($value, $ids, true)) {
            $ids[] = $value;
        }

        return implode(',', $ids);
    }
}
