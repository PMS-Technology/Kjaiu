<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Host;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductConfigGroup;
use App\Models\ProductConfigOption;
use App\Models\ProductConfigOptionSub;
use App\Models\ProductDownload;
use App\Models\PromoCode;
use App\Models\ServerGroup;
use Illuminate\Support\Facades\DB;

/**
 * Read-side helpers for `shd_host`: billing-cycle labels, the current
 * configurable-option selection, the module client-area payload and the
 * prorated amounts used by the upgrade flows.
 *
 * Shared by the client-area service pages and the public API so the two
 * surfaces cannot drift apart.
 */
class HostService
{
    /** Billing cycle => Chinese label, as the original `$Lang.billing_cycle_*`. */
    public const CYCLE_LABELS = [
        'free' => '免费',
        'onetime' => '一次性',
        'hour' => '小时',
        'day' => '天',
        'ontrial' => '试用',
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
        'biennially' => '两年付',
        'triennially' => '三年付',
        'fourly' => '四年付',
        'fively' => '五年付',
        'sixly' => '六年付',
        'sevenly' => '七年付',
        'eightly' => '八年付',
        'ninely' => '九年付',
        'tenly' => '十年付',
    ];

    /** Short cycle form used inside list cells. */
    public const CYCLE_SHORT = [
        'free' => '免费',
        'onetime' => '一次性',
        'hour' => '小时',
        'day' => '天',
        'ontrial' => '试用',
        'monthly' => '月',
        'quarterly' => '季',
        'semiannually' => '半年',
        'annually' => '年',
        'biennially' => '两年',
        'triennially' => '三年',
        'fourly' => '四年',
        'fively' => '五年',
        'sixly' => '六年',
        'sevenly' => '七年',
        'eightly' => '八年',
        'ninely' => '九年',
        'tenly' => '十年',
    ];

    /** `shd_host.domainstatus` => Chinese label. */
    public const STATUS_LABELS = [
        'Pending' => '待开通',
        'Active' => '已激活',
        'Suspended' => '已暂停',
        'Cancelled' => '被取消',
        'Fraud' => '有欺诈',
        'Deleted' => '被删除',
        'Completed' => '已完成',
        'Verifiy_Active' => '待核验',
        'Overdue_Active' => '已逾期',
        'Issue_Active' => '已签发',
    ];

    /** Statuses a client-area list shows by default. */
    public const DEFAULT_STATUSES = ['Pending', 'Active', 'Suspended'];

    public function __construct(
        protected PricingService $pricing = new PricingService(),
        protected ModuleService $modules = new ModuleService(),
    ) {
    }

    public static function cycleLabel(string $cycle): string
    {
        return self::CYCLE_LABELS[$cycle] ?? $cycle;
    }

    public static function cycleShort(string $cycle): string
    {
        return self::CYCLE_SHORT[$cycle] ?? $cycle;
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[(string) $status] ?? (string) $status;
    }

    /**
     * Product display name, matching the original's `groupname-productname` shape.
     */
    public function productName(Host $host): string
    {
        $product = $host->product;

        if ($product === null) {
            return '';
        }

        $group = $product->group;

        return $group === null ? (string) $product->name : ($group->name . '-' . $product->name);
    }

    /**
     * Group name for the service list's grouping column.
     */
    public function groupName(Host $host): string
    {
        $product = $host->product;

        if ($product === null) {
            return '';
        }

        return (string) ($product->group?->name ?? '');
    }

    /**
     * The billing cycles a host may be renewed into, with prices.
     *
     * @return array<int, array{billingcycle:string, billingcycle_zh:string, price:string, setup_fee:string, amount:string, saleproducts:string}>
     */
    public function renewalCycles(Host $host, ?int $currencyId = null): array
    {
        $product = $host->product;

        if ($product === null) {
            return [];
        }

        $currencyId = $currencyId ?? $this->pricing->currencyId();
        $pricing = $this->pricing->productPricing($product, $currencyId);
        $client = $host->client;
        $discount = $client?->group?->discount_percent;

        $cycles = [];

        foreach ($pricing?->availableCycles() ?? [] as $cycle) {
            $price = (float) $pricing->priceFor($cycle);
            $setup = $pricing->setupFeeFor($cycle);
            $amount = PricingService::money($price + $setup);
            $sale = $this->pricing->groupDiscount($amount, $discount === null ? null : (int) $discount);

            $cycles[] = [
                'billingcycle' => $cycle,
                'billingcycle_zh' => self::cycleLabel($cycle),
                'price' => $this->money($price),
                'setup_fee' => $this->money($setup),
                'amount' => $this->money($amount),
                'saleproducts' => $discount ? $this->money($sale) : '0.00',
            ];
        }

        return $cycles;
    }

    /**
     * Amount a host costs for one cycle, honouring its own negotiated amount
     * for the cycle it is already on.
     */
    public function cycleAmount(Host $host, string $cycle, ?int $currencyId = null): ?float
    {
        $product = $host->product;

        if ($product === null) {
            return null;
        }

        if ($cycle === (string) $host->billingcycle && (float) $host->amount > 0) {
            return PricingService::money((float) $host->amount);
        }

        $price = $this->pricing->cyclePrice($product, $cycle, $currencyId);

        if ($price === null) {
            return null;
        }

        $discount = $host->client?->group?->discount_percent;

        return $this->pricing->groupDiscount(
            PricingService::money($price + $this->pricing->setupFee($product, $cycle, $currencyId)),
            $discount === null ? null : (int) $discount
        );
    }

    /**
     * `pay_type` payload for the storefront's cycle rules.
     */
    public function payTypePayload(Product $product): array
    {
        $payType = $product->payType();
        $rules = (array) ($payType['pay_ontrial_condition'] ?? []);

        if ($rules === [] && isset($payType['pay_ontrial_condition'])) {
            $rules = (array) $payType['pay_ontrial_condition'];
        }

        return [
            'pay_type' => (string) ($payType['pay_type'] ?? 'recurring'),
            'pay_hour_cycle' => (int) ($payType['pay_hour_cycle'] ?? 720),
            'pay_day_cycle' => (int) ($payType['pay_day_cycle'] ?? 30),
            'pay_ontrial_status' => (int) ($payType['pay_ontrial_status'] ?? 0),
            'pay_ontrial_cycle_type' => (string) ($payType['pay_ontrial_cycle_type'] ?? 'day'),
            'pay_ontrial_cycle' => (int) ($payType['pay_ontrial_cycle'] ?? 0),
            'pay_ontrial_condition' => $rules,
            'pay_ontrial_num' => (int) ($payType['pay_ontrial_num'] ?? 0),
            'pay_ontrial_num_rule' => (int) ($payType['pay_ontrial_num_rule'] ?? 0),
            'clientscount_rule' => (int) ($payType['clientscount_rule'] ?? 0),
        ];
    }

    /**
     * Currency block used by the client-area payloads.
     */
    public function currencyPayload(?Currency $currency = null): array
    {
        $currency = $currency ?? Currency::default();

        if ($currency === null) {
            return ['id' => 0, 'code' => '', 'prefix' => '', 'suffix' => '', 'default' => 1];
        }

        return [
            'id' => (int) $currency->id,
            'code' => (string) $currency->code,
            'prefix' => (string) $currency->prefix,
            'suffix' => (string) $currency->suffix,
            'default' => (int) $currency->default,
        ];
    }

    /**
     * Current configurable-option selection for a host.
     *
     * @return array<int, array{id:int, name:string, sub_name:string, sub_id:int, qty:int, option_type:int}>
     */
    public function configOptions(Host $host): array
    {
        $rows = DB::table('host_config_options')->where('relid', $host->id)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $optionIds = $rows->pluck('configid')->map(fn ($id) => (int) $id)->unique()->all();
        $subIds = $rows->pluck('optionid')->map(fn ($id) => (int) $id)->unique()->all();

        $options = ProductConfigOption::query()->whereIn('id', $optionIds)->get()->keyBy('id');
        $subs = ProductConfigOptionSub::query()->whereIn('id', $subIds)->get()->keyBy('id');

        $out = [];

        foreach ($rows as $row) {
            $option = $options->get((int) $row->configid);
            $sub = $subs->get((int) $row->optionid);

            $out[] = [
                'id' => (int) $row->configid,
                'name' => (string) ($option->option_name ?? ''),
                'option_type' => (int) ($option->option_type ?? 0),
                'sub_id' => (int) $row->optionid,
                'sub_name' => (string) ($sub->option_name ?? ''),
                'qty' => (int) $row->qty,
            ];
        }

        return $out;
    }

    /**
     * Custom fields attached to a host, with their stored values.
     */
    public function customFields(Host $host): array
    {
        $fields = CustomField::query()
            ->where('type', 'product')
            ->where('relid', $host->productid)
            ->orderBy('sortorder')
            ->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $values = CustomFieldValue::query()
            ->where('relid', $host->id)
            ->pluck('value', 'fieldid');

        return $fields->map(fn (CustomField $field) => [
            'id' => (int) $field->id,
            'name' => (string) $field->fieldname,
            'value' => (string) ($values[$field->id] ?? ''),
        ])->values()->all();
    }

    /**
     * The module's own client-area panels (`module_client_area`), read from
     * the driver. Returns [] when the module declares none.
     */
    public function clientArea(Host $host): array
    {
        $module = $this->modules->moduleNameFor($host);

        if ($module === '' || $this->modules->isUpstream($host)) {
            return $this->upstreamClientArea($host);
        }

        $result = $this->modules->call($module, 'client_area', $host);

        if (! $result['status'] || ! is_array($result['data'])) {
            return [];
        }

        $areas = [];

        foreach ($result['data'] as $key => $value) {
            if (is_array($value) && isset($value['key'])) {
                $areas[] = ['key' => (string) $value['key'], 'name' => (string) ($value['name'] ?? $value['key'])];
            } elseif (is_string($key)) {
                $areas[] = ['key' => $key, 'name' => is_string($value) ? $value : $key];
            }
        }

        return $areas;
    }

    /**
     * Upstream products expose the supplier's own client-area tabs.
     */
    protected function upstreamClientArea(Host $host): array
    {
        $client = $this->modules->supplierClient($host);

        if ($client === null) {
            return [];
        }

        try {
            $result = $client->request('get', '/v1/hosts/' . $host->id . '/module');
        } catch (\Throwable) {
            return [];
        }

        $data = $result['data'] ?? $result;

        if (! is_array($data)) {
            return [];
        }

        $areas = [];

        foreach ($data as $item) {
            if (is_array($item) && isset($item['function'])) {
                $areas[] = [
                    'key' => (string) $item['function'],
                    'name' => (string) ($item['name'] ?? $item['function']),
                ];
            }
        }

        return $areas;
    }

    /**
     * Files a client may download for this host's product.
     */
    public function hostDownloads(Host $host): array
    {
        $downloadIds = ProductDownload::query()
            ->where('product_id', $host->productid)
            ->pluck('download_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($downloadIds === []) {
            return [];
        }

        return \App\Models\Download::query()
            ->whereIn('id', $downloadIds)
            ->where('hidden', 0)
            ->orderBy('id')
            ->get()
            ->map(fn (\App\Models\Download $download) => [
                'id' => (int) $download->id,
                'name' => (string) $download->title,
                'title' => (string) $download->title,
                'type' => (int) $download->type,
                'filetype' => (string) $download->filetype,
                'location' => (string) $download->location,
                'locationname' => (string) $download->locationname,
                'url' => (string) $download->url,
                'amount' => (int) $download->downloads,
                'create_time' => (int) $download->create_time,
            ])
            ->values()
            ->all();
    }

    /**
     * Product groups a host catalogue is grouped by (`host_cates` filter).
     */
    public function categories(Client $client): array
    {
        $hosts = Host::query()
            ->where('uid', $client->id)
            ->whereIn('domainstatus', self::DEFAULT_STATUSES)
            ->get();

        $groupIds = [];

        foreach ($hosts as $host) {
            $groupId = $host->product?->gid;

            if ($groupId) {
                $groupIds[(int) $groupId] = true;
            }
        }

        if ($groupIds === []) {
            return [];
        }

        return \App\Models\ProductGroup::query()
            ->whereIn('id', array_keys($groupIds))
            ->orderBy('order')
            ->get()
            ->map(fn ($group) => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->values()
            ->all();
    }

    /**
     * Products a host may be upgraded to, from `shd_product_upgrade_products`.
     *
     * @return array<int, array{pid:int, host:string, description:string, cycle:array}>
     */
    public function upgradeTargets(Host $host, ?int $currencyId = null): array
    {
        $upgradeIds = DB::table('product_upgrade_products')
            ->where('product_id', $host->productid)
            ->pluck('upgrade_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($upgradeIds === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', $upgradeIds)
            ->where('hidden', 0)
            ->get();

        return $products->map(function (Product $product) use ($currencyId, $host) {
            return [
                'pid' => (int) $product->id,
                'host' => (string) $product->name,
                'description' => (string) $product->description,
                'type' => (string) $product->type,
                'cycle' => $this->upgradeCyclePayload($product, $host, $currencyId),
            ];
        })->values()->all();
    }

    /**
     * Cycles a client may move a host onto when upgrading products.
     */
    public function upgradeCyclePayload(Product $product, Host $host, ?int $currencyId = null): array
    {
        $currencyId = $currencyId ?? $this->pricing->currencyId();
        $pricing = $this->pricing->productPricing($product, $currencyId);
        $discount = $host->client?->group?->discount_percent;

        $cycles = [];

        foreach ($pricing?->availableCycles() ?? [] as $cycle) {
            $price = (float) $pricing->priceFor($cycle);
            $setup = $pricing->setupFeeFor($cycle);
            $sale = $this->pricing->groupDiscount(
                PricingService::money($price + $setup),
                $discount === null ? null : (int) $discount
            );

            $cycles[] = [
                'billingcycle' => $cycle,
                'billingcycle_zh' => self::cycleLabel($cycle),
                'price' => $this->money($price),
                'setup_fee' => $this->money($setup),
                'saleproducts' => $discount ? $this->money($sale) : '0.00',
            ];
        }

        return $cycles;
    }

    /**
     * Prorated amount for a product upgrade.
     *
     * The original charges the difference between the new and old recurring
     * amount for the remainder of the current billing period, plus the setup
     * fee when the new product charges one.
     */
    public function proratedUpgradeAmount(Host $host, float $newRecurring, float $newSetup = 0.0, ?int $at = null): float
    {
        $cycle = (string) $host->billingcycle;
        $totalDays = $this->pricing->cycleDays($cycle) ?? 0;
        $now = $at ?? time();
        $dueDate = (int) $host->nextduedate;
        $remaining = max(0, $dueDate - $now);
        $daysRemaining = (int) ceil($remaining / 86400);

        if ($totalDays <= 0) {
            // onetime / hourly products switch outright.
            return PricingService::money($newRecurring + $newSetup);
        }

        $daysRemaining = min($daysRemaining, $totalDays);
        $oldRecurring = (float) $host->amount;

        $difference = (float) \App\Models\Configuration::value('upgrade_prorata_type', '') === 'full'
            ? $newRecurring - $oldRecurring
            : ($newRecurring - $oldRecurring) * ($daysRemaining / max(1, $totalDays));

        $amount = $difference + $newSetup;

        // Downgrades are refunded only when the setting allows it.
        if ($amount < 0 && (int) Configuration::value('credit_on_downgrade', 1) !== 1) {
            return 0.0;
        }

        return PricingService::money($amount);
    }

    /**
     * Number of days left and the length of the current cycle.
     *
     * @return array{0:int, 1:int}
     */
    public function cycleWindow(Host $host): array
    {
        $total = $this->pricing->cycleDays((string) $host->billingcycle) ?? 0;
        $days = max(0, (int) ceil(max(0, (int) $host->nextduedate - time()) / 86400));

        return [min($days, $total ?: $days), $total];
    }

    /**
     * Configurable option groups eligible for an upgrade, shaped the way the
     * client area expects (`_host[].sub[]`).
     */
    public function upgradeConfigOptions(Host $host, ?int $currencyId = null): array
    {
        $currencyId = $currencyId ?? $this->pricing->currencyId();
        $product = $host->product;

        if ($product === null || (int) $product->config_options_upgrade !== 1) {
            return [];
        }

        $current = DB::table('host_config_options')
            ->where('relid', $host->id)
            ->get()
            ->keyBy(fn ($row) => (int) $row->configid . ':' . (int) $row->optionid);

        $groups = $this->pricing->productConfigGroups($product);
        $out = [];

        foreach ($groups as $group) {
            /** @var ProductConfigGroup $group */
            foreach ($group->options as $option) {
                if ((int) $option->upgrade !== 1) {
                    continue;
                }

                $subs = [];

                foreach ($option->subOptions as $sub) {
                    $pricing = $this->pricing->optionPricing($sub, $currencyId);
                    $price = $pricing?->priceFor((string) $host->billingcycle);
                    $selected = $current->get((int) $option->id . ':' . (int) $sub->id);

                    $subs[] = [
                        'id' => (int) $sub->id,
                        'config_id' => (int) $option->id,
                        'option_name' => (string) $sub->option_name,
                        'option_name_first' => $this->beforeBar($sub->option_name),
                        'suboption_name' => $this->afterBar($sub->option_name),
                        'suboption_name_first' => $this->beforeBar($sub->option_name),
                        'qty_minimum' => (string) $sub->qty_minimum,
                        'qty_maximum' => (string) $sub->qty_maximum,
                        'pricing' => $price === null ? '' : $this->money($price),
                        'qty_stage' => (string) (int) $sub->qty_stage,
                        'show_pricing' => $price === null ? '' : $this->money($price),
                        'selected' => $selected !== null,
                        'selected_qty' => (int) ($selected->qty ?? 0),
                    ];
                }

                if ($subs === []) {
                    continue;
                }

                $out[] = [
                    'id' => (int) $option->id,
                    'option_name' => (string) $option->option_name,
                    'option_type' => (int) $option->option_type,
                    'unit' => (string) $option->unit,
                    'qty_stage' => (int) $option->qty_stage,
                    'qty_minimum' => (int) $option->qty_minimum,
                    'qty_maximum' => (int) $option->qty_maximum,
                    'sub' => $subs,
                ];
            }
        }

        return $out;
    }

    /**
     * Difference in configurable-option price between the current selection
     * and the requested one.
     *
     * @param  array<int|string, mixed>  $selections  configid => suboption id(s) or qty
     */
    public function configUpgradeAmount(Host $host, array $selections, ?int $currencyId = null): float
    {
        $currencyId = $currencyId ?? $this->pricing->currencyId();
        $product = $host->product;

        if ($product === null) {
            return 0.0;
        }

        $cycle = (string) $host->billingcycle;
        $currentRows = DB::table('host_config_options')->where('relid', $host->id)->get();

        $currentTotal = 0.0;
        $subs = ProductConfigOptionSub::query()
            ->whereIn('id', $currentRows->pluck('optionid')->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('id');

        foreach ($currentRows as $row) {
            $sub = $subs->get((int) $row->optionid);

            if ($sub === null) {
                continue;
            }

            $pricing = $this->pricing->optionPricing($sub, $currencyId);

            $currentTotal += ($pricing?->priceFor($cycle) ?? 0.0) * max(1, (int) $row->qty);
        }

        $newTotal = $this->pricing->configOptionsTotal($product, $selections, $cycle, $currencyId);

        $amount = $newTotal - $currentTotal;

        if ($amount < 0 && (int) Configuration::value('credit_on_downgrade', 1) !== 1) {
            return 0.0;
        }

        return PricingService::money($amount);
    }

    /**
     * Promotional codes that apply to upgrades.
     */
    public function upgradePromo(string $code): ?PromoCode
    {
        $promo = PromoCode::query()->where('code', $code)->first();

        if ($promo === null || ! $promo->isUsable()) {
            return null;
        }

        if ((int) $promo->upgrades !== 1) {
            return null;
        }

        return $promo;
    }

    /**
     * Product-group menu row (`groupn`) used by the upgrade payloads.
     */
    public function groupMenu(Product $product): array
    {
        $group = $product->group;

        return [
            'id' => (int) ($group->id ?? $product->gid),
            'groupname' => (string) ($group->name ?? ''),
            'fa_icon' => (string) ($group->headline ?? ''),
            'order' => (int) ($group->order ?? 0),
        ];
    }

    public function money(float $amount): string
    {
        return number_format(PricingService::money($amount), 2, '.', '');
    }

    protected function beforeBar(?string $value): string
    {
        $parts = explode('|', (string) $value);

        return trim($parts[0]);
    }

    protected function afterBar(?string $value): string
    {
        $parts = explode('|', (string) $value);

        return trim(end($parts));
    }
}
