<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\TicketDepartment;
use App\Models\Upgrade;
use App\Services\HostService;
use App\Services\ModuleService;
use App\Services\PricingService;
use App\Services\RenewalService;
use App\Services\VerifyCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Client services (`shd_host`): listing, detail, renewal, cancellation,
 * upgrades (product and configurable option) and the provisioning module
 * actions.
 *
 * Field names follow the original `/v1` payloads (`_host`, `_cycle`,
 * `_currency`, `_host_cancel`, `_second_verify`) so downstream clients render
 * them unchanged.
 */
class HostController extends ApiController
{
    /** Pending in-progress upgrade selections, keyed by client+host. */
    protected const UPGRADE_CACHE_TTL = 1800;

    /** Power states the module status call may report. */
    protected const POWER_STATES = [
        'on' => '运行中',
        'off' => '已关机',
        'unknown' => '未知',
        'process' => '处理中',
        'waiting' => '等待中',
        'suspend' => '已暂停',
        'wait_reboot' => '等待重启',
        'wait' => '等待中',
        'cold_migrate' => '冷迁移中',
        'hot_migrate' => '热迁移中',
    ];

    public function __construct(
        protected HostService $hosts = new HostService(),
        protected ModuleService $modules = new ModuleService(),
        protected RenewalService $renewals = new RenewalService(),
        protected PricingService $pricing = new PricingService(),
        protected VerifyCodeService $codes = new VerifyCodeService(),
    ) {
    }

    /* ---------------------------------------------------------------------
     | Listing
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/hosts — paginated service list.
     */
    public function index(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $statuses = $this->requestedStatuses($request);

        $query = Host::query()
            ->where('uid', $client->id)
            ->with(['product.group']);

        if ($request->filled('cate_id')) {
            $cateId = (int) $request->input('cate_id');
            $query->whereHas('product', fn ($sub) => $sub->where('gid', $cateId));
        }

        if ($statuses !== []) {
            $query->whereIn('domainstatus', $statuses);
        }

        if ($request->filled('groupid')) {
            $query->whereHas('product', fn ($sub) => $sub->where('gid', (int) $request->input('groupid')));
        }

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where(function ($sub) use ($keywords) {
                $sub->where('domain', 'like', '%' . $keywords . '%')
                    ->orWhere('dedicatedip', 'like', '%' . $keywords . '%')
                    ->orWhere('id', $keywords)
                    ->orWhereHas('product', fn ($q) => $q->where('name', 'like', '%' . $keywords . '%'));
            });
        }

        $paginator = $this->applyListQuery($query, $request, [], 'id')->paginate($limit, ['*'], 'page', $page);

        return $this->paginated($paginator, fn (Host $host) => $this->listPayload($host), [
            'domainstatus' => array_values(array_unique(array_merge(
                $statuses === [] ? HostService::DEFAULT_STATUSES : $statuses,
                HostService::DEFAULT_STATUSES
            ))),
        ]);
    }

    /**
     * GET /v1/hosts/cates — product groups the client owns services in.
     */
    public function cates(Request $request)
    {
        $client = $this->requireClient($request);

        return $this->ok(['cate' => $this->hosts->categories($client)]);
    }

    /**
     * GET /v1/hosts/{id} — service detail.
     */
    public function show(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $currency = $this->currency();
        $product = $host->product;
        $server = $this->modules->resolveServer($host);

        $detail = [
            'id' => (int) $host->id,
            'type' => (string) ($product->type ?? 'other'),
            'domain' => (string) $host->domain,
            'domainstatus' => (string) $host->domainstatus,
            'domainstatus_desc' => HostService::statusLabel($host->domainstatus),
            'initiative_renew' => (int) $host->initiative_renew,
            'product_id' => (int) $host->productid,
            'product_name' => $this->hosts->productName($host),
            'productname' => $this->hosts->productName($host),
            'regdate' => (int) $host->regdate,
            'payment' => (string) $host->payment,
            'group_id' => (int) ($product->gid ?? 0),
            'group_name' => $this->hosts->groupName($host),
            'firstpaymentamount' => $this->hosts->money((float) $host->firstpaymentamount),
            'amount' => $this->hosts->money((float) $host->amount),
            'billingcycle' => (string) $host->billingcycle,
            'billingcycle_desc' => HostService::cycleShort((string) $host->billingcycle),
            'nextduedate' => (int) $host->nextduedate,
            'nextinvoicedate' => (int) $host->nextinvoicedate,
            'dedicatedip' => (string) $host->dedicatedip,
            'assignedips' => $this->splitIps((string) $host->assignedips),
            'username' => (string) $host->username,
            'password' => (string) $host->password,
            'suspend_type' => (string) $host->domainstatus === Host::STATUS_SUSPENDED ? 'Suspend' : '',
            'suspend_reason' => (string) $host->suspendreason,
            'bwusage' => (float) $host->bwusage,
            'bwlimit' => (int) $host->bwlimit,
            'os' => (string) $host->os,
            'os_url' => (string) $host->os_url,
            'remark' => (string) $host->remark,
            'notes' => (string) $host->notes,
            'port' => (int) $host->port,
            'config_options_upgrade' => (int) ($product->config_options_upgrade ?? 0),
            'billing_cycle_upgrade' => (string) ($product->billing_cycle_upgrade ?? ''),
            'cancel_control' => (int) ($product->cancel_control ?? 0),
            'ip_num' => count($this->splitIps((string) $host->assignedips)),
            'allow_upgrade_config' => (int) ($product->config_options_upgrade ?? 0) === 1,
            'allow_upgrade_product' => $this->hosts->upgradeTargets($host) !== [],
            'server' => $server === null ? null : [
                'id' => (int) $server->id,
                'name' => (string) $server->name,
                'hostname' => (string) $server->hostname,
                'ip_address' => (string) $server->ip_address,
                'module' => $this->modules->moduleNameFor($host),
            ],
        ];

        $cancel = DB::table('cancel_requests')
            ->where('relid', $host->id)
            ->where('type', 'host')
            ->where('delete_time', 0)
            ->orderByDesc('id')
            ->first();

        return $this->ok([
            'host' => $detail,
            'config_options' => $this->hosts->configOptions($host),
            'custom_fields' => $this->hosts->customFields($host),
            'module_client_area' => $this->hosts->clientArea($host),
            'download' => $this->hosts->hostDownloads($host),
            'host_cancel' => $cancel === null ? null : [
                'type' => (string) $cancel->type === 'host' ? (string) $cancel->reason : (string) $cancel->type,
                'reason' => (string) $cancel->reason,
                'create_time' => (int) $cancel->create_time,
                'status' => (int) $cancel->status,
            ],
            'module_button' => $this->moduleButtons($host),
            'currency' => $this->hosts->currencyPayload(),
        ]);
    }

    /**
     * GET /v1/hosts/{id}/logs — client-visible activity for one service.
     */
    public function logs(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        [$page, $limit] = $this->pagination($request);

        // Activity rows record the host id in `activeid`; module queue rows are
        // folded in so module-driven actions show up too.
        $activity = DB::table('activity_log')
            ->where('activeid', $host->id)
            ->select([
                'id',
                'uid',
                'user',
                'description',
                'ipaddr',
                'port',
                'create_time',
                DB::raw("'activity' as source"),
            ]);

        $queue = DB::table('module_queue')
            ->where('service_id', $host->id)
            ->select([
                'id',
                'service_id as uid',
                DB::raw("'' as user"),
                DB::raw("module_action as description"),
                DB::raw("'' as ipaddr"),
                DB::raw('0 as port'),
                'create_time',
                DB::raw("'module' as source"),
            ]);

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $activity->where('description', 'like', '%' . $keywords . '%');
            $queue->where('module_action', 'like', '%' . $keywords . '%');
        }

        $union = $queue->unionAll($activity);

        $total = DB::query()->fromSub($union, 'l')->count();

        $rows = DB::query()
            ->fromSub($union, 'l')
            ->orderByDesc('create_time')
            ->orderByDesc('id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return $this->ok([
            'list' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'uid' => (int) $host->uid,
                'user' => (string) ($row->user ?: $client->username),
                'description' => (string) $row->description,
                'ipaddr' => (string) $row->ipaddr,
                'port' => (int) $row->port,
                'create_time' => (int) $row->create_time,
                'type' => (string) $row->source,
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_page' => (int) ceil($total / max(1, $limit)),
            'currency' => $this->hosts->currencyPayload(),
        ]);
    }

    /**
     * GET /v1/hosts/{id}/downloads — files attached to the product.
     */
    public function downloads(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        return $this->ok(['download' => $this->hosts->hostDownloads($host)]);
    }

    /**
     * GET /v1/hosts/{id}/downloads/{download} — fetch one file.
     */
    public function downloadFile(Request $request, int $id, int $download)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $file = collect($this->hosts->hostDownloads($host))->firstWhere('id', $download);

        if ($file === null) {
            return $this->fail('文件不存在');
        }

        $path = $file['location'] !== '' ? $file['location'] : $file['url'];

        if ($path === '' || ! is_file(public_path($path))) {
            return $this->fail('文件不存在或已被删除');
        }

        \App\Models\Download::query()->where('id', $download)->increment('downloads');

        return $this->ok([
            'url' => url($path),
            'name' => $file['name'],
        ], '获取成功');
    }

    /* ---------------------------------------------------------------------
     | Renewal
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/hosts/{id}/renew — renewal cycles and prices.
     */
    public function renewPage(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if (! $this->renewable($host)) {
            return $this->fail('该产品不支持续费');
        }

        return $this->ok($this->renewals->page($host, (string) $request->input('billingcycle', '')));
    }

    /**
     * POST /v1/hosts/{id}/renew — create the renewal invoice.
     */
    public function renew(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if (! $this->renewable($host)) {
            return $this->fail('该产品不支持续费');
        }

        $cycle = (string) $request->input('billingcycle', $host->billingcycle);

        if (! in_array($cycle, $this->allowedCycles($host), true)) {
            return $this->fail('该产品不支持所选计费周期');
        }

        try {
            $result = $this->renewals->renew($host, $cycle, $client);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok([
            'invoiceid' => (int) $result['invoice']->id,
            'payment' => (string) ($host->payment ?: Configuration::value('payment_default', '')),
            'amount' => $this->hosts->money($result['amount']),
        ], '续费账单已生成');
    }

    /**
     * PUT /v1/hosts/{id}/renew — automatic balance renewal toggle.
     */
    public function renewAuto(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if (! $request->has('initiative_renew')) {
            return $this->fail('缺少参数 initiative_renew', 406);
        }

        $this->renewals->setAutoRenew($host, (int) $request->input('initiative_renew'));

        return $this->ok([
            'id' => (int) $host->id,
            'initiative_renew' => (int) $host->initiative_renew,
        ], '设置成功');
    }

    /**
     * GET /v1/hosts/renew/batch — batch renewal page.
     */
    public function renewBatchPage(Request $request)
    {
        $client = $this->requireClient($request);
        $ids = $this->idsFrom($request, 'ids');
        $cycles = (array) $request->input('billingcycles', []);

        $hosts = $this->renewals->hostsFor($client, $ids);

        if ($hosts === [] || count($hosts) !== count($ids)) {
            return $this->fail('请选择需要续费的产品');
        }

        return $this->ok($this->renewals->batchPage($hosts, $cycles));
    }

    /**
     * POST /v1/hosts/renew/batch — renew several services on one invoice.
     */
    public function renewBatch(Request $request)
    {
        $client = $this->requireClient($request);
        $ids = $this->idsFrom($request, 'ids');
        $cycles = (array) $request->input('billingcycles', []);

        $hosts = $this->renewals->hostsFor($client, $ids);

        if ($hosts === [] || count($hosts) !== count($ids)) {
            return $this->fail('请选择需要续费的产品');
        }

        try {
            $result = $this->renewals->renewBatch($hosts, $cycles, $client);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok([
            'invoice_id' => (int) $result['invoice']->id,
            'invoiceid' => (int) $result['invoice']->id,
            'payment' => (string) Configuration::value('payment_default', ''),
            'amount' => $this->hosts->money($result['amount']),
        ], '续费账单已生成');
    }

    /* ---------------------------------------------------------------------
     | Cancellation
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/hosts/{id}/cancel — current cancellation request + reasons.
     */
    public function cancelPage(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $request_row = DB::table('cancel_requests')
            ->where('relid', $host->id)
            ->where('delete_time', 0)
            ->orderByDesc('id')
            ->first();

        $reasons = DB::table('cancel_reason')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'reason' => (string) $row->reason])
            ->values()
            ->all();

        return $this->ok([
            'cancel' => $request_row === null ? null : [
                'type' => (string) ($request_row->type === 'Immediate' || $request_row->type === 'Endofbilling'
                    ? $request_row->type
                    : 'Endofbilling'),
                'reason' => (string) $request_row->reason,
                'status' => (int) $request_row->status,
                'create_time' => (int) $request_row->create_time,
            ],
            'cancelist' => $reasons,
            'show_cancel' => (int) Configuration::value('show_cancel', 1),
        ]);
    }

    /**
     * POST /v1/hosts/{id}/cancel — file a cancellation request.
     */
    public function cancel(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $type = (string) $request->input('type', 'Endofbilling');

        if (! in_array($type, ['Immediate', 'Endofbilling'], true)) {
            return $this->fail('停用类型不正确', 406);
        }

        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            return $this->fail('请填写停用原因', 406);
        }

        $existing = DB::table('cancel_requests')
            ->where('relid', $host->id)
            ->where('delete_time', 0)
            ->where('status', 0)
            ->first();

        if ($existing !== null) {
            return $this->fail('该产品已有待处理的停用申请');
        }

        DB::table('cancel_requests')->insert([
            'relid' => $host->id,
            'type' => $type,
            'reason' => $reason,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
            'status' => 0,
        ]);

        // Immediate cancellations run the module's terminate path right away.
        if ($type === 'Immediate') {
            $host->markAs(Host::STATUS_CANCELLED);

            DB::table('cancel_requests')
                ->where('relid', $host->id)
                ->where('delete_time', 0)
                ->where('status', 0)
                ->update(['status' => 1, 'update_time' => time()]);
        }

        return $this->ok([
            'id' => (int) $host->id,
            'type' => $type,
            'reason' => $reason,
        ], '停用申请已提交');
    }

    /**
     * DELETE /v1/hosts/{id}/cancel — withdraw the pending request.
     */
    public function cancelDelete(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $affected = DB::table('cancel_requests')
            ->where('relid', $host->id)
            ->where('delete_time', 0)
            ->where('status', 0)
            ->update(['delete_time' => time(), 'update_time' => time()]);

        if ($affected === 0) {
            return $this->fail('未找到待处理的停用申请');
        }

        // A previously Cancelled host goes back to Active.
        if ((string) $host->domainstatus === Host::STATUS_CANCELLED) {
            $host->markAs(Host::STATUS_ACTIVE);
        }

        return $this->ok(['id' => (int) $host->id], '已取消停用申请');
    }

    /* ---------------------------------------------------------------------
     | Configurable-option upgrades
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/hosts/{id}/actions/upgradeconfig
     */
    public function upgradeConfigPage(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $options = $this->hosts->upgradeConfigOptions($host);

        if ($options === []) {
            return $this->fail('该产品不支持配置项升降级');
        }

        $state = $this->upgradeState($client, $host, 'config');

        return $this->ok([
            'pid' => (int) $host->productid,
            'currency' => $this->hosts->currencyPayload(),
            'host' => $options,
            'configoption' => (object) ($state['configoption'] ?? []),
            'promo_code' => (string) ($state['promo_code'] ?? ''),
            'total' => $this->hosts->money((float) ($state['total'] ?? 0)),
            'subtotal' => $this->hosts->money((float) ($state['subtotal'] ?? 0)),
        ]);
    }

    /**
     * POST /v1/hosts/{id}/actions/upgradeconfig — price a selection.
     */
    public function upgradeConfig(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if (empty($this->hosts->upgradeConfigOptions($host))) {
            return $this->fail('该产品不支持配置项升降级');
        }

        $selections = $this->normaliseSelections((array) $request->input('configoption', []));

        if ($selections === []) {
            return $this->fail('请选择需要变更的配置项', 406);
        }

        $amount = $this->hosts->configUpgradeAmount($host, $selections);
        $state = $this->upgradeState($client, $host, 'config');
        $promo = $this->resolvePromo($state['promo_code'] ?? '');
        $discount = $promo === null ? 0.0 : $this->pricing->applyPromo($amount, $promo);
        $total = PricingService::money($amount - $discount);

        $state = array_merge($state, [
            'configoption' => $selections,
            'subtotal' => $amount,
            'total' => $total,
        ]);

        $this->saveUpgradeState($client, $host, 'config', $state);

        $payload = $this->configUpgradePayload($host, $selections, $amount, $total, $discount);

        $payload['promo_code'] = (string) ($state['promo_code'] ?? '');
        $payload['currency'] = $this->hosts->currencyPayload();

        return $this->ok($payload, '获取成功');
    }

    /**
     * POST /v1/hosts/{id}/actions/upgradeconfig/checkout
     */
    public function upgradeConfigCheckout(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $state = $this->upgradeState($client, $host, 'config');

        if (empty($state['configoption'])) {
            return $this->fail('请先选择需要变更的配置项');
        }

        $selections = (array) $state['configoption'];
        $amount = $this->hosts->configUpgradeAmount($host, $selections);
        $promo = $this->resolvePromo($state['promo_code'] ?? '');
        $discount = $promo === null ? 0.0 : $this->pricing->applyPromo($amount, $promo);
        $total = PricingService::money($amount - $discount);

        if ($total <= 0) {
            // Nothing to pay: apply the change immediately.
            $this->applyConfigUpgrade($host, $selections, $amount);

            $this->forgetUpgradeState($client, $host, 'config');

            return $this->ok([
                'invoiceid' => 0,
                'url' => 'servicedetail?id=' . $host->id,
            ], '配置项变更成功', ['status' => 1001]);
        }

        $invoice = $this->createUpgradeInvoice($client, $host, $total, sprintf(
            '%s - 配置项升降级',
            $this->hosts->productName($host) ?: ('服务 #' . $host->id)
        ));

        Upgrade::create([
            'uid' => (int) $client->id,
            'order_id' => 0,
            'type' => 'configoptions',
            'date' => time(),
            'relid' => (int) $host->id,
            'original_value' => json_encode($this->hosts->configOptions($host), JSON_UNESCAPED_UNICODE),
            'new_value' => json_encode($selections, JSON_UNESCAPED_UNICODE),
            'new_cycle' => (string) $host->billingcycle,
            'amount' => $total,
            'credit_amount' => 0,
            'days_remaining' => $this->hosts->cycleWindow($host)[0],
            'total_days_in_cycle' => $this->hosts->cycleWindow($host)[1],
            'new_recurring_amount' => $amount,
            'recurring_change' => $amount,
            'status' => 'Pending',
            'paid' => 'N',
            'description' => '配置项升降级',
        ]);

        $this->forgetUpgradeState($client, $host, 'config');

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'url' => 'viewbilling?id=' . $invoice->id,
        ], '订单已生成');
    }

    /**
     * PUT /v1/hosts/{id}/actions/upgradeconfig/promo
     */
    public function upgradeConfigPromo(Request $request, int $id)
    {
        return $this->applyPromo($request, $id, 'config');
    }

    /**
     * DELETE /v1/hosts/{id}/actions/upgradeconfig/promo
     */
    public function upgradeConfigPromoRemove(Request $request, int $id)
    {
        return $this->removePromo($request, $id, 'config');
    }

    /* ---------------------------------------------------------------------
     | Product upgrades
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/hosts/{id}/actions/upgrade
     */
    public function upgradePage(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $targets = $this->hosts->upgradeTargets($host);

        if ($targets === []) {
            return $this->fail('该产品不支持升降级');
        }

        $state = $this->upgradeState($client, $host, 'product');

        return $this->ok([
            'currency' => $this->hosts->currencyPayload(),
            'old_host' => [
                'id' => (int) $host->id,
                'host' => $this->hosts->productName($host),
                'domain' => (string) $host->domain,
                'flag' => (float) $host->amount > 0 ? 1 : 0,
            ],
            'host' => $targets,
            'promo_code' => (string) ($state['promo_code'] ?? ''),
            'subtotal' => $this->hosts->money((float) ($state['subtotal'] ?? 0)),
            'amount_total' => $this->hosts->money((float) ($state['total'] ?? 0)),
            'saleproducts' => $this->hosts->money((float) ($state['discount'] ?? 0)),
        ]);
    }

    /**
     * POST /v1/hosts/{id}/actions/upgrade — price a product change.
     */
    public function upgrade(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $productId = (int) $request->input('product_id', 0);
        $cycle = (string) $request->input('billingcycle', $host->billingcycle);

        $target = $this->upgradeTarget($host, $productId);

        if ($target === null) {
            return $this->fail('所选商品不支持升降级');
        }

        $cyclePrice = $this->pricing->cyclePrice($target, $cycle);

        if ($cyclePrice === null) {
            return $this->fail('所选商品不支持该计费周期');
        }

        $newRecurring = PricingService::money(
            $cyclePrice + $this->pricing->setupFee($target, $cycle)
        );
        $amount = $this->hosts->proratedUpgradeAmount($host, $newRecurring);

        $state = $this->upgradeState($client, $host, 'product');
        $promo = $this->resolvePromo($state['promo_code'] ?? '');
        $discount = $promo === null ? 0.0 : $this->pricing->applyPromo(max(0, $amount), $promo);
        $total = PricingService::money($amount - $discount);

        $state = array_merge($state, [
            'product_id' => $productId,
            'billingcycle' => $cycle,
            'subtotal' => $amount,
            'discount' => $discount,
            'total' => $total,
            'new_recurring' => $newRecurring,
        ]);

        $this->saveUpgradeState($client, $host, 'product', $state);

        return $this->ok([
            'currency' => $this->hosts->currencyPayload(),
            'name' => (string) $target->name,
            'saleproducts' => $this->hosts->money($discount),
            'amount_total' => $this->hosts->money($total),
            'subtotal' => $this->hosts->money($amount),
            'promo_code' => (string) ($state['promo_code'] ?? ''),
            'billingcycle' => $cycle,
            'old_host' => [
                'id' => (int) $host->id,
                'host' => $this->hosts->productName($host),
                'domain' => (string) $host->domain,
                'flag' => (float) $host->amount > 0 ? 1 : 0,
            ],
        ], '获取成功');
    }

    /**
     * POST /v1/hosts/{id}/actions/upgrade/checkout
     */
    public function upgradeCheckout(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $state = $this->upgradeState($client, $host, 'product');

        if (empty($state['product_id'])) {
            return $this->fail('请先选择需要升降级的商品');
        }

        $target = $this->upgradeTarget($host, (int) $state['product_id']);

        if ($target === null) {
            return $this->fail('所选商品不支持升降级');
        }

        $total = PricingService::money((float) ($state['total'] ?? 0));
        $cycle = (string) ($state['billingcycle'] ?? $host->billingcycle);

        if ($total <= 0) {
            $this->applyProductUpgrade($host, $target, $cycle, (float) ($state['new_recurring'] ?? 0));
            $this->forgetUpgradeState($client, $host, 'product');

            return $this->ok([
                'invoiceid' => 0,
                'orderid' => 0,
                'url' => 'servicedetail?id=' . $host->id,
            ], '产品变更成功', ['status' => 1001]);
        }

        $invoice = $this->createUpgradeInvoice($client, $host, $total, sprintf(
            '%s - 产品升降级',
            $this->hosts->productName($host) ?: ('服务 #' . $host->id)
        ));

        Upgrade::create([
            'uid' => (int) $client->id,
            'order_id' => 0,
            'type' => 'product',
            'date' => time(),
            'relid' => (int) $host->id,
            'original_value' => (string) $host->productid,
            'new_value' => (string) $target->id,
            'new_cycle' => $cycle,
            'amount' => $total,
            'credit_amount' => PricingService::money((float) ($state['discount'] ?? 0)),
            'days_remaining' => $this->hosts->cycleWindow($host)[0],
            'total_days_in_cycle' => $this->hosts->cycleWindow($host)[1],
            'new_recurring_amount' => (float) ($state['new_recurring'] ?? 0),
            'recurring_change' => PricingService::money((float) ($state['new_recurring'] ?? 0) - (float) $host->amount),
            'status' => 'Pending',
            'paid' => 'N',
            'description' => '产品升降级至 ' . $target->name,
        ]);

        $this->forgetUpgradeState($client, $host, 'product');

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'orderid' => 0,
            'url' => 'viewbilling?id=' . $invoice->id,
        ], '订单已生成');
    }

    /**
     * PUT /v1/hosts/{id}/actions/upgrade/promo
     */
    public function upgradePromo(Request $request, int $id)
    {
        return $this->applyPromo($request, $id, 'product');
    }

    /**
     * DELETE /v1/hosts/{id}/actions/upgrade/promo
     */
    public function upgradePromoRemove(Request $request, int $id)
    {
        return $this->removePromo($request, $id, 'product');
    }

    /* ---------------------------------------------------------------------
     | Module actions
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/hosts/{id}/module — module functions this product supports.
     */
    public function module(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        return $this->ok($this->moduleButtons($host));
    }

    public function on(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'on');
    }

    public function off(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'off');
    }

    public function reboot(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'reboot');
    }

    public function hardOff(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'hard_off');
    }

    public function hardReboot(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'hard_reboot');
    }

    public function bmc(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'bmc');
    }

    public function kvm(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'kvm');
    }

    public function ikvm(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'ikvm');
    }

    public function vnc(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'vnc');
    }

    public function rescue(Request $request, int $id)
    {
        return $this->moduleAction($request, $id, 'rescue', [
            'rescue_id' => (string) $request->input('rescue_id', ''),
        ]);
    }

    /**
     * PUT /v1/hosts/{id}/module/repassword — reset the service password.
     */
    public function repassword(Request $request, int $id)
    {
        $password = (string) $request->input('password', '');

        if ($password === '') {
            return $this->fail('请输入新密码', 406);
        }

        $result = $this->moduleAction($request, $id, 'crack_pass', ['password' => $password], true);

        return $result;
    }

    /**
     * GET /v1/hosts/{id}/module/reinstall — available operating systems.
     */
    public function getReinstall(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $result = $this->modules->call($this->modules->moduleNameFor($host), 'get_reinstall', $host);

        if (! $result['status']) {
            return $this->fail($result['msg']);
        }

        $data = is_array($result['data']) ? $result['data'] : [];
        $os = $data['os'] ?? $data['_os'] ?? [];

        return $this->ok([
            'os' => $os,
            'os_group' => $data['os_group'] ?? $data['_os_group'] ?? [],
        ], $result['msg']);
    }

    /**
     * PUT /v1/hosts/{id}/module/reinstall — reinstall the operating system.
     */
    public function reinstall(Request $request, int $id)
    {
        $osId = (string) $request->input('os_id', '');

        if ($osId === '') {
            return $this->fail('请选择操作系统', 406);
        }

        return $this->moduleAction($request, $id, 'reinstall', [
            'options' => [
                'os_id' => $osId,
                'password' => (string) $request->input('password', ''),
                'port' => $request->input('port'),
                'part_type' => $request->input('part_type'),
            ],
        ], true);
    }

    /**
     * POST /v1/hosts/{id}/module/reinstall_buy — buy a reinstall quota.
     */
    public function reinstallBuy(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $price = (float) Configuration::value('reinstall_price', 0);

        if ($price <= 0) {
            return $this->fail('重装次数暂不出售');
        }

        $invoice = $this->createUpgradeInvoice(
            $client,
            $host,
            $price,
            sprintf('%s - 重装次数', $this->hosts->productName($host) ?: ('服务 #' . $host->id)),
            'zjmf_reinstall_times'
        );

        return $this->ok(['invoiceid' => (int) $invoice->id], '订单已生成');
    }

    /**
     * GET /v1/hosts/{id}/module/status — power / task state.
     */
    public function status(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $type = (string) $request->input('type', 'host');

        if ($type === '') {
            return $this->fail('缺少参数 type', 406);
        }

        $result = $this->modules->call($this->modules->moduleNameFor($host), 'status', $host, [
            'type' => $type,
        ]);

        if (! $result['status']) {
            return $this->fail($result['msg']);
        }

        return $this->ok($result['data'], $result['msg']);
    }

    /**
     * GET /v1/hosts/{id}/module/charts — usage chart series.
     */
    public function charts(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $type = (string) $request->input('type', '');

        if ($type === '') {
            return $this->fail('缺少参数 type', 406);
        }

        $result = $this->modules->call($this->modules->moduleNameFor($host), 'chart', $host, [
            'type' => $type,
            'start' => $request->input('start'),
            'end' => $request->input('end'),
        ]);

        if (! $result['status']) {
            return $this->fail($result['msg']);
        }

        return $this->ok($result['data'], $result['msg']);
    }

    /**
     * GET /v1/hosts/{id}/module/custom — invoke a custom module function.
     */
    public function custom(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $key = (string) $request->input('key', '');

        if ($key === '') {
            return $this->fail('缺少参数 key', 406);
        }

        $available = collect($this->moduleButtons($host)['list'])->firstWhere('function', $key);

        if ($available === null) {
            return $this->fail('该功能不存在');
        }

        if (! $this->secondVerifyPassed($request, $key)) {
            return $this->needsSecondVerify($client);
        }

        $result = $this->modules->call($this->modules->moduleNameFor($host), $key, $host, [
            'key' => $key,
        ]);

        if (! $result['status']) {
            return $this->fail($result['msg']);
        }

        return $this->ok($result['data'], $result['msg']);
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    /**
     * Shared runner for every module action endpoint.
     */
    protected function moduleAction(Request $request, int $id, string $action, array $extra = [], bool $persistSecret = false)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        if (! $host->isLive()) {
            return $this->fail('产品当前状态不支持该操作');
        }

        if ($this->modules->requiresSecondVerify($action) && ! $this->secondVerifyPassed($request, $action)) {
            return $this->needsSecondVerify($client);
        }

        $result = $this->modules->call($this->modules->moduleNameFor($host), $action, $host, $extra);

        if (! $result['status']) {
            return $this->fail($result['msg']);
        }

        if ($persistSecret && isset($extra['password']) && $extra['password'] !== '') {
            $host->password = $this->encryptSecret((string) $extra['password']);
            $host->update_time = time();
            $host->save();
        }

        // Record the action the way the client area's activity log does.
        DB::table('activity_log')->insert([
            'create_time' => time(),
            'description' => '产品模块操作：' . $action,
            'user' => (string) $client->username,
            'uid' => (int) $client->id,
            'ipaddr' => (string) $request->ip(),
            'type' => 1,
            'activeid' => (int) $host->id,
            'usertype' => 'client',
            'port' => (string) $request->getPort(),
            'type_data_id' => 0,
        ]);

        return $this->ok($result['data'], $result['msg']);
    }

    /**
     * Module buttons available for a product, in the client area's shape.
     */
    protected function moduleButtons(Host $host): array
    {
        $module = $this->modules->moduleNameFor($host);

        if ($module === '') {
            return [];
        }

        $functions = $this->modules->availableFunctions($module);
        $power = ['on', 'off', 'reboot', 'hard_off', 'hard_reboot'];

        $control = [];
        $console = [];
        $custom = [];

        foreach ($functions as $function) {
            $item = [
                'type' => $function['type'],
                'function' => $function['function'],
                'func' => $function['function'],
                'name' => $function['name'],
                'desc' => $function['desc'],
                'select' => $function['select'],
            ];

            if (($function['type'] ?? 'default') === 'custom') {
                $custom[] = $item;

                continue;
            }

            if (in_array($function['function'], $power, true)) {
                $control[] = $item;

                continue;
            }

            $console[] = $item;
        }

        return [
            'control' => $control,
            'console' => $console,
            'custom' => $custom,
            'list' => $functions,
        ];
    }

    /**
     * Whether a verification code accompanied the request.
     */
    protected function secondVerifyPassed(Request $request, string $action): bool
    {
        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            return false;
        }

        $client = $this->client($request);
        $account = (string) ($client?->email ?: $client?->phonenumber);

        return $this->codes->consume('second_verify:' . $this->modules->actionFunction($action), $account, $code);
    }

    /**
     * Envelope telling the client to run the 二次验证 flow first.
     */
    protected function needsSecondVerify(Client $client)
    {
        $allowed = explode(',', (string) Configuration::value('second_verify_action_type', 'email,phone'));

        $types = [];

        foreach ($allowed as $type) {
            $type = trim($type);

            if ($type === 'phone' && (string) $client->phonenumber !== '') {
                $types[] = ['type' => 'phone', 'name_zh' => '手机', 'account' => (string) $client->phonenumber];
            } elseif ($type === 'email' && (string) $client->email !== '') {
                $types[] = ['type' => 'email', 'name_zh' => '邮箱', 'account' => $this->mask($client->email)];
            }
        }

        if ($types === []) {
            $types[] = ['type' => 'email', 'name_zh' => '邮箱', 'account' => $this->mask((string) $client->email)];
        }

        return response()->json(\App\Support\ApiResponse::success([
            'second_verify' => $types,
        ], '需要进行二次验证'));
    }

    protected function mask(?string $account): string
    {
        $account = (string) $account;

        if ($account === '') {
            return '';
        }

        if (str_contains($account, '@')) {
            [$name, $domain] = explode('@', $account, 2);

            return mb_substr($name, 0, 2) . '***@' . $domain;
        }

        return mb_substr($account, 0, 3) . '****' . mb_substr($account, -2);
    }

    /**
     * Payload for the service list rows.
     */
    protected function listPayload(Host $host): array
    {
        $cancel = DB::table('cancel_requests')
            ->where('relid', $host->id)
            ->where('delete_time', 0)
            ->where('status', 0)
            ->orderByDesc('id')
            ->first();

        return [
            'id' => (int) $host->id,
            'type' => (string) ($host->product->type ?? 'other'),
            'domain' => (string) $host->domain,
            'domainstatus' => (string) $host->domainstatus,
            'domainstatus_desc' => HostService::statusLabel($host->domainstatus),
            'regdate' => (int) $host->regdate,
            'nextduedate' => (int) $host->nextduedate,
            'firstpaymentamount' => $this->hosts->money((float) $host->firstpaymentamount),
            'amount' => $this->hosts->money((float) $host->amount),
            'billingcycle' => (string) $host->billingcycle,
            'billingcycle_zh' => HostService::cycleShort((string) $host->billingcycle),
            'dedicatedip' => (string) $host->dedicatedip,
            'assignedips' => $this->splitIps((string) $host->assignedips),
            'initiative_renew' => (int) $host->initiative_renew,
            'remark' => (string) $host->remark,
            'product_id' => (int) $host->productid,
            'product_name' => $this->hosts->productName($host),
            'group_id' => (int) ($host->product->gid ?? 0),
            'group_name' => $this->hosts->groupName($host),
            'os' => (string) $host->os,
            'host_cancel' => $cancel === null ? '' : [
                'type' => (string) $cancel->type,
                'reason' => (string) $cancel->reason,
            ],
        ];
    }

    /**
     * Build the config-option upgrade response, including the old/new diff.
     */
    protected function configUpgradePayload(Host $host, array $selections, float $subtotal, float $total, float $discount): array
    {
        $current = $this->hosts->configOptions($host);
        $groups = $this->hosts->upgradeConfigOptions($host);
        $allOption = [];

        foreach ($groups as $group) {
            $newSelections = $selections[$group['id']] ?? null;

            if ($newSelections === null) {
                continue;
            }

            $newIds = is_array($newSelections) ? array_map('intval', $newSelections) : [(int) $newSelections];
            $oldRows = array_values(array_filter($current, fn ($row) => $row['id'] === $group['id']));

            $newNames = [];
            foreach ($group['sub'] as $sub) {
                if (in_array((int) $sub['id'], $newIds, true)) {
                    $newNames[] = $sub['suboption_name'] ?: $sub['option_name'];
                }
            }

            $allOption[] = [
                'oid' => (int) $group['id'],
                'option_name' => $group['option_name'],
                'option_type' => (int) $group['option_type'],
                'suboption_name' => implode(',', $newNames),
                'old_suboption_name' => implode(',', array_map(fn ($row) => $row['sub_name'], $oldRows)),
                'old_qty' => $oldRows === [] ? 0 : (int) $oldRows[0]['qty'],
                'qty' => is_array($newSelections) ? 1 : (int) $newSelections,
            ];
        }

        return [
            'name' => $this->hosts->productName($host) . '(' . (string) $host->domain . ')',
            'payment' => (string) ($host->payment ?: Configuration::value('payment_default', '')),
            'saleproducts' => $this->hosts->money($discount),
            'subtotal' => $this->hosts->money($subtotal),
            'total' => $this->hosts->money($total),
            'billingcycle' => (string) $host->billingcycle,
            'configoptions' => (object) $selections,
            'alloption' => $allOption,
        ];
    }

    /**
     * Apply a config-option change to the host's stored selection.
     */
    protected function applyConfigUpgrade(Host $host, array $selections, float $amount): void
    {
        DB::transaction(function () use ($host, $selections, $amount) {
            DB::table('host_config_options')->where('relid', $host->id)->delete();

            foreach ($selections as $configId => $value) {
                $configId = (int) $configId;

                foreach ((array) $value as $subId) {
                    DB::table('host_config_options')->insert([
                        'relid' => (int) $host->id,
                        'configid' => $configId,
                        'optionid' => (int) $subId,
                        'qty' => 1,
                    ]);
                }
            }

            $host->amount = PricingService::money((float) $host->amount + $amount);
            $host->update_time = time();
            $host->save();
        });

        $this->modules->dispatch($host, 'renew', true);
    }

    /**
     * Apply a product change to a host.
     */
    protected function applyProductUpgrade(Host $host, Product $target, string $cycle, float $newRecurring): void
    {
        $host->productid = (int) $target->id;
        $host->billingcycle = $cycle;
        $host->amount = PricingService::money($newRecurring);
        $host->update_time = time();
        $host->save();
    }

    /**
     * A single upgrade invoice line.
     */
    protected function createUpgradeInvoice(Client $client, Host $host, float $amount, string $description, string $type = 'upgrade'): Invoice
    {
        $invoice = $this->invoices()->create($client, [[
            'type' => $type === 'upgrade' ? 'upgrade' : $type,
            'rel_id' => (int) $host->id,
            'description' => $description,
            'amount' => $amount,
        ]], time() + 86400 * 7, 'upgrade');

        return $invoice;
    }

    /**
     * Shared promo application for both upgrade flows.
     */
    protected function applyPromo(Request $request, int $id, string $flow)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $code = trim((string) $request->input('pormo_code', $request->input('promo_code', '')));

        if ($code === '') {
            return $this->fail('请输入优惠码', 406);
        }

        $promo = $this->hosts->upgradePromo($code);

        if ($promo === null) {
            return $this->fail('优惠码无效或已过期');
        }

        $state = $this->upgradeState($client, $host, $flow);

        if (empty($state)) {
            return $this->fail('请先选择需要变更的配置');
        }

        $state['promo_code'] = $promo->code;

        $subtotal = (float) ($state['subtotal'] ?? 0);
        $discount = $this->pricing->applyPromo(max(0, $subtotal), $promo);
        $state['discount'] = $discount;
        $state['total'] = PricingService::money($subtotal - $discount);

        $this->saveUpgradeState($client, $host, $flow, $state);

        return $this->ok([
            'promo_code' => (string) $promo->code,
            'saleproducts' => $this->hosts->money($discount),
            'subtotal' => $this->hosts->money($subtotal),
            'total' => $this->hosts->money($state['total']),
            'currency' => $this->hosts->currencyPayload(),
        ], '优惠码已应用');
    }

    /**
     * Remove a promo code from a pending upgrade.
     */
    protected function removePromo(Request $request, int $id, string $flow)
    {
        $client = $this->requireClient($request);
        $host = $this->findHost($client, $id);

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $state = $this->upgradeState($client, $host, $flow);

        if (empty($state)) {
            return $this->fail('请先选择需要变更的配置');
        }

        $state['promo_code'] = '';
        $state['discount'] = 0.0;
        $state['total'] = PricingService::money((float) ($state['subtotal'] ?? 0));

        $this->saveUpgradeState($client, $host, $flow, $state);

        return $this->ok([
            'promo_code' => '',
            'saleproducts' => '0.00',
            'subtotal' => $this->hosts->money((float) $state['subtotal']),
            'total' => $this->hosts->money((float) $state['total']),
            'currency' => $this->hosts->currencyPayload(),
        ], '优惠码已移除');
    }

    protected function resolvePromo(mixed $code): ?PromoCode
    {
        $code = trim((string) $code);

        return $code === '' ? null : $this->hosts->upgradePromo($code);
    }

    /**
     * Cached upgrade selection for a host, mirroring the original's
     * step-one/step-two modal hand-off.
     */
    protected function upgradeState(Client $client, Host $host, string $flow): array
    {
        return (array) Cache::get($this->upgradeKey($client, $host, $flow), []);
    }

    protected function saveUpgradeState(Client $client, Host $host, string $flow, array $state): void
    {
        Cache::put($this->upgradeKey($client, $host, $flow), $state, self::UPGRADE_CACHE_TTL);
    }

    protected function forgetUpgradeState(Client $client, Host $host, string $flow): void
    {
        Cache::forget($this->upgradeKey($client, $host, $flow));
    }

    protected function upgradeKey(Client $client, Host $host, string $flow): string
    {
        return 'host_upgrade:' . $flow . ':' . $client->id . ':' . $host->id;
    }

    /**
     * The upgrade product row that a host may switch to.
     */
    protected function upgradeTarget(Host $host, int $productId): ?Product
    {
        if ($productId <= 0) {
            return null;
        }

        $allowed = DB::table('product_upgrade_products')
            ->where('product_id', $host->productid)
            ->where('upgrade_product_id', $productId)
            ->exists();

        if (! $allowed) {
            return null;
        }

        return Product::query()
            ->where('id', $productId)
            ->where('hidden', 0)
            ->first();
    }

    /**
     * A host may be renewed when its catalog offers recurring cycles.
     */
    protected function renewable(Host $host): bool
    {
        if (! $host->isLive()) {
            return false;
        }

        return $this->allowedCycles($host) !== [];
    }

    protected function allowedCycles(Host $host): array
    {
        $cycles = [];

        foreach ($this->hosts->renewalCycles($host) as $cycle) {
            $cycles[] = $cycle['billingcycle'];
        }

        return $cycles;
    }

    /**
     * Normalise the `configoption[<id>]` input into `int => int|int[]`.
     */
    protected function normaliseSelections(array $raw): array
    {
        $selections = [];

        foreach ($raw as $configId => $value) {
            $configId = (int) $configId;

            if ($configId <= 0) {
                continue;
            }

            if (is_array($value)) {
                $values = array_values(array_filter(array_map('intval', $value), fn ($id) => $id > 0));

                if ($values !== []) {
                    $selections[$configId] = $values;
                }

                continue;
            }

            $value = (int) $value;

            if ($value > 0) {
                $selections[$configId] = $value;
            }
        }

        return $selections;
    }

    protected function idsFrom(Request $request, string $key): array
    {
        $raw = $request->input($key, []);

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_filter(array_map('intval', (array) $raw), fn ($id) => $id > 0));
    }

    protected function requestedStatuses(Request $request): array
    {
        $raw = $request->input('domainstatus', $request->input('domain_status', []));

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        $statuses = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            (array) $raw
        ), fn ($value) => $value !== '' && isset(HostService::STATUS_LABELS[$value])));

        return $statuses;
    }

    protected function splitIps(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: []), fn ($ip) => $ip !== ''));
    }

    /**
     * Host belonging to the client, or null.
     */
    protected function findHost(Client $client, int $id): ?Host
    {
        return Host::query()
            ->where('id', $id)
            ->where('uid', $client->id)
            ->first();
    }

    /**
     * Drivers store passwords in plain text; the surrounding platform wraps
     * them when a crypto helper is available.
     */
    protected function encryptSecret(string $value): string
    {
        if (class_exists(\App\Support\Crypto::class)) {
            try {
                return \App\Support\Crypto::encrypt($value);
            } catch (\Throwable) {
                // Fall through to the plain value.
            }
        }

        return $value;
    }

    protected function invoices(): \App\Services\InvoiceService
    {
        return app(\App\Services\InvoiceService::class);
    }
}
