<?php

namespace App\Http\Controllers\Web;

use App\Models\Currency;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductConfigOption;
use App\Models\ProductConfigOptionSub;
use App\Models\ProductGroup;
use App\Services\InvoiceService;
use App\Services\PricingService;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Products and services: list, detail, renew, upgrade / downgrade, cancel.
 *
 * The detail page multiplexes on `?action=`: the page itself renders the
 * dispatcher, and `renew`, `billing_page`, `log_page`, `flowpacket`,
 * `upgrade_page` and `upgrade_configoption_page` return HTML fragments that the
 * original injects into modals and tab panels.
 */
class ServiceController extends WebController
{
    /** Statuses the list shows when the query string names none. */
    protected const DEFAULT_STATUSES = ['Pending', 'Active', 'Suspended'];

    public function __construct(
        protected PricingService $pricing = new PricingService(),
        protected InvoiceService $invoices = new InvoiceService(),
    ) {
    }

    // -----------------------------------------------------------------
    // List
    // -----------------------------------------------------------------

    public function index(Request $request): View
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $query = $this->listQuery($request, $client->id);
        $sort = $this->orderBy($request, ['id', 'nextduedate', 'regdate', 'domain', 'domainstatus'], 'id');
        $hosts = $query->orderByRaw($sort)->paginate($limit, ['*'], 'page', $page);

        return view('web.service.index', array_merge($this->shared(), [
            'Title' => '产品与服务',
            'TplName' => 'service',
            'Service' => [
                'list' => $this->rows($hosts->items()),
                'domainstatus' => StatusMap::hostStatusOptions(),
                'groups' => $this->groups($client->id),
                'selected_status' => $this->selectedStatuses($request),
                'groupid' => (int) $request->input('groupid', 0),
                'keywords' => (string) $request->input('keywords', ''),
                'Total' => $hosts->total(),
                'Limit' => $hosts->perPage(),
                'Page' => $hosts->currentPage(),
                'Pages' => $hosts->lastPage(),
            ],
            'moduleButtons' => $this->moduleButtons(),
        ]));
    }

    // -----------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------

    public function detail(Request $request): View|string|RedirectResponse
    {
        $client = $this->requireClient();
        $action = (string) $request->input('action', '');

        $host = $this->findHost($request, $client->id);

        if ($host === null) {
            if ($request->ajax() || $action !== '') {
                return '<div class="p-6 text-sm text-slate-500">产品不存在</div>';
            }

            return redirect()->to('/service')->with('error', '产品不存在');
        }

        return match ($action) {
            'renew' => $this->renewFragment($host),
            'billing_page' => $this->billingFragment($request, $host),
            'log_page' => $this->logFragment($request, $host),
            'flowpacket' => $this->flowPacketFragment($host),
            'upgrade_page' => $this->upgradePageFragment($host),
            'upgrade_configoption_page' => $this->upgradeConfigFragment($host),
            'upgrade' => $this->submitUpgrade($request, $host),
            'upgrade_config' => $this->submitConfigUpgrade($request, $host),
            'upgrade_use_promo_code', 'upgrade_config_use_promo_code' => $this->applyUpgradePromo($request, $host),
            'upgrade_remove_promo_code', 'upgrade_config_remove_promo_code' => $this->removeUpgradePromo($request, $host),
            default => $this->detailPage($host),
        };
    }

    protected function detailPage(Host $host): View
    {
        $product = $host->product;
        $type = (string) ($product?->type ?? 'other');

        return view('web.service.detail', array_merge($this->shared(), [
            'Title' => '产品详情',
            'TplName' => 'servicedetail',
            'Detail' => [
                'host_data' => $this->hostPayload($host),
                'host' => $host,
                'product' => $product,
                'config_options' => $this->hostConfigOptions($host),
                'custom_field_data' => $this->hostCustomFields($host),
                'module_client_area' => $this->clientAreaTabs($host),
                'download_data' => $this->downloads($host),
                'module_button' => $this->buttonsFor($host),
                'cloud_os_group' => [],
                'cloud_os' => [],
                'reinstall_random_port' => false,
                'reinstall_format_data_disk' => false,
                'module_chart' => [],
                'password_rule' => ['rule' => ['len_num' => 1, 'num' => 0, 'upper' => 0, 'lower' => 0, 'special' => 0]],
            ],
            'Cancel' => [
                'host_cancel' => $this->cancelRequest($host),
                'cancelist' => DB::table('cancel_reason')->get(['id', 'reason'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'reason' => (string) $row->reason])
                    ->all(),
            ],
            'HostRecharge' => [],
            'RecordLog' => [],
            'Flowpacket' => [],
            'hostType' => $type,
        ]));
    }

    /**
     * POST /servicedetail?id=N&action=renew
     */
    public function renewSubmit(Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->findHost($request, $client->id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $cycle = (string) $request->input('billingcycles', $request->input('billingcycle', ''));

        if ($cycle === '' || $this->pricing->cyclePrice($host->product, $cycle) === null) {
            return $this->fail('请选择有效的计费周期');
        }

        $amount = (float) $this->pricing->cyclePrice($host->product, $cycle)
            + $this->pricing->setupFee($host->product, $cycle);

        $invoice = $this->invoices->create($client, [[
            'type' => 'renew',
            'rel_id' => $host->id,
            'description' => sprintf(
                '%s 续费 %s 周期：%s',
                (string) ($host->product?->name ?? '产品'),
                StatusMap::cycle($cycle),
                (string) ($host->domain ?: $host->id)
            ),
            'amount' => PricingService::money($amount),
        ]], time() + 86400 * 7, 'renew');

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok(['invoiceid' => $invoice->id, 'url' => '/viewbilling?id=' . $invoice->id], '续费账单已生成');
        }

        return redirect()->to('/viewbilling?id=' . $invoice->id)->with('success', '续费账单已生成');
    }

    /**
     * POST /host/remark
     */
    public function remark(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->findHost($request, $client->id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $host->remark = mb_substr((string) $request->input('remark', ''), 0, 500);
        $host->update_time = time();
        $host->save();

        return $this->ok(['remark' => (string) $host->remark], '备注已保存');
    }

    /**
     * POST /host/autorenew
     */
    public function autoRenew(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->findHost($request, $client->id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if (in_array((string) $host->billingcycle, ['free', 'onetime'], true)) {
            return $this->fail('该产品不支持自动续费');
        }

        $host->initiative_renew = (int) $request->input('initiative_renew', 0) === 1 ? 1 : 0;
        $host->update_time = time();
        $host->save();

        return $this->ok(
            ['initiative_renew' => (int) $host->initiative_renew],
            $host->initiative_renew ? '已开启自动续费' : '已关闭自动续费'
        );
    }

    /**
     * DELETE /host/cancel — request cancellation of a service.
     */
    public function cancel(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->findHost($request, $client->id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if ((int) ($host->product?->cancel_control ?? 0) !== 1) {
            return $this->fail('该产品不支持自助取消');
        }

        $type = (string) $request->input('type', 'Immediate');
        $reason = mb_substr((string) $request->input('reason', ''), 0, 500);

        DB::table('cancel_requests')->insert([
            'relid' => (int) $host->id,
            'type' => $type,
            'reason' => $reason,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
            'status' => 0,
        ]);

        if ($type === 'Immediate') {
            $host->markAs(Host::STATUS_CANCELLED);
        }

        return $this->ok(null, '取消申请已提交');
    }

    /**
     * POST /host/hostrecharge — transactions recorded against one service.
     */
    public function hostRecharge(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $hostId = (int) $request->input('hostid', 0);

        $rows = DB::table('accounts')
            ->where('uid', $client->id)
            ->where('invoice_id', '>', 0)
            ->when($hostId > 0, function ($query) use ($hostId) {
                $query->whereIn('invoice_id', function ($sub) use ($hostId) {
                    $sub->select('invoice_id')->from('invoice_items')->where('rel_id', $hostId);
                });
            })
            ->orderByDesc('pay_time')
            ->limit(50)
            ->get();

        return $this->ok([
            'invoices' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'pay_time' => (int) $row->pay_time,
                'type' => (float) $row->amount_in > 0 ? '收入' : '支出',
                'amount_in' => number_format((float) $row->amount_in, 2, '.', ''),
                'amount_out' => number_format((float) $row->amount_out, 2, '.', ''),
                'trans_id' => (string) $row->trans_id,
                'gateway' => (string) $row->gateway,
                'description' => (string) $row->description,
            ])->all(),
        ]);
    }

    /**
     * GET /host/trafficusage — traffic series for the detail chart.
     */
    public function trafficUsage(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->findHost($request, $client->id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $start = (int) $request->input('start', strtotime('-30 days'));
        $end = (int) $request->input('end', time());

        // No traffic history table exists in the mirrored schema; the module
        // supplies the series when it is available, otherwise the current
        // counters are reported as a flat series.
        $days = max(1, (int) ceil(($end - $start) / 86400));
        $points = [];

        for ($i = 0; $i < $days; $i++) {
            $points[] = [
                'date' => date('Y-m-d', $start + ($i * 86400)),
                'value' => 0,
            ];
        }

        return $this->ok([
            'list' => $points,
            'bwlimit' => (float) $host->bwlimit,
            'bwusage' => (float) $host->bwusage,
            'disklimit' => (int) $host->disklimit,
            'diskusage' => (int) $host->diskusage,
        ]);
    }

    // -----------------------------------------------------------------
    // Batch renew
    // -----------------------------------------------------------------

    /**
     * GET|POST /mulitrenew — batch renewal for the checked services.
     */
    public function multiRenew(Request $request): View|RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('host_ids', []))));

        if ($ids === []) {
            return redirect()->to('/service')->with('error', '请选择需要续费的产品');
        }

        $hosts = Host::query()->where('uid', $client->id)->whereIn('id', $ids)->get();

        if ($hosts->isEmpty()) {
            return redirect()->to('/service')->with('error', '产品不存在');
        }

        if ($request->isMethod('post')) {
            return $this->submitMultiRenew($request, $hosts);
        }

        return view('web.service.mulitrenew', array_merge($this->shared(), [
            'Title' => '批量续费',
            'TplName' => 'mulitrenew',
            'Renew' => [
                'hosts' => $hosts->map(fn (Host $host) => [
                    'host' => $host,
                    'allow_billingcycle' => $this->renewCycles($host),
                    'nextduedate_renew' => (int) $host->nextduedate,
                ])->all(),
                'currency' => $this->currencyPayload(),
            ],
            'Total' => 0,
        ]));
    }

    protected function submitMultiRenew(Request $request, $hosts): RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $cycles = (array) $request->input('cycles', []);
        $items = [];

        foreach ($hosts as $host) {
            $cycle = (string) ($cycles[$host->id] ?? '');

            if ($cycle === '' || $this->pricing->cyclePrice($host->product, $cycle) === null) {
                continue;
            }

            $amount = (float) $this->pricing->cyclePrice($host->product, $cycle)
                + $this->pricing->setupFee($host->product, $cycle);

            $items[] = [
                'type' => 'renew',
                'rel_id' => $host->id,
                'description' => sprintf(
                    '%s 续费 %s 周期：%s',
                    (string) ($host->product?->name ?? '产品'),
                    StatusMap::cycle($cycle),
                    (string) ($host->domain ?: $host->id)
                ),
                'amount' => PricingService::money($amount),
            ];
        }

        if ($items === []) {
            return $this->fail('请为至少一个产品选择计费周期');
        }

        $invoice = $this->invoices->create($client, $items, time() + 86400 * 7, 'renew');

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok(['invoiceid' => $invoice->id], '续费账单已生成');
        }

        return redirect()->to('/viewbilling?id=' . $invoice->id)->with('success', '续费账单已生成');
    }

    /**
     * POST /host/batchrenewpage — recalculate due dates for a cycle change.
     */
    public function batchRenewPage(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $cycles = (array) $request->input('cycles', []);
        $hosts = [];

        foreach ((array) $request->input('host_ids', []) as $index => $hostId) {
            $host = Host::query()->where('uid', $client->id)->find((int) $hostId);

            if ($host === null) {
                continue;
            }

            $cycle = (string) ($cycles[$host->id] ?? $host->billingcycle);
            $next = $this->pricing->nextDueDate($cycle, (int) $host->nextduedate ?: null);

            $hosts[] = [
                'id' => (int) $host->id,
                'hostid' => (int) $host->id,
                'nextduedate_renew' => $next ?? (int) $host->nextduedate,
                'saleproducts' => number_format((float) $this->pricing->cyclePrice($host->product, $cycle), 2, '.', ''),
            ];
        }

        return $this->ok([
            'hosts' => $hosts,
            'total' => number_format(array_sum(array_map(fn ($h) => (float) $h['saleproducts'], $hosts)), 2, '.', ''),
        ]);
    }

    // -----------------------------------------------------------------
    // Fragments
    // -----------------------------------------------------------------

    protected function renewFragment(Host $host): string
    {
        return $this->fragment('web.service._renew', [
            'host' => $host,
            'Renew' => [
                'cycle' => $this->renewCycles($host),
                'currency' => $this->currencyPayload(),
            ],
        ]);
    }

    protected function billingFragment(Request $request, Host $host): string
    {
        [$page, $limit] = $this->pager($request, 10);
        $client = $this->requireClient();

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->whereIn('id', function ($query) use ($host) {
                $query->select('invoice_id')->from('invoice_items')->where('rel_id', $host->id);
            })
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->fragment('web.service._billing', [
            'host' => $host,
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => (int) $invoice->id,
                'subtotal' => number_format((float) $invoice->total, 2, '.', ''),
                'create_time' => (int) $invoice->create_time,
                'due_time' => (int) $invoice->due_time,
                'status' => (string) $invoice->status,
                'status_zh' => StatusMap::invoiceStatus((string) $invoice->status),
                'status_color' => StatusMap::invoiceStatusColor((string) $invoice->status),
            ])->all(),
            'Total' => $invoices->total(),
            'Pages' => $invoices->lastPage(),
            'Page' => $invoices->currentPage(),
            'Limit' => $invoices->perPage(),
        ]);
    }

    protected function logFragment(Request $request, Host $host): string
    {
        [$page, $limit] = $this->pager($request, 10);

        $logs = DB::table('activity_log_home')
            ->where('activeid', $host->id)
            ->orderByDesc('create_time')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->fragment('web.service._log', [
            'host' => $host,
            'logs' => $logs->map(fn ($row) => [
                'create_time' => (int) $row->create_time,
                'description' => (string) $row->description,
                'user' => (string) $row->user,
                'ipaddr' => (string) $row->ipaddr,
            ])->all(),
            'Total' => $logs->total(),
            'Pages' => $logs->lastPage(),
            'Page' => $logs->currentPage(),
            'Limit' => $logs->perPage(),
        ]);
    }

    protected function flowPacketFragment(Host $host): string
    {
        $packets = DB::table('dcim_flow_packet')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) ($row->name ?? ''),
                'capacity' => (string) ($row->capacity ?? ''),
                'price' => (float) ($row->price ?? 0),
                'leave' => 0,
            ])
            ->all();

        return $this->fragment('web.service._flowpacket', [
            'host' => $host,
            'Flowpacket' => $packets,
            'currency' => $this->currencyPayload(),
        ]);
    }

    protected function upgradePageFragment(Host $host): string
    {
        if ((int) ($host->product?->config_options_upgrade ?? 0) === 0) {
            return '<div class="p-6 text-sm text-slate-500">该产品不支持升级</div>';
        }

        $upgrades = DB::table('product_upgrade_products')
            ->where('product_id', $host->productid)
            ->pluck('upgrade_product_id')
            ->all();

        $products = Product::query()
            ->whereIn('id', $upgrades)
            ->where('hidden', 0)
            ->get()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'cycle' => $this->renewCycles($host),
            ])
            ->all();

        return $this->fragment('web.service._upgrade', [
            'host' => $host,
            'products' => $products,
            'currency' => $this->currencyPayload(),
        ]);
    }

    protected function upgradeConfigFragment(Host $host): string
    {
        return $this->fragment('web.service._upgrade_config', [
            'host' => $host,
            'options' => $this->hostConfigOptions($host, true),
            'currency' => $this->currencyPayload(),
        ]);
    }

    /**
     * POST /servicedetail?id=N&action=upgrade — step one of the product upgrade.
     */
    protected function submitUpgrade(Request $request, Host $host): string
    {
        $targetId = (int) $request->input('upgrade_product_id', 0);
        $cycle = (string) $request->input('billingcycle', $host->billingcycle);
        $product = Product::query()->find($targetId);

        if ($product === null) {
            return '<div class="alert alert-danger">请选择要升级的产品</div>';
        }

        $current = (float) $this->pricing->cyclePrice($host->product, $cycle);
        $target = (float) $this->pricing->cyclePrice($product, $cycle);
        $difference = PricingService::money(max(0, $target - $current));

        return $this->fragment('web.service._upgrade_confirm', [
            'host' => $host,
            'product' => $product,
            'cycle' => $cycle,
            'current_amount' => PricingService::money($current),
            'target_amount' => PricingService::money($target),
            'difference' => $difference,
            'currency' => $this->currencyPayload(),
            'promo' => session('upgrade_promo.' . $host->id),
        ]);
    }

    /**
     * POST /servicedetail?id=N&action=upgrade_config
     */
    protected function submitConfigUpgrade(Request $request, Host $host): string
    {
        $selections = (array) $request->input('configoption', []);
        $cycle = (string) $host->billingcycle;
        $total = $this->pricing->configOptionsTotal($host->product, $this->normaliseSelections($selections), $cycle);

        return $this->fragment('web.service._upgrade_config_confirm', [
            'host' => $host,
            'selections' => $selections,
            'total' => PricingService::money($total),
            'currency' => $this->currencyPayload(),
            'promo' => session('upgrade_promo.' . $host->id),
        ]);
    }

    protected function applyUpgradePromo(Request $request, Host $host): string
    {
        $code = (string) $request->input('promo_code', $request->input('pormo_code', ''));
        $promo = (new \App\Services\CartService())->findPromo($code);

        if ($promo === null) {
            return '<div class="alert alert-danger">优惠码无效或已过期</div>';
        }

        session(['upgrade_promo.' . $host->id => [
            'code' => (string) $promo->code,
            'type' => (string) $promo->type,
            'value' => (float) $promo->value,
        ]]);

        return $this->fragment('web.service._upgrade_confirm', [
            'host' => $host,
            'product' => $host->product,
            'cycle' => (string) $host->billingcycle,
            'current_amount' => number_format((float) $host->amount, 2, '.', ''),
            'target_amount' => number_format((float) $host->amount, 2, '.', ''),
            'difference' => number_format(max(0, $promo->discountFor((float) $host->amount)), 2, '.', ''),
            'currency' => $this->currencyPayload(),
            'promo' => session('upgrade_promo.' . $host->id),
        ]);
    }

    protected function removeUpgradePromo(Request $request, Host $host): string
    {
        session()->forget('upgrade_promo.' . $host->id);

        return $this->submitUpgrade($request, $host);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function listQuery(Request $request, int $clientId)
    {
        $statuses = $this->selectedStatuses($request);

        return Host::query()
            ->where('host.uid', $clientId)
            ->when($statuses !== [], fn ($query) => $query->whereIn('host.domainstatus', $statuses))
            ->when($request->filled('groupid'), fn ($query) => $query->whereIn(
                'host.productid',
                Product::query()->where('gid', (int) $request->input('groupid'))->select('id')
            ))
            ->when(trim((string) $request->input('keywords', '')) !== '', function ($query) use ($request) {
                $keywords = '%' . trim((string) $request->input('keywords')) . '%';
                $query->where(function ($sub) use ($keywords) {
                    $sub->where('host.domain', 'like', $keywords)
                        ->orWhere('host.dedicatedip', 'like', $keywords)
                        ->orWhere('host.id', 'like', $keywords)
                        ->orWhereIn('host.productid', Product::query()->where('name', 'like', $keywords)->select('id'));
                });
            });
    }

    /**
     * @return array<int, string>
     */
    protected function selectedStatuses(Request $request): array
    {
        $raw = $request->input('domain_status', $request->input('domain_status', []));

        if (is_string($raw)) {
            $raw = [$raw];
        }

        $valid = array_keys(StatusMap::HOST_STATUS);
        $statuses = array_values(array_intersect(array_map('strval', (array) $raw), $valid));

        return $statuses === [] ? self::DEFAULT_STATUSES : $statuses;
    }

    /**
     * Resolve the host addressed by `id` (also accepts `hid`), scoped to the
     * signed-in client.
     */
    protected function findHost(Request $request, int $clientId): ?Host
    {
        $id = (int) $request->input('id', $request->input('hid', 0));

        if ($id <= 0) {
            return null;
        }

        return Host::query()->where('uid', $clientId)->find($id);
    }

    /**
     * Cycles a service may be renewed on, with prices.
     */
    protected function renewCycles(Host $host): array
    {
        $product = $host->product;

        if ($product === null) {
            return [];
        }

        $cycles = [];

        foreach ($this->pricing->availableCycles($product) as $cycle) {
            if (in_array($cycle, ['free', 'ontrial'], true)) {
                continue;
            }

            $price = (float) $this->pricing->cyclePrice($product, $cycle);
            $setup = $this->pricing->setupFee($product, $cycle);

            $cycles[] = [
                'billingcycle' => $cycle,
                'billingcycle_zh' => StatusMap::cycle($cycle),
                'amount' => number_format($price + $setup, 2, '.', ''),
            ];
        }

        return $cycles;
    }

    /**
     * Decorate the list rows.
     *
     * @param  array<int, Host>  $hosts
     */
    protected function rows(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', array_filter(array_map(fn (Host $host) => $host->productid, $hosts)))
            ->get()
            ->keyBy('id');

        $cancels = DB::table('cancel_requests')
            ->whereIn('relid', array_map(fn (Host $host) => $host->id, $hosts))
            ->where('delete_time', 0)
            ->get()
            ->keyBy('relid');

        $rows = [];

        foreach ($hosts as $host) {
            $product = $products[$host->productid] ?? null;
            $cycle = (string) $host->billingcycle;
            $cancel = $cancels[$host->id] ?? null;

            $rows[] = [
                'id' => (int) $host->id,
                'productname' => (string) ($product?->name ?? '未知产品'),
                'type' => (string) ($product?->type ?? 'other'),
                'domain' => (string) $host->domain,
                'domainstatus' => (string) $host->domainstatus,
                'domainstatus_desc' => StatusMap::hostStatus((string) $host->domainstatus, (string) ($product?->type ?? '')),
                'domainstatus_color' => StatusMap::hostStatusColor((string) $host->domainstatus),
                'dedicatedip' => (string) $host->dedicatedip,
                'assignedips' => array_values(array_filter(preg_split('/[\r\n,]+/', (string) $host->assignedips) ?: [])),
                'nextduedate' => (int) $host->nextduedate,
                'billingcycle' => $cycle,
                'cycle_desc' => StatusMap::cycle($cycle),
                'cycle_short' => StatusMap::cycleShort($cycle),
                'price_desc' => number_format((float) $host->amount, 2, '.', ''),
                'initiative_renew' => (int) $host->initiative_renew,
                'notes' => (string) $host->remark,
                'os' => (string) $host->os,
                'os_url' => (string) $host->os_url,
                'host_cancel' => $cancel === null ? '' : [
                    'type' => StatusMap::CANCEL_TYPE[(string) $cancel->type] ?? (string) $cancel->type,
                    'reason' => (string) $cancel->reason,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Product groups the client owns services in.
     */
    protected function groups(int $clientId): array
    {
        $gids = Host::query()
            ->where('host.uid', $clientId)
            ->join('products', 'products.id', '=', 'host.productid')
            ->distinct()
            ->pluck('products.gid')
            ->all();

        if ($gids === []) {
            return [];
        }

        return ProductGroup::query()
            ->whereIn('id', $gids)
            ->orderBy('order')
            ->get()
            ->map(fn (ProductGroup $group) => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->all();
    }

    /**
     * The `$Detail.host_data` payload the detail templates read.
     */
    protected function hostPayload(Host $host): array
    {
        $product = $host->product;
        $cycle = (string) $host->billingcycle;
        $type = (string) ($product?->type ?? 'other');

        return [
            'id' => (int) $host->id,
            'productname' => (string) ($product?->name ?? '未知产品'),
            'domain' => (string) $host->domain,
            'remark' => (string) $host->remark,
            'domainstatus' => (string) $host->domainstatus,
            'domainstatus_desc' => StatusMap::hostStatus((string) $host->domainstatus, $type),
            'domainstatus_color' => StatusMap::hostStatusColor((string) $host->domainstatus),
            'firstpaymentamount_desc' => number_format((float) $host->firstpaymentamount, 2, '.', ''),
            'regdate' => (int) $host->regdate,
            'billingcycle' => $cycle,
            'billingcycle_desc' => StatusMap::cycle($cycle),
            'cycle_desc' => StatusMap::cycleShort($cycle),
            'nextduedate' => (int) $host->nextduedate,
            'price_desc' => number_format((float) $host->amount, 2, '.', ''),
            'initiative_renew' => (int) $host->initiative_renew,
            'type' => $type,
            'username' => (string) $host->username,
            'password' => (string) $host->password,
            'port' => (int) $host->port,
            'dedicatedip' => (string) $host->dedicatedip,
            'assignedips' => array_values(array_filter(preg_split('/[\r\n,]+/', (string) $host->assignedips) ?: [])),
            'os' => (string) $host->os,
            'os_url' => (string) $host->os_url,
            'bwlimit' => (float) $host->bwlimit,
            'bwusage' => (float) $host->bwusage,
            'disklimit' => (int) $host->disklimit,
            'diskusage' => (int) $host->diskusage,
            'allow_upgrade_config' => (int) ($product?->config_options_upgrade ?? 0) === 1,
            'allow_upgrade_product' => DB::table('product_upgrade_products')->where('product_id', $host->productid)->exists(),
            'cancel_control' => (int) ($product?->cancel_control ?? 0),
            'format_nextduedate' => $this->dueDateHint($host),
        ];
    }

    /**
     * Colour + wording for the "next due" cell.
     */
    protected function dueDateHint(Host $host): array
    {
        $due = (int) $host->nextduedate;

        if ($due === 0 || in_array((string) $host->billingcycle, ['free', 'onetime'], true)) {
            return ['class' => 'text-slate-400', 'msg' => '—'];
        }

        $days = (int) floor(($due - time()) / 86400);

        return match (true) {
            $days < 0 => ['class' => 'text-rose-600 font-medium', 'msg' => '已逾期'],
            $days <= 7 => ['class' => 'text-orange-600 font-medium', 'msg' => "{$days} 天后到期"],
            default => ['class' => 'text-slate-600', 'msg' => "{$days} 天后到期"],
        };
    }

    /**
     * Selected configurable options on a service.
     */
    protected function hostConfigOptions(Host $host, bool $upgradableOnly = false): array
    {
        $selections = DB::table('host_config_options')->where('relid', $host->id)->get();

        if ($selections->isEmpty()) {
            return [];
        }

        $options = ProductConfigOption::query()
            ->whereIn('id', $selections->pluck('configid')->all())
            ->when($upgradableOnly, fn ($query) => $query->where('upgrade', 1))
            ->get()
            ->keyBy('id');

        $subs = ProductConfigOptionSub::query()
            ->whereIn('id', $selections->pluck('optionid')->all())
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($selections as $selection) {
            $option = $options[$selection->configid] ?? null;

            if ($option === null) {
                continue;
            }

            $sub = $subs[$selection->optionid] ?? null;

            $rows[] = [
                'configid' => (int) $option->id,
                'optionid' => (int) $selection->optionid,
                'qty' => (int) $selection->qty,
                'name' => (string) $option->option_name,
                'option_name' => (string) $option->option_name,
                'sub_name' => (string) ($sub?->option_name ?? ''),
                'option_type' => (int) $option->option_type,
                'unit' => (string) $option->unit,
                'qty_minimum' => (int) $option->qty_minimum,
                'qty_maximum' => (int) $option->qty_maximum,
                'qty_stage' => (int) $option->qty_stage,
                'sub_options' => $this->subOptions($host, $option),
            ];
        }

        return $rows;
    }

    /**
     * Choices available for one configurable option, priced for this service's
     * billing cycle.
     */
    protected function subOptions(Host $host, ProductConfigOption $option): array
    {
        $cycle = (string) $host->billingcycle;
        $currencyId = Currency::default()?->id;

        return $option->subOptions()
            ->where('hidden', 0)
            ->get()
            ->map(function (ProductConfigOptionSub $sub) use ($cycle, $currencyId) {
                $pricing = $this->pricing->optionPricing($sub, $currencyId);

                return [
                    'id' => (int) $sub->id,
                    'option_name' => (string) $sub->option_name,
                    'price' => number_format((float) ($pricing?->priceFor($cycle) ?? 0), 2, '.', ''),
                ];
            })
            ->all();
    }

    /**
     * Custom field values shown on the detail page.
     */
    protected function hostCustomFields(Host $host): array
    {
        $values = DB::table('customfieldsvalues')
            ->where('relid', $host->id)
            ->get();

        if ($values->isEmpty()) {
            return [];
        }

        $fields = DB::table('customfields')
            ->whereIn('id', $values->pluck('fieldid'))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($values as $value) {
            $field = $fields[$value->fieldid] ?? null;

            if ($field === null || (int) $field->showdetail !== 1) {
                continue;
            }

            $rows[] = [
                'fieldname' => (string) $field->fieldname,
                'value' => (string) $value->value,
                'showdetail' => 1,
            ];
        }

        return $rows;
    }

    /**
     * Module-supplied tabs on the detail page.
     */
    protected function clientAreaTabs(Host $host): array
    {
        $module = (string) ($host->product?->server_type ?? '');

        if ($module === '') {
            return [];
        }

        return [];
    }

    /**
     * Downloads attached to a service's product.
     */
    protected function downloads(Host $host): array
    {
        $ids = DB::table('product_downloads')
            ->where('product_id', $host->productid)
            ->pluck('download_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return DB::table('downloads')
            ->whereIn('id', $ids)
            ->where('hidden', 0)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'down_link' => (string) ($row->url ?: $row->location),
                'create_time' => (int) $row->create_time,
                'downloads' => (int) $row->downloads,
                'type' => is_numeric($row->type) ? (int) $row->type : $this->guessDownloadType((string) $row->type),
            ])
            ->all();
    }

    protected function guessDownloadType(string $type): int
    {
        return match (strtolower($type)) {
            'zip', 'rar', 'gz' => 1,
            'png', 'jpg', 'jpeg', 'gif', 'image' => 2,
            default => 3,
        };
    }

    /**
     * The button sets shown on the detail page, grouped the way the original
     * splits them (`control` vs `console`).
     */
    protected function buttonsFor(Host $host): array
    {
        $buttons = $this->moduleButtons();
        $type = (string) ($host->product?->type ?? 'other');

        $usable = array_values(array_filter($buttons, function (array $button) use ($host, $type) {
            return ! ($button['free_product_only'] ?? false)
                || in_array($type, ['software', 'other'], true);
        }));

        return [
            'control' => array_values(array_filter($usable, fn ($b) => ($b['group'] ?? 'control') === 'control')),
            'console' => array_values(array_filter($usable, fn ($b) => ($b['group'] ?? 'control') === 'console')),
        ];
    }

    /**
     * The generic module button set, mirroring `$Detail.module_button`.
     */
    protected function moduleButtons(): array
    {
        return [
            ['func' => 'on', 'type' => 'default', 'name' => '开机', 'desc' => '启动服务器', 'group' => 'control', 'sensitive' => false],
            ['func' => 'off', 'type' => 'default', 'name' => '关机', 'desc' => '关闭服务器', 'group' => 'control', 'sensitive' => false],
            ['func' => 'reboot', 'type' => 'default', 'name' => '重启', 'desc' => '重启服务器', 'group' => 'control', 'sensitive' => false],
            ['func' => 'hard_off', 'type' => 'default', 'name' => '硬关机', 'desc' => '强制关闭服务器', 'group' => 'control', 'sensitive' => false],
            ['func' => 'hard_reboot', 'type' => 'default', 'name' => '硬重启', 'desc' => '强制重启服务器', 'group' => 'control', 'sensitive' => false],
            ['func' => 'crack_pass', 'type' => 'default', 'name' => '重置密码', 'desc' => '重置服务器密码', 'group' => 'console', 'sensitive' => true],
            ['func' => 'reinstall', 'type' => 'default', 'name' => '重装系统', 'desc' => '重装操作系统', 'group' => 'console', 'sensitive' => true],
            ['func' => 'vnc', 'type' => 'default', 'name' => 'VNC', 'desc' => '打开控制台', 'group' => 'console', 'sensitive' => false],
            ['func' => 'rescue', 'type' => 'default', 'name' => '救援模式', 'desc' => '进入救援模式', 'group' => 'console', 'sensitive' => true],
            ['func' => 'resetLicense', 'type' => 'custom', 'name' => '重置授权', 'desc' => '重置授权信息', 'group' => 'console', 'sensitive' => false, 'free_product_only' => true],
        ];
    }

    /**
     * Pending cancellation request attached to a service.
     */
    protected function cancelRequest(Host $host): array
    {
        $row = DB::table('cancel_requests')
            ->where('relid', $host->id)
            ->where('delete_time', 0)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return [];
        }

        return [
            'type' => StatusMap::CANCEL_TYPE[(string) $row->type] ?? (string) $row->type,
            'reason' => (string) $row->reason,
            'status' => StatusMap::CANCEL_REQUEST_STATUS[(int) $row->status] ?? '',
        ];
    }

    /**
     * Normalise `configoption[<id>]` input into the shape PricingService wants.
     */
    protected function normaliseSelections(array $selections): array
    {
        $normalised = [];

        foreach ($selections as $optionId => $value) {
            $normalised[] = [
                'option' => (int) $optionId,
                'value' => $value,
                'qty' => is_array($value) ? (int) ($value['qty'] ?? 1) : 1,
            ];
        }

        return $normalised;
    }
}
