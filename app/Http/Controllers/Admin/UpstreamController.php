<?php

namespace App\Http\Controllers\Admin;

use App\Integrations\Upstream\SupplierClient;
use App\Models\Client;
use App\Models\FinanceApi;
use App\Models\Host;
use App\Models\Order;
use App\Models\Product;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use App\Support\PasswordHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 上下游 (upstream / downstream) — supplier management, upstream catalogue
 * import, upstream hosts/orders, the task queue and the downstream API users.
 *
 * The same surface serves three roles, mirroring the original's single
 * `admin/zjmfFinanceApi` controller:
 *
 *   • supplier management  — I buy from someone else (`zjmf_finance_api`)
 *   • downstream management — someone else buys from me (`api`, `task_queue`)
 *   • resource pool        — the manual/DCIM proxy views (`upper/*`)
 *
 * Every call that reaches an upstream installation goes through
 * `App\Integrations\Upstream\SupplierClient`; failures are reported as the
 * platform envelope rather than escaping as exceptions.
 */
class UpstreamController extends AdminController
{
    /**
     * `GET zjmf_finance_api` — the 供应商管理 list.
     */
    public function index(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, ['id', 'name', 'create_time', 'status'], 'id');

        $query = FinanceApi::query();

        if ($keywords = trim((string) $request->input('keywords', $request->input('name', '')))) {
            $query->where(function ($q) use ($keywords) {
                $q->where('name', 'like', "%{$keywords}%")
                    ->orWhere('hostname', 'like', "%{$keywords}%")
                    ->orWhere('username', 'like', "%{$keywords}%");
            });
        }

        if (($type = $request->input('type')) !== null && $type !== '' && $type !== 'ALL') {
            $query->where('type', $type);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy($orderBy, $sort)->forPage($page, $limit)->get();

        $list = $rows->map(fn (FinanceApi $api) => $this->apiRow($api))->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
            'type' => ['manual' => '手动', 'zjmf_api' => '智简魔方', 'v10' => 'v10'],
        ]);
    }

    /**
     * `GET zjmf_finance_api/<id>` — one supplier, for the edit dialog.
     */
    public function detail(Request $request, $id)
    {
        $api = FinanceApi::query()->find((int) $id);

        if ($api === null) {
            return $this->notFound('供应商不存在');
        }

        return $this->ok($this->apiRow($api, true));
    }

    /**
     * `POST zjmf_finance_api` — add a supplier.
     */
    public function create(Request $request)
    {
        return $this->writeApi($request, 0);
    }

    /**
     * `PUT zjmf_finance_api` / `PUT zjmf_finance_api/<id>` — edit a supplier.
     */
    public function update(Request $request, $id = null)
    {
        return $this->writeApi($request, (int) ($id ?? $request->input('id', 0)));
    }

    /**
     * `DELETE zjmf_finance_api/<id>`
     */
    public function delete(Request $request, $id)
    {
        $id = (int) $id;
        $api = FinanceApi::query()->find($id);

        if ($api === null) {
            return $this->notFound('供应商不存在');
        }

        if (Product::query()->where('zjmf_api_id', $id)->exists()) {
            return $this->fail('该供应商下还有商品，不能删除');
        }

        $name = $api->name;
        FinanceApi::query()->whereKey($id)->delete();

        $this->log('删除供应商：'.$name, $id);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET zjmf_finance_api/<id>/status` — refresh the connection state.
     */
    public function refreshStatus(Request $request, $id)
    {
        $api = FinanceApi::query()->find((int) $id);

        if ($api === null) {
            return $this->notFound('供应商不存在');
        }

        if ($api->type === 'manual') {
            // A manual supplier has no endpoint to dial.
            DB::table('zjmf_finance_api')->where('id', $api->id)->update(['status' => 1]);

            return $this->ok(['status' => 1, 'desc' => '手动供应商'], '链接成功');
        }

        try {
            $result = (new SupplierClient($api))->testConnection();
        } catch (\Throwable $e) {
            DB::table('zjmf_finance_api')->where('id', $api->id)->update(['status' => 0]);

            return $this->ok([
                'status' => 0,
                'desc' => '链接失败',
                'error' => $e->getMessage(),
            ], '链接失败');
        }

        $ok = (int) ($result['status'] ?? 0) === ApiResponse::OK;
        $productNum = (int) ($result['product_num'] ?? $api->product_num);

        DB::table('zjmf_finance_api')->where('id', $api->id)->update([
            'status' => $ok ? 1 : 0,
            'product_num' => $productNum,
        ]);

        return $this->ok([
            'status' => $ok ? 1 : 0,
            'desc' => $ok ? '链接成功' : '链接失败',
            'product_num' => $productNum,
            'balance' => $result['balance'] ?? null,
        ], $ok ? '链接成功' : '链接失败');
    }

    /**
     * `GET zjmf_finance_api/summary` — the supplier summary cards.
     */
    public function summary(Request $request)
    {
        $apis = FinanceApi::query()->get();

        $totalHosts = Host::query()->whereIn('serverid', DB::table('servers')->pluck('id')->all() ?: [-1])->count();

        return $this->ok([
            'api_count' => $apis->count(),
            'active_api_count' => $apis->where('status', 1)->count(),
            'product_count' => Product::query()->where('zjmf_api_id', '>', 0)->count(),
            'host_count' => $totalHosts,
            'upstream_host_count' => Host::query()
                ->whereIn('productid', Product::query()->where('zjmf_api_id', '>', 0)->pluck('id')->all() ?: [-1])
                ->count(),
            'list' => $apis->map(fn (FinanceApi $api) => $this->apiRow($api))->all(),
        ], '请求成功');
    }

    /**
     * `GET zjmf_finance_api/downstream_summary` — 上下游概览.
     *
     * `id` targets one supplier; without it the whole downstream estate is
     * summarised (the `/task-queue` and `/configure-edit` dashboards).
     */
    public function downstreamSummary(Request $request)
    {
        $apiId = (int) $request->input('id', 0);

        $orders = Order::query()->whereNull('delete_time');
        $hosts = Host::query();

        if ($apiId > 0) {
            $pids = Product::query()->where('zjmf_api_id', $apiId)->pluck('id')->all();
            $orders->whereIn('uid', $this->downstreamUids($apiId));
            $hosts->whereIn('productid', $pids ?: [-1]);
        }

        $queuePending = DB::table('run_maping')->where('status', 0)->count();

        return $this->ok([
            'api_id' => $apiId,
            'order_count' => (clone $orders)->count(),
            'order_amount' => $this->money((float) (clone $orders)->sum('amount')),
            'host_count' => (clone $hosts)->count(),
            'host_active' => (clone $hosts)->where('domainstatus', 'Active')->count(),
            'host_pending' => (clone $hosts)->where('domainstatus', 'Pending')->count(),
            'host_suspended' => (clone $hosts)->where('domainstatus', 'Suspended')->count(),
            'product_count' => $apiId > 0
                ? Product::query()->where('zjmf_api_id', $apiId)->count()
                : Product::query()->where('zjmf_api_id', '>', 0)->count(),
            'task_queue_pending' => $queuePending,
            'pushhost_count' => DB::table('zjmf_pushhost')->count(),
            'pushhost_failed' => DB::table('zjmf_pushhost')->where('status', '0')->count(),
            'recent_orders' => (clone $orders)->orderByDesc('id')->limit(10)->get(['id', 'uid', 'amount', 'status', 'create_time'])
                ->map(fn (Order $o) => [
                    'id' => (int) $o->id,
                    'username' => (string) (Client::query()->whereKey($o->uid)->value('username') ?? ''),
                    'amount' => $this->money((float) $o->amount),
                    'status' => (string) $o->status,
                    'status_zh' => AdminMeta::orderStatusLabel((string) $o->status),
                    'create_time' => (int) $o->create_time,
                ])
                ->all(),
        ], '请求成功');
    }

    /**
     * `GET zjmf_finance_api/products` — the imported upstream catalogue.
     *
     * Each upstream product is shown once per supplier, with its stock and the
     * margin between the upstream price and the local selling price.
     */
    public function products(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = Product::query()->where('zjmf_api_id', '>', 0);

        if ($apiId = $request->input('id', $request->input('zjmf_finance_api_id'))) {
            $query->where('zjmf_api_id', (int) $apiId);
        }

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where('name', 'like', "%{$name}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('order')->forPage($page, $limit)->get();

        $apiNames = DB::table('zjmf_finance_api')->pluck('name', 'id');

        $list = $rows->map(function (Product $product) use ($apiNames) {
            $active = DB::table('host')->where('productid', $product->id)->whereIn('domainstatus', Host::LIVE_STATUSES)->count();
            $sellPrice = (float) (DB::table('pricing')->where('type', 'product')->where('relid', $product->id)->value('monthly') ?? 0);

            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'billingcycle_zh' => AdminMeta::productPayLabel($product),
                'list' => [[
                    'id' => (int) $product->zjmf_api_id,
                    'supplier' => (string) ($apiNames[$product->zjmf_api_id] ?? ''),
                    'stock' => (int) $product->upstream_qty,
                    'count' => $active,
                    'amount' => $this->money($sellPrice),
                    'upstream_price' => $this->money((float) ($product->upstream_price ?? 0)),
                    'profit' => $this->money($sellPrice - (float) ($product->upstream_price ?? 0)),
                ]],
            ];
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `GET zjmf_finance_api/order` — upstream orders (the supplier order tab).
     */
    public function orders(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $apiId = (int) $request->input('zjmf_finance_api_id', $request->input('id', 0));

        $query = Order::query()->whereNull('delete_time');

        if ($apiId > 0) {
            $query->whereIn('uid', $this->downstreamUids($apiId));
        }

        $total = (clone $query)->count();
        $priceTotal = (float) (clone $query)->sum('amount');

        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $clients = Client::query()->whereIn('id', $rows->pluck('uid')->all())->get()->keyBy('id');

        $list = $rows->map(fn (Order $o) => [
            'id' => (int) $o->id,
            'uid' => (int) $o->uid,
            'username' => $clients[$o->uid]->username ?? '',
            'amount' => $this->money((float) $o->amount),
            'create_time' => (int) $o->create_time,
            'pay_time' => (int) $o->pay_time,
            'payment' => $o->payment,
            'status' => (string) $o->status,
            'status_zh' => AdminMeta::orderStatusLabel((string) $o->status),
        ])->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'count' => $total,
            'price_total' => $this->money($priceTotal),
            'price_total_page' => $this->money((float) $rows->sum('amount')),
        ]);
    }

    /**
     * `POST zjmf_finance_api/order_commission` — commission on downstream orders.
     */
    public function orderCommission(Request $request)
    {
        return app(OrderController::class)->commission($request);
    }

    /**
     * `GET zjmf_finance_api/renew` — upstream renewal orders.
     */
    public function renew(Request $request)
    {
        return app(InvoiceController::class)->renewList($request);
    }

    /**
     * `GET zjmf_finance_api/host` — upstream hosts.
     */
    public function hosts(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $apiId = (int) $request->input('zjmf_finance_api_id', $request->input('id', 0));

        $query = DB::table('host')
            ->leftJoin('products as p', 'p.id', '=', 'host.productid')
            ->leftJoin('clients as c', 'c.id', '=', 'host.uid')
            ->leftJoin('zjmf_finance_api as a', 'a.id', '=', 'p.zjmf_api_id')
            ->select('host.id', 'host.uid', 'host.orderid', 'host.productid', 'host.serverid', 'host.regdate', 'host.domain', 'host.payment', 'host.firstpaymentamount', 'host.amount', 'host.billingcycle', 'host.last_settle', 'host.nextduedate', 'host.nextinvoicedate', 'host.termination_date', 'host.completed_date', 'host.domainstatus', 'host.username', 'host.password', 'host.notes', 'host.subscriptionid', 'host.promoid', 'host.suspendreason', 'host.overideautosuspend', 'host.overidesuspenduntil', 'host.dedicatedip', 'host.assignedips', 'host.ns1', 'host.ns2', 'host.port', 'host.upstream_cost', 'host.create_time', 'host.update_time', 'host.suspend_time', 'host.os', 'host.remark', 'host.dcimid', 'host.initiative_renew', 'p.name as productname', 'p.type as product_type', 'c.username', 'a.name as server_name')
            ->whereNotNull('p.zjmf_api_id')
            ->where('p.zjmf_api_id', '>', 0);

        if ($apiId > 0) {
            $query->where('p.zjmf_api_id', $apiId);
        }

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where(function ($q) use ($name) {
                $q->where('host.domain', 'like', "%{$name}%")
                    ->orWhere('c.username', 'like', "%{$name}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('host.id')->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['name'] = $row->domain;
            $entry['type_zh'] = AdminMeta::productTypeLabel($row->product_type);
            $entry['domainstatus_zh'] = AdminMeta::domainStatusLabel($row->domainstatus);
            $entry['amount'] = $this->money((float) $row->amount);
            $entry['credit'] = $this->money((float) $row->amount - (float) ($row->upstream_cost ?? 0));
            $entry['saler'] = (string) (DB::table('user')->where('id', optional(Client::query()->find($row->uid))->sale_id)->value('user_nickname') ?? '');

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `GET zjmf_finance_api/manualhost` — the manual supplier's host records
     * (`shd_upper_manual_info`).
     */
    public function manualHostList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('upper_manual_info')
            ->leftJoin('host as h', 'h.id', '=', 'upper_manual_info.hid')
            ->leftJoin('clients as c', 'c.id', '=', 'h.uid')
            ->select('upper_manual_info.id', 'upper_manual_info.hid', 'upper_manual_info.regate', 'upper_manual_info.amount', 'upper_manual_info.billingcycle', 'upper_manual_info.dedicatedip', 'upper_manual_info.assignedips', 'upper_manual_info.create_time', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'h.amount as host_amount', 'h.billingcycle as host_billingcycle', 'h.domainstatus', 'h.create_time as host_create_time', 'c.username');

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('upper_manual_info.id')->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['regate'] = (int) $row->regate;
            $entry['create_time'] = (int) ($row->host_create_time ?: $row->create_time);
            $entry['domainstatus_zh'] = AdminMeta::domainStatusLabel($row->domainstatus);
            $entry['billingcycle'] = (string) ($row->host_billingcycle ?: $row->billingcycle);

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `POST zjmf_finance_api/manualhost` — save a manual host record.
     */
    public function manualHostSave(Request $request)
    {
        $hid = (int) $request->input('hid', $request->input('id', 0));

        if ($hid <= 0) {
            return $this->validationFail('产品ID不能为空');
        }

        if (Host::query()->find($hid) === null) {
            return $this->notFound('产品不存在');
        }

        $data = [
            'regate' => (string) ($this->timestamp($request->input('regate')) ?? 0),
            'amount' => (string) $this->money((float) $request->input('amount', 0)),
            'billingcycle' => (string) $request->input('billingcycle', ''),
            'dedicatedip' => (string) $request->input('dedicatedip', ''),
            'assignedips' => (string) $request->input('assignedips', ''),
            'create_time' => (string) ($this->timestamp($request->input('create_time')) ?? time()),
        ];

        $exists = DB::table('upper_manual_info')->where('hid', $hid)->exists();

        if ($exists) {
            DB::table('upper_manual_info')->where('hid', $hid)->update($data);
        } else {
            DB::table('upper_manual_info')->insert($data + ['hid' => $hid]);
        }

        // Mirror the editable figures onto the host row itself.
        $hostUpdate = [];

        if ($request->has('amount')) {
            $hostUpdate['amount'] = $this->money((float) $request->input('amount'));
        }

        if ($request->has('billingcycle')) {
            $hostUpdate['billingcycle'] = (string) $request->input('billingcycle');
        }

        if ($request->has('dedicatedip')) {
            $hostUpdate['dedicatedip'] = (string) $request->input('dedicatedip');
        }

        if ($request->has('assignedips')) {
            $hostUpdate['assignedips'] = (string) $request->input('assignedips');
        }

        if ($request->has('regate')) {
            $hostUpdate['nextduedate'] = $this->timestamp($request->input('regate')) ?? 0;
        }

        if ($hostUpdate !== []) {
            $hostUpdate['update_time'] = time();
            DB::table('host')->where('id', $hid)->update($hostUpdate);
        }

        $this->log('保存手动供应商产品 #'.$hid, $hid);

        return $this->ok(['id' => $hid], '保存成功');
    }

    /**
     * `POST zjmf_finance_api/upstreamhost` — fetch the live upstream host view
     * for a local product.
     */
    public function upstreamHost(Request $request)
    {
        $hostId = (int) $request->input('hid', $request->input('id', 0));
        $host = Host::query()->find($hostId);

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $api = FinanceApi::query()->find((int) ($host->product?->zjmf_api_id ?? 0));

        if ($api === null) {
            return $this->fail('该产品未对接供应商');
        }

        try {
            $result = (new SupplierClient($api))->hostStatus($host);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '获取上游产品信息失败');
        }

        return $this->respond($result + $this->envelope());
    }

    /**
     * `GET zjmf_finance_api/upstreamcredit {id}` — the supplier's balance.
     */
    public function upstreamCredit(Request $request)
    {
        $api = FinanceApi::query()->find((int) $request->input('id', 0));

        if ($api === null) {
            return $this->notFound('供应商不存在');
        }

        if ($api->type === 'manual') {
            return $this->ok(['credit' => 0, 'currency' => DB::table('currencies')->orderBy('id')->first()]);
        }

        try {
            $result = (new SupplierClient($api))->credit();
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '获取上游余额失败');
        }

        return $this->ok([
            'credit' => $result['credit'] ?? 0,
            'currency' => $result['currency'] ?? DB::table('currencies')->orderBy('id')->first(),
        ]);
    }

    /**
     * `GET zjmf_finance_api/addpage` — the 导入商品 dialog metadata.
     */
    public function addPage(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'data' => [
                'grouping' => DB::table('product_groups')->orderBy('order')->get(['id', 'name'])->toArray(),
                'upperReaches' => DB::table('zjmf_finance_api')
                    ->whereIn('type', ['zjmf_api', 'v10'])
                    ->orderBy('id')
                    ->get(['id', 'name'])
                    ->toArray(),
                'classification' => DB::table('nav')->whereIn('nav_type', [2, 3])->orderBy('order')->get(['id', 'name'])->toArray(),
            ],
        ] + $this->envelope());
    }

    /**
     * `POST zjmf_finance_api/inputproduct` — import upstream products.
     *
     * Posted as **multipart/form-data** with bracket keys:
     *   type[<upstreamPid>], productnames[<upstreamPid>], gid,
     *   zjmf_finance_api_id, ptype,
     *   upstream_price_value (markup + 100; 100 means no markup),
     *   rate (only when the supplier currency differs).
     */
    public function inputProduct(Request $request)
    {
        $types = (array) $request->input('type', []);
        $names = (array) $request->input('productnames', []);
        $gid = (int) $request->input('gid', 0);
        $apiId = (int) $request->input('zjmf_finance_api_id', 0);
        $ptype = (int) $request->input('ptype', 0);
        $markup = (float) $request->input('upstream_price_value', 100);
        $rate = (float) $request->input('rate', 0);

        if ($gid <= 0) {
            return $this->validationFail('请选择本地分组');
        }

        if ($apiId <= 0) {
            return $this->validationFail('请选择上游');
        }

        if ($types === []) {
            return $this->validationFail('请选择上游商品');
        }

        $api = FinanceApi::query()->find($apiId);

        if ($api === null) {
            return $this->notFound('供应商不存在');
        }

        $group = DB::table('product_groups')->where('id', $gid)->first();

        if ($group === null) {
            return $this->notFound('商品组不存在');
        }

        $factor = $markup > 0 ? $markup / 100 : 1;
        $now = time();
        $created = [];

        // A supplier billing in another currency is converted with the rate
        // the dialog collected before submitting.
        $conversion = $rate > 0 ? $rate : 1;

        DB::transaction(function () use ($types, $names, $gid, $group, $api, $ptype, $factor, $conversion, $now, &$created) {
            foreach ($types as $upstreamPid => $productType) {
                $upstreamPid = (int) $upstreamPid;
                $productType = (string) $productType;
                $name = (string) ($names[$upstreamPid] ?? ('上游商品'.$upstreamPid));

                $existing = Product::query()
                    ->where('zjmf_api_id', $api->id)
                    ->where('upstream_pid', $upstreamPid)
                    ->first();

                if ($existing !== null) {
                    $created[] = ['id' => (int) $existing->id, 'name' => $existing->name, 'msg' => '已存在，已跳过'];

                    continue;
                }

                $product = Product::query()->create([
                    'name' => $name,
                    'type' => $productType,
                    'gid' => $gid,
                    'groupid' => (int) ($group->gid ?? 0),
                    'description' => '',
                    'hidden' => 0,
                    'pay_type' => json_encode([
                        'pay_type' => 'recurring',
                        'pay_ontrial_status' => 0,
                        'pay_ontrial_condition' => [],
                    ]),
                    'pay_method' => 'prepayment',
                    'api_type' => 'zjmf_api',
                    'zjmf_api_id' => (int) $api->id,
                    'upstream_pid' => $upstreamPid,
                    'upstream_price_type' => 'percent',
                    'upstream_price_value' => $markup,
                    'upstream_stock_control' => 1,
                    'upstream_auto_setup' => 'order',
                    'server_group' => 0,
                    'order' => (int) (Product::query()->max('order') ?? 0) + 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                // Pull the upstream price matrix and apply the markup.
                $pricing = [];

                try {
                    $detail = (new SupplierClient($api))->productDetail($upstreamPid);
                    $pricing = $detail['pricing'] ?? [];
                } catch (\Throwable) {
                    $pricing = [];
                }

                foreach (DB::table('currencies')->pluck('id') as $currencyId) {
                    $row = [
                        'type' => 'product',
                        'relid' => $product->id,
                        'currency' => (int) $currencyId,
                    ];

                    foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
                        $row[$cycle] = -1;
                    }

                    foreach (AdminMeta::SETUP_COLUMNS as $column) {
                        $row[$column] = 0;
                    }

                    DB::table('pricing')->insert($row);
                }

                foreach ($pricing as $entry) {
                    $entry = is_array($entry) ? $entry : (array) $entry;
                    $update = [];

                    foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
                        if (! isset($entry[$cycle]) || (float) $entry[$cycle] < 0) {
                            continue;
                        }

                        $update[$cycle] = $this->money((float) $entry[$cycle] * $factor * $conversion);
                    }

                    if ($update !== []) {
                        DB::table('pricing')
                            ->where('type', 'product')
                            ->where('relid', $product->id)
                            ->update($update);
                    }
                }

                if ($ptype > 0) {
                    $current = (string) DB::table('nav')->where('id', $ptype)->value('relid');
                    $ids = array_filter(array_map('intval', explode(',', $current)));
                    $ids[] = (int) $product->id;
                    DB::table('nav')->where('id', $ptype)->update(['relid' => implode(',', array_unique($ids))]);
                }

                $created[] = ['id' => (int) $product->id, 'name' => $name, 'msg' => '导入成功'];
            }
        });

        $this->log('导入上游商品 '.count($created).' 个');

        return $this->ok($created, '导入完成');
    }

    /**
     * `GET zjmf_finance_api/logs` — the downstream API call log.
     */
    public function logs(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('api_resource_log');

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        if ($apiId = $request->input('id', $request->input('pid'))) {
            $query->where('pid', (int) $apiId);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'uid' => (int) $row->uid,
            'username' => (string) (Client::query()->whereKey($row->uid)->value('username') ?? ''),
            'pid' => (int) $row->pid,
            'description' => $row->description,
            'new_desc' => $row->description,
            'version' => $row->version,
            'ip' => $row->ip,
            'ipaddr' => $row->ip,
            'source' => $row->source,
            'port' => $row->port,
            'create_time' => (int) $row->create_time,
        ])->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `POST zjmf_finance_api/toggle` — enable/disable a client's downstream API.
     */
    public function toggle(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $open = $request->has('api_open')
            ? (int) $request->input('api_open')
            : ((int) $client->api_open === 1 ? 0 : 1);

        $client->api_open = $open;
        $client->api_lock_time = 0;
        $client->lock_reason = '';
        $client->update_time = time();

        if ($open === 1 && (string) $client->api_password === '') {
            $client->api_password = PasswordHasher::apiPassword();
            $client->api_create_time = time();
        }

        $client->save();

        $this->log(($open === 1 ? '开启' : '关闭').'客户'.$client->username.'的API', $uid);

        return $this->ok(['uid' => $uid, 'api_open' => $open], '操作成功');
    }

    /**
     * `POST zjmf_finance_api/open` — grant API access (downstream self-service).
     */
    public function open(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        if (! SettingService::bool('allow_resource_api')) {
            return $this->fail('系统未开启资源API');
        }

        if (SettingService::bool('allow_resource_api_realname') && ! $client->certifi) {
            return $this->fail('请先完成实名认证');
        }

        if (SettingService::bool('allow_resource_api_phone') && trim((string) $client->phonenumber) === '') {
            return $this->fail('请先绑定手机号');
        }

        $client->api_open = 1;
        $client->api_password = PasswordHasher::apiPassword();
        $client->api_create_time = time();
        $client->update_time = time();
        $client->save();

        $this->log('开启客户'.$client->username.'的资源API', $uid);

        return $this->ok([
            'uid' => $uid,
            'api_password' => $client->api_password,
        ], '开启成功');
    }

    /**
     * `POST zjmf_finance_api/reset` — rotate a client's API secret.
     */
    public function reset(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $client->api_password = PasswordHasher::apiPassword();
        $client->update_time = time();
        $client->save();

        $this->log('重置客户'.$client->username.'的API密钥', $uid);

        return $this->ok(['uid' => $uid, 'api_password' => $client->api_password], '重置成功');
    }

    /**
     * `GET|POST|DELETE zjmf_finance_api/freepage` — the 免登录/白名单 config
     * (which client account the downstream panel may act as).
     */
    public function freePage(Request $request)
    {
        $rows = DB::table('clients')
            ->where('api_open', 1)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'username', 'email', 'api_password', 'api_create_time']);

        return $this->ok([
            'list' => $rows->map(fn ($c) => [
                'uid' => (int) $c->id,
                'username' => $c->username,
                'email' => $c->email,
                'api_password' => $c->api_password,
                'api_create_time' => (int) $c->api_create_time,
            ])->all(),
            'allow_resource_api' => (int) (SettingService::value('allow_resource_api', '0') ?? 0),
        ]);
    }

    /**
     * `POST zjmf_finance_api/freepage` — add a client to the downstream list.
     */
    public function freePost(Request $request)
    {
        return $this->open($request);
    }

    /**
     * `DELETE zjmf_finance_api/freepage {uid}` — revoke downstream access.
     */
    public function freeDelete(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('id', 0));
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $client->api_open = 0;
        $client->update_time = time();
        $client->save();

        return $this->ok(['uid' => $uid], '已关闭');
    }

    // -----------------------------------------------------------------
    // 任务队列
    // -----------------------------------------------------------------

    /**
     * `GET run_map/list` — 任务队列 (`shd_run_maping`).
     */
    public function taskQueue(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('run_maping');

        // The SPA filters with `query[<column>]=<value>`.
        $filters = $request->input('query', []);

        if (is_array($filters)) {
            foreach ($filters as $column => $value) {
                if (in_array((string) $column, ['id', 'user_id', 'host_id', 'from_type', 'active_type', 'status'], true) && $value !== '') {
                    $query->where((string) $column, $value);
                }
            }
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('status', (int) $status);
        }

        if ($user = trim((string) $request->input('user', ''))) {
            $query->where('user', 'like', "%{$user}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['domain'] = (string) (DB::table('host')->where('id', $row->host_id)->value('domain') ?? '');
            $entry['active_type_zh'] = $this->mapAction((int) $row->active_type);
            // Nested ternaries are ambiguous in PHP 8; resolve the label explicitly.
            $entry['credit'] = match ((int) $row->status) {
                1 => '成功',
                2 => '失败',
                default => '等待中',
            };

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
            'status_map' => [0 => '等待中', 1 => '成功', 2 => '失败'],
        ]);
    }

    /**
     * `POST run_map/repeat_task` — retry a queued module action.
     */
    public function repeatTask(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('run_maping')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('任务不存在');
        }

        $host = Host::query()->find((int) $row->host_id);

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $action = trim((string) ($row->active_type_param ?: ''));

        try {
            $result = (new \App\Services\ModuleService())->dispatch($host, $action !== '' ? $action : 'status');
        } catch (\Throwable $e) {
            DB::table('run_maping')->where('id', $id)->update([
                'status' => 2,
                'last_execute_time' => time(),
            ]);

            return $this->fail($e->getMessage() ?: '执行失败');
        }

        DB::table('run_maping')->where('id', $id)->update([
            'status' => ($result['status'] ?? 0) === ApiResponse::OK ? 1 : 2,
            'last_execute_time' => time(),
        ]);

        $this->log('重试任务队列 #'.$id, (int) $row->host_id);

        return $this->respond($result + $this->envelope());
    }

    /**
     * `POST task_queue/clear` — drop completed queue rows.
     */
    public function taskQueueClear(Request $request)
    {
        $status = (int) $request->input('status', 1);

        $deleted = DB::table('run_maping')->where('status', $status)->delete();

        $this->log('清理任务队列 '.$deleted.' 条');

        return $this->ok(['deleted' => $deleted], '清理成功');
    }

    // -----------------------------------------------------------------
    // 下游 API 用户
    // -----------------------------------------------------------------

    /**
     * `GET api` — the 下游 API 用户 list (`shd_api`).
     */
    public function apiList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        // `shd_api` stores the downstream credentials that let another
        // finance system sign in as one of my clients.
        $query = DB::table('api');

        if ($username = trim((string) $request->input('username', $request->input('keywords', '')))) {
            $query->where('username', 'like', "%{$username}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'username' => $row->username,
            'ip' => $row->ip,
            'is_auto' => (int) $row->is_auto,
            'create_time' => (int) $row->create_time,
        ])->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `GET api/create` — the 添加 API 用户 form metadata.
     */
    public function apiCreatePage(Request $request)
    {
        return $this->ok([
            'client_groups' => AdminMeta::clientGroups(),
            'allow_resource_api' => (int) (SettingService::value('allow_resource_api', '0') ?? 0),
        ]);
    }

    /**
     * `POST api` — create a downstream API user.
     */
    public function apiCreate(Request $request)
    {
        $username = trim((string) $request->input('username', ''));

        if ($username === '') {
            return $this->validationFail('用户名不能为空');
        }

        if (DB::table('api')->where('username', $username)->exists()) {
            return $this->ok(['id' => (int) DB::table('api')->where('username', $username)->value('id')], '该用户名已存在');
        }

        $id = (int) DB::table('api')->insertGetId([
            'username' => $username,
            'password' => (string) ($request->input('password') ?: PasswordHasher::apiPassword()),
            'ip' => is_array($request->input('ip'))
                ? implode(',', $request->input('ip'))
                : (string) $request->input('ip', ''),
            'is_auto' => (int) $request->input('is_auto', 0),
            'create_time' => time(),
        ]);

        $this->log('添加API用户：'.$username, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `PUT api` — edit a downstream API user.
     */
    public function apiUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('api')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('API用户不存在');
        }

        $data = [];

        if ($request->filled('username')) {
            $data['username'] = (string) $request->input('username');
        }

        if ($request->filled('password')) {
            $data['password'] = (string) $request->input('password');
        }

        if ($request->has('ip')) {
            $data['ip'] = is_array($request->input('ip'))
                ? implode(',', $request->input('ip'))
                : (string) $request->input('ip');
        }

        if ($request->has('is_auto')) {
            $data['is_auto'] = (int) $request->input('is_auto');
        }

        if ($data !== []) {
            DB::table('api')->where('id', $id)->update($data);
        }

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `GET api/<id>`
     */
    public function apiDetail(Request $request, $id)
    {
        $row = DB::table('api')->where('id', (int) $id)->first();

        return $row === null
            ? $this->notFound('API用户不存在')
            : $this->ok([
                'id' => (int) $row->id,
                'username' => $row->username,
                'ip' => $row->ip,
                'is_auto' => (int) $row->is_auto,
                'create_time' => (int) $row->create_time,
            ]);
    }

    /**
     * `DELETE api/<id>`
     */
    public function apiDelete(Request $request, $id)
    {
        DB::table('api')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET api_user_product/list` — the products a downstream agent may sell.
     */
    public function apiUserProductList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        $uid = (int) $request->input('uid', 0);

        $query = DB::table('api_user_product')->when($uid > 0, fn ($q) => $q->where('uid', $uid));

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();
        $products = Product::query()->pluck('name', 'id');

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'uid' => (int) $row->uid,
            'pid' => (int) $row->pid,
            'productname' => (string) ($products[$row->pid] ?? ''),
            'qty' => (int) $row->qty,
            'ontrial' => (int) $row->ontrial,
        ])->all();

        return $this->paginated($list, $total, $page, $limit);
    }

    // -----------------------------------------------------------------
    // 上游资源 / DCIM 代理
    // -----------------------------------------------------------------

    /**
     * `GET munualresource` / `GET upper/index` — the supplier resource list.
     */
    public function resourceList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('upper_reaches');

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where('name', 'like', "%{$name}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'phone' => $row->phone,
            'bz' => $row->bz,
            'create_time' => (int) $row->create_time,
            'ip_count' => DB::table('upper_reaches_ip')->where('resid', $row->id)->count(),
        ])->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `GET upper/index` / `GET upper/upperindex` — the resource rows, optionally
     * filtered per upstream.
     */
    public function upperIndex(Request $request)
    {
        return $this->resourceList($request);
    }

    /**
     * `GET upper/addupperpage` — the 添加资源 form metadata.
     */
    public function upperAddPage(Request $request)
    {
        return $this->ok([
            'upper_reaches' => DB::table('upper_reaches')->orderBy('id')->get()->toArray(),
            'ips' => DB::table('upper_reaches_ip')->orderByDesc('id')->limit(500)->get()->toArray(),
        ]);
    }

    /**
     * `POST upper/addpost` / `POST upper/addupperpost` — add an upstream
     * resource record.
     */
    public function upperAdd(Request $request)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('名称不能为空');
        }

        $id = (int) DB::table('upper_reaches')->insertGetId([
            'name' => $name,
            'phone' => (string) $request->input('phone', ''),
            'bz' => (string) $request->input('bz', ''),
            'create_time' => time(),
        ]);

        $this->log('添加上游资源：'.$name, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `GET upper/editupperpage {id}`
     */
    public function upperEditPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('upper_reaches')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('资源不存在');
        }

        return $this->ok([
            'data' => (array) $row,
            'ips' => DB::table('upper_reaches_ip')->where('resid', $id)->get()->map(fn ($i) => (array) $i)->all(),
        ]);
    }

    /**
     * `POST upper/edituppost` / `POST upper/editupperpost`
     */
    public function upperUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('upper_reaches')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('资源不存在');
        }

        $data = [];

        foreach (['name', 'phone', 'bz'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        if ($data !== []) {
            DB::table('upper_reaches')->where('id', $id)->update($data);
        }

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `POST upper/del` / `POST upper/delupper`
     */
    public function upperDelete(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('upper_reaches_ip')->whereIn('resid', $ids)->delete();
        DB::table('upper_reaches')->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    /**
     * `POST upper/allotupper` — assign an IP block to a resource.
     */
    public function upperAllot(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $ips = $request->input('ip', $request->input('ips', ''));

        if ($id <= 0) {
            return $this->validationFail('ID错误');
        }

        $items = is_array($ips) ? $ips : preg_split('/[\s,]+/', (string) $ips);
        $items = array_values(array_filter(array_map('trim', (array) $items), fn ($v) => $v !== ''));

        foreach ($items as $ip) {
            $exists = DB::table('upper_reaches_ip')
                ->where('resid', $id)
                ->where('ip', $ip)
                ->exists();

            if (! $exists) {
                DB::table('upper_reaches_ip')->insert([
                    'resid' => $id,
                    'ip' => $ip,
                ]);
            }
        }

        $this->log('分配上游IP '.count($items).' 个', $id);

        return $this->ok(['count' => count($items)], '分配成功');
    }

    /**
     * `POST upper/emptyupper` — release the IPs of a resource.
     */
    public function upperEmpty(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->validationFail('ID错误');
        }

        $deleted = DB::table('upper_reaches_ip')->where('resid', $id)->delete();

        return $this->ok(['deleted' => $deleted], '清空成功');
    }

    /**
     * `GET upper/dcim_client/status {id}`
     */
    public function dcimStatus(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/status', $request);
    }

    public function dcimOn(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/on', $request);
    }

    public function dcimOff(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/off', $request);
    }

    public function dcimReboot(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/reboot', $request);
    }

    public function dcimVnc(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/vnc', $request);
    }

    public function dcimReinstall(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/reinstall', $request);
    }

    public function dcimGetOs(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/get_os', $request);
    }

    public function dcimCrackPass(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/crack_pass', $request);
    }

    public function dcimReinstallStatus(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/resintall_status', $request);
    }

    public function dcimCancelTask(Request $request)
    {
        return $this->dcimProxy('upper/dcim_client/cancel_task', $request);
    }

    /**
     * `GET upper/ipmi/status {id}`
     */
    public function ipmiStatus(Request $request)
    {
        return $this->dcimProxy('upper/ipmi/status', $request);
    }

    public function ipmiOn(Request $request)
    {
        return $this->dcimProxy('upper/ipmi/on', $request);
    }

    public function ipmiOff(Request $request)
    {
        return $this->dcimProxy('upper/ipmi/off', $request);
    }

    public function ipmiReboot(Request $request)
    {
        return $this->dcimProxy('upper/ipmi/reboot', $request);
    }

    public function ipmiVnc(Request $request)
    {
        return $this->dcimProxy('upper/ipmi/vnc', $request);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Create or update a supplier row.
     */
    private function writeApi(Request $request, int $id)
    {
        $name = trim((string) $request->input('name', ''));
        $type = (string) $request->input('type', 'manual');

        if ($name === '') {
            return $this->validationFail('名称不能为空');
        }

        if (! in_array($type, ['manual', 'zjmf_api', 'v10', 'whmcs'], true)) {
            return $this->validationFail('接口类型错误');
        }

        if ($type !== 'manual') {
            foreach (['hostname', 'username', 'password'] as $required) {
                if (trim((string) $request->input($required, '')) === '') {
                    return $this->validationFail('请填写完整的接口信息');
                }
            }
        } else {
            if (trim((string) $request->input('contact_way', '')) === '' && $id === 0) {
                return $this->validationFail('请填写联系方式');
            }
        }

        $data = [
            'name' => $name,
            'type' => $type,
            'des' => (string) $request->input('des', ''),
            'contact_way' => (string) $request->input('contact_way', ''),
        ];

        if ($type !== 'manual') {
            $data['hostname'] = (string) $request->input('hostname');
            $data['username'] = (string) $request->input('username');
            $data['password'] = (string) $request->input('password');
        }

        if ($id > 0) {
            // The interface type is fixed once a supplier exists.
            unset($data['type']);

            FinanceApi::query()->whereKey($id)->update($data);
            $this->log('编辑供应商：'.$name, $id);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['status'] = 0;
        $data['create_time'] = time();
        $data['is_resource'] = 0;

        $api = FinanceApi::query()->create($data);

        $this->log('添加供应商：'.$name, (int) $api->id);

        return $this->ok(['id' => (int) $api->id], '添加成功');
    }

    /**
     * A supplier row with the derived counters the list renders.
     */
    private function apiRow(FinanceApi $api, bool $withSecret = false): array
    {
        $productIds = Product::query()->where('zjmf_api_id', $api->id)->pluck('id')->all();

        $hosts = DB::table('host')->whereIn('productid', $productIds ?: [-1]);

        $row = [
            'id' => (int) $api->id,
            'name' => $api->name,
            'type' => $api->type ?: 'manual',
            'type_zh' => ['manual' => '手动', 'zjmf_api' => '智简魔方', 'v10' => 'v10', 'whmcs' => 'WHMCS'][$api->type ?: 'manual'] ?? '手动',
            'hostname' => $api->hostname,
            'username' => $api->username,
            'des' => $api->des,
            'contact_way' => $api->contact_way,
            'status' => (int) $api->status,
            'desc' => (int) $api->status === 1 ? '链接成功' : '链接失败',
            'product_num' => (int) $api->product_num,
            'set_product_num' => count($productIds),
            'host_num' => (clone $hosts)->count(),
            'active_host_num' => (clone $hosts)->where('domainstatus', 'Active')->count(),
            'create_time' => (int) $api->create_time,
            'is_resource' => (int) $api->is_resource,
            'ticket_open' => (int) $api->ticket_open,
            'credit' => 0,
        ];

        if ($withSecret) {
            $row['password'] = $api->password;
        }

        return $row;
    }

    /**
     * Client ids that belong to a supplier's downstream set: everyone who has
     * bought one of its products.
     */
    private function downstreamUids(int $apiId): array
    {
        $productIds = Product::query()->where('zjmf_api_id', $apiId)->pluck('id')->all();

        if ($productIds === []) {
            return [-1];
        }

        return Host::query()
            ->whereIn('productid', $productIds)
            ->pluck('uid')
            ->unique()
            ->all() ?: [-1];
    }

    /**
     * Human label for a `shd_run_maping.active_type` code.
     */
    private function mapAction(int $action): string
    {
        return [
            1 => '开通', 2 => '暂停', 3 => '解除暂停', 4 => '删除',
            5 => '续费', 6 => '升降级', 7 => '同步', 8 => '开机',
            9 => '关机', 10 => '重启', 11 => '重装系统', 12 => '重置密码',
        ][$action] ?? (string) $action;
    }

    /**
     * Forward a DCIM/IPMI action to the supplier that owns the host.
     *
     * The original proxies these straight through to the upstream install;
     * here the owning product's supplier is resolved and the request is made
     * with the module layer, so an install without upstream DCIM connectivity
     * still returns a usable envelope rather than an error page.
     */
    private function dcimProxy(string $path, Request $request)
    {
        $hostId = (int) $request->input('id', 0);
        $host = Host::query()->find($hostId);

        if ($host === null) {
            return $this->fail('ID错误');
        }

        $module = new \App\Services\ModuleService();

        $action = match (true) {
            str_contains($path, '/on') => 'on',
            str_contains($path, '/off') => 'off',
            str_contains($path, '/reboot') => 'reboot',
            str_contains($path, '/vnc') => 'vnc',
            str_contains($path, '/reinstall') => 'reinstall',
            str_contains($path, '/crack_pass') => 'crack_pass',
            str_contains($path, '/status') => 'status',
            str_contains($path, '/resintall_status') => 'status',
            str_contains($path, '/cancel_task') => 'status',
            str_contains($path, '/get_os') => 'get_reinstall',
            default => 'status',
        };

        try {
            $result = $module->dispatch($host, $action, false, $request->except(['id']));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '操作失败');
        }

        return $this->respond($result + $this->envelope());
    }
}
