<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminMeta;
use App\Services\InvoiceService;
use App\Services\ModuleService;
use App\Services\PricingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 业务 → 业务列表 / 业务内页 — the service (host) list, the host editor and
 * the module action buttons.
 */
class ServiceController extends AdminController
{
    /**
     * `GET clients_services/list` / `POST searchfornamelist` — the 业务列表.
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, ['id', 'regdate', 'nextduedate', 'amount', 'domainstatus'], 'id');

        $query = Host::query();

        $this->applyFilters($query, $request);

        $total = (clone $query)->count();
        $rows = $query->orderBy($orderBy, $sort)->forPage($page, $limit)->get();

        return $this->paginated($this->decorate($rows), $total, $page, $limit, [
            'tabsStatus' => $this->statusTabs(),
            'condition' => $this->conditionOptions(),
        ]);
    }

    /**
     * `POST searchfornamelist` — advanced search for the service table.
     */
    public function searchForNameList(Request $request)
    {
        return $this->list($request);
    }

    /**
     * `POST clients_services/info` — save the 业务内页 (host editor).
     *
     * The form posts the full `shd_host` row plus `configoption[]` and
     * `customfield[]`; the original writes every submitted column.
     */
    public function update(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $host = Host::query()->find($id);

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $fields = [
            'productid', 'serverid', 'domain', 'payment', 'firstpaymentamount',
            'amount', 'billingcycle', 'domainstatus', 'username', 'password',
            'notes', 'subscriptionid', 'promoid', 'suspendreason',
            'overideautosuspend', 'overidesuspenduntil', 'dedicatedip',
            'assignedips', 'ns1', 'ns2', 'port', 'upstream_cost',
            'auto_terminate_end_cycle', 'auto_terminate_reason', 'initiative_renew',
            'remark', 'os', 'percent_value',
        ];

        $data = [];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        // Date pickers post either a unix timestamp or a datetime string.
        if ($request->has('regdate') || $request->has('regdateCus')) {
            $regdate = $this->timestamp($request->input('regdate', $request->input('regdateCus')));

            if ($regdate) {
                $data['regdate'] = $regdate;
            }
        }

        if ($request->has('nextduedate') || $request->has('nextduedateCus')) {
            $nextDue = $this->timestamp($request->input('nextduedate', $request->input('nextduedateCus')));

            if ($nextDue) {
                $data['nextduedate'] = $nextDue;
                $data['nextinvoicedate'] = $nextDue;
            }
        }

        $data['update_time'] = time();

        $host->fill($data)->save();

        // Config-option selections live in `shd_host_config_options`.
        if ($request->has('configoption')) {
            $this->saveHostConfigOptions($host, (array) $request->input('configoption', []));
        }

        // Custom fields for the 其他服务 / product field set.
        if ($request->has('customfield')) {
            $this->saveHostCustomFields($host, (array) $request->input('customfield', []));
        }

        $this->log('编辑产品 #'.$host->id, (int) $host->id);

        return $this->ok(['id' => (int) $host->id], '保存成功');
    }

    /**
     * `POST clients_services/transfer` — change the owning client.
     */
    public function transfer(Request $request)
    {
        $hostId = (int) $request->input('id', $request->input('hostid', 0));
        $uid = (int) $request->input('uid', 0);

        $host = Host::query()->find($hostId);

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $previous = $host->uid;
        $host->uid = $uid;
        $host->update_time = time();
        $host->save();

        DB::table('transfer')->insert([
            'uid' => $previous,
            'host_id' => $hostId,
            'transfer_uid' => $uid,
            'remarks' => '管理员'.$this->adminName().'转移产品',
            'status' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $this->log('转移产品 #'.$hostId.'：'.$previous.' → '.$uid, $hostId);

        return $this->ok(['id' => $hostId, 'uid' => $uid], '转移成功');
    }

    /**
     * `DELETE clients_services/host {hostid}` — terminate a service.
     */
    public function delete(Request $request)
    {
        $ids = $request->input('hostid', $request->input('id', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('请选择要删除的产品');
        }

        $module = app(ModuleService::class);
        $deleted = [];
        $errors = [];

        foreach (Host::query()->whereIn('id', $ids)->get() as $host) {
            try {
                // Terminate with the provisioning module where one is attached.
                if ($host->serverid || $host->product?->server_type) {
                    $result = $module->dispatch($host, 'terminate');

                    if (($result['status'] ?? 0) !== ApiResponse::OK) {
                        $errors[] = '#'.$host->id.'：'.($result['msg'] ?? '删除失败');

                        continue;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = '#'.$host->id.'：'.$e->getMessage();

                continue;
            }

            $deleted[] = (int) $host->id;
            $host->domainstatus = 'Deleted';
            $host->termination_date = time();
            $host->update_time = time();
            $host->save();
        }

        $this->log('删除产品：'.implode(',', $deleted));

        if ($deleted === []) {
            return $this->fail($errors[0] ?? '删除失败');
        }

        return $this->ok(['deleted' => $deleted, 'errors' => $errors], '删除成功');
    }

    /**
     * `GET clients_services/host_renew {id}` — the 续费 dialog metadata.
     */
    public function renewPage(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        $product = $host->product;
        $pricing = app(PricingService::class);

        $cycles = [];

        foreach (AdminMeta::BILLING_CYCLES as $cycle => $label) {
            $price = $product ? $pricing->cyclePrice($product, $cycle) : null;

            if ($price === null) {
                continue;
            }

            $cycles[] = [
                'cycle' => $cycle,
                'name' => $label,
                'amount' => $this->money($price),
                'setup_fee' => $pricing->setupFee($product, $cycle),
            ];
        }

        return $this->ok([
            'host' => (array) $host->toArray(),
            'cycles' => $cycles,
            'billingcycle' => $host->billingcycle,
            'amount' => $this->money((float) $host->amount),
            'nextduedate' => (int) $host->nextduedate,
            'gateway' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `POST clients_services/host_renew {id, billingcycle, amount}` — generate
     * a renewal invoice for one service.
     */
    public function renew(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $client = Client::query()->find($host->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $cycle = (string) ($request->input('billingcycle') ?: $host->billingcycle ?: 'monthly');

        $amount = $request->has('amount') && $request->input('amount') !== null && $request->input('amount') !== ''
            ? $this->money((float) $request->input('amount'))
            : $this->money((float) (app(PricingService::class)->cyclePrice($host->product, $cycle) ?? $host->amount));

        if ($amount <= 0) {
            return $this->validationFail('续费金额必须大于0');
        }

        $dueTime = $this->timestamp($request->input('due_time'))
            ?? ((int) $host->nextduedate > time() ? (int) $host->nextduedate : time() + 86400 * 7);

        $invoice = app(InvoiceService::class)->create(
            $client,
            [[
                'type' => 'renew',
                'rel_id' => (int) $host->id,
                'description' => '续费 - '.($host->product?->name ?? $host->domain).' ('.(AdminMeta::BILLING_CYCLES[$cycle] ?? $cycle).')',
                'amount' => $amount,
            ]],
            $dueTime,
            'renew',
            ['payment' => (string) $request->input('payment', '')],
        );

        $this->log('续费产品 #'.$host->id.' 生成账单 #'.$invoice->id, (int) $host->id);

        return $this->ok([
            'id' => (int) $invoice->id,
            'invoiceid' => (int) $invoice->id,
            'amount' => $amount,
        ], '续费账单已生成');
    }

    /**
     * `GET|POST clients_services/host_batch_renew_page` — the batch-renewal
     * confirmation payload.
     */
    public function batchRenewPage(Request $request)
    {
        $ids = $this->hostIds($request);
        $cycle = (string) $request->input('billingcycle', '');

        $hosts = Host::query()->whereIn('id', $ids)->get();
        $pricing = app(PricingService::class);

        $rows = $hosts->map(function (Host $host) use ($cycle, $pricing) {
            $useCycle = $cycle !== '' ? $cycle : (string) $host->billingcycle;
            $amount = (float) ($pricing->cyclePrice($host->product, $useCycle) ?? $host->amount);

            return [
                'id' => (int) $host->id,
                'uid' => (int) $host->uid,
                'username' => Client::query()->whereKey($host->uid)->value('username'),
                'productname' => $host->product?->name,
                'domain' => $host->domain,
                'billingcycle' => $useCycle,
                'billingcycle_zh' => AdminMeta::BILLING_CYCLES[$useCycle] ?? $useCycle,
                'nextduedate' => (int) $host->nextduedate,
                'amount' => $this->money($amount),
            ];
        })->all();

        return $this->ok([
            'list' => $rows,
            'hosts' => $rows,
            'total' => $this->money(array_sum(array_column($rows, 'amount'))),
            'cycles' => AdminMeta::BILLING_CYCLES,
            'gateway' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `POST clients_services/host_batch_renew` — renew several services at
     * once, grouping the invoices by client.
     */
    public function batchRenew(Request $request)
    {
        $ids = $this->hostIds($request);
        $cycle = (string) $request->input('billingcycle', '');

        if ($ids === []) {
            return $this->validationFail('请选择要续费的产品');
        }

        $pricing = app(PricingService::class);
        $invoiceService = app(InvoiceService::class);
        $created = [];

        foreach (Host::query()->whereIn('id', $ids)->get() as $host) {
            $client = Client::query()->find($host->uid);

            if ($client === null) {
                continue;
            }

            $useCycle = $cycle !== '' ? $cycle : ((string) $host->billingcycle ?: 'monthly');
            $amount = $this->money((float) ($pricing->cyclePrice($host->product, $useCycle) ?? $host->amount));

            if ($amount <= 0) {
                continue;
            }

            $dueTime = (int) $host->nextduedate > time() ? (int) $host->nextduedate : time() + 86400 * 7;

            $invoice = $invoiceService->create(
                $client,
                [[
                    'type' => 'renew',
                    'rel_id' => (int) $host->id,
                    'description' => '续费 - '.($host->product?->name ?? $host->domain).' ('.(AdminMeta::BILLING_CYCLES[$useCycle] ?? $useCycle).')',
                    'amount' => $amount,
                ]],
                $dueTime,
                'renew',
                ['payment' => (string) $request->input('payment', '')],
            );

            $created[] = ['host_id' => (int) $host->id, 'invoice_id' => (int) $invoice->id, 'amount' => $amount];
        }

        if ($created === []) {
            return $this->fail('没有可续费的产品');
        }

        $this->log('批量续费产品：'.implode(',', array_column($created, 'host_id')));

        return $this->ok(['list' => $created, 'count' => count($created)], '续费成功');
    }

    /**
     * `GET clients_services/host_suspend {id}` — the 暂停 dialog metadata
     * (canned reason list plus the service summary).
     */
    public function suspendPage(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        return $this->ok([
            'host' => (array) $host->toArray(),
            'reason_type' => $this->suspendReasons(),
        ]);
    }

    /**
     * `POST clients_services/host_suspend {id, reason_type, reason}` — suspend
     * a service, optionally through its provisioning module.
     */
    public function suspend(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $reason = (string) $request->input('reason', '');
        $reasonType = (int) $request->input('reason_type', 0);

        if (mb_strlen($reason) > 20) {
            return $this->validationFail('暂停原因不能超过20个字符');
        }

        if ($host->isSuspended()) {
            return $this->fail('该产品已暂停');
        }

        if ($host->serverid || $host->product?->server_type) {
            $result = app(ModuleService::class)->dispatch($host, 'suspend');

            if (($result['status'] ?? 0) !== ApiResponse::OK) {
                return $this->fail($result['msg'] ?? '暂停失败');
            }
        }

        $host->domainstatus = 'Suspended';
        $host->suspendreason = $reason;
        $host->suspend_time = time();
        $host->auto_terminate_reason = $reasonType > 0 ? (string) $reasonType : $host->auto_terminate_reason;
        $host->update_time = time();
        $host->save();

        $this->log('暂停产品 #'.$host->id, (int) $host->id);

        return $this->ok(['id' => (int) $host->id], '暂停成功');
    }

    /**
     * `POST clients_services/unsuspend {id}` — lift a suspension.
     */
    public function unsuspend(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        if (! $host->isSuspended()) {
            return $this->fail('该产品未被暂停');
        }

        if ($host->serverid || $host->product?->server_type) {
            $result = app(ModuleService::class)->dispatch($host, 'unsuspend');

            if (($result['status'] ?? 0) !== ApiResponse::OK) {
                return $this->fail($result['msg'] ?? '解除暂停失败');
            }
        }

        $host->domainstatus = 'Active';
        $host->suspendreason = '';
        $host->update_time = time();
        $host->save();

        $this->log('解除暂停产品 #'.$host->id, (int) $host->id);

        return $this->ok(['id' => (int) $host->id], '操作成功');
    }

    /**
     * `GET clients_services/refund_page {id}` — 退款 dialog metadata.
     */
    public function refundPage(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        $paid = (float) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.rel_id', $host->id)
            ->whereIn('invoice_items.type', ['hosting', 'renew', 'upgrade'])
            ->where('invoices.status', 'Paid')
            ->whereNull('invoice_items.delete_time')
            ->sum('invoice_items.amount');

        return $this->ok([
            'host' => (array) $host->toArray(),
            'paid_amount' => $this->money($paid),
            'diff_amount' => $this->money($paid),
            'gateway' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `POST clients_services/refund` — issue a refund against a service.
     */
    public function refund(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $client = Client::query()->find($host->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));

        if ($amount <= 0) {
            return $this->validationFail('退款金额必须大于0');
        }

        $type = (string) $request->input('type', 'credit');

        DB::transaction(function () use ($client, $host, $amount, $type) {
            if ($type === 'credit') {
                // Refund to the client's balance.
                $client->addCredit($amount, '产品退款：'.$host->domain, (int) $host->id);
            }

            DB::table('accounts')->insert([
                'uid' => $client->id,
                'currency' => 'CNY',
                'gateway' => (string) request()->input('payment', ''),
                'create_time' => time(),
                'update_time' => time(),
                'description' => '产品退款 #'.$host->id,
                'amount_in' => 0,
                'fees' => 0,
                'amount_out' => $amount,
                'rate' => 1,
                'trans_id' => '',
                'invoice_id' => 0,
                'refund' => 1,
            ]);
        });

        $this->log('产品退款 #'.$host->id.' 金额 '.$amount, (int) $host->id);

        return $this->ok(['amount' => $amount], '退款成功');
    }

    /**
     * `GET clients_services/apply_credit_page {id}` — 余额支付 dialog.
     */
    public function applyCreditPage(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null && ! $request->filled('uid')) {
            return $this->fail('ID错误');
        }

        $uid = (int) ($host->uid ?? $request->input('uid', 0));
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $unpaid = Invoice::query()
            ->where('uid', $uid)
            ->whereIn('status', ['Unpaid', 'Overdue'])
            ->get()
            ->map(fn (Invoice $i) => [
                'id' => (int) $i->id,
                'invoice_num' => $i->invoice_num,
                'total' => $this->money((float) $i->total),
                'due_time' => (int) $i->due_time,
                'status' => (string) $i->status,
            ])
            ->all();

        return $this->ok([
            'credit' => $this->money((float) $client->credit),
            'invoices' => $unpaid,
            'total' => $this->money(array_sum(array_column($unpaid, 'total'))),
        ]);
    }

    /**
     * `POST clients_services/apply_credit` — pay an invoice from the balance.
     */
    public function applyCredit(Request $request)
    {
        $invoiceId = (int) $request->input('invoiceid', $request->input('id', 0));
        $uid = (int) $request->input('uid', 0);

        $invoice = $invoiceId > 0 ? Invoice::query()->find($invoiceId) : null;

        if ($invoice === null && $uid > 0) {
            $invoice = Invoice::query()
                ->where('uid', $uid)
                ->whereIn('status', ['Unpaid', 'Overdue'])
                ->orderBy('id')
                ->first();
        }

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $client = Client::query()->find($invoice->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $ok = app(InvoiceService::class)->payWithCredit($invoice, $client);

        if (! $ok) {
            return $this->fail('余额不足');
        }

        $this->log('余额支付账单 #'.$invoice->id, (int) $invoice->id);

        return $this->ok(['id' => (int) $invoice->id], '支付成功');
    }

    /**
     * `POST clients_services/upgrade_config` — change a config-option
     * selection and bill the difference.
     */
    public function upgradeConfig(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $client = Client::query()->find($host->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $selections = $this->normaliseConfigOptions($request->input('configoption', []));
        $cycle = (string) $host->billingcycle ?: 'monthly';

        $current = (float) $host->amount;
        $new = (float) app(PricingService::class)->configOptionsTotal($host->product, $selections, $cycle);

        $this->saveHostConfigOptions($host, $request->input('configoption', []));

        $diff = $this->money($new - $current);

        if ($diff <= 0) {
            return $this->ok(['diff' => 0], '配置已更新');
        }

        $invoice = app(InvoiceService::class)->create(
            $client,
            [[
                'type' => 'upgrade',
                'rel_id' => (int) $host->id,
                'description' => '产品升级 - '.($host->product?->name ?? $host->domain),
                'amount' => $diff,
            ]],
            time() + 86400 * 7,
            'upgrade',
        );

        $this->log('产品升级 #'.$host->id, (int) $host->id);

        return $this->ok([
            'diff' => $diff,
            'invoiceid' => (int) $invoice->id,
        ], '升级成功');
    }

    /**
     * `GET clients_services/get_product_list {uid}` — the add-service picker.
     */
    public function getProductList(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        return $this->ok([
            'products' => AdminMeta::productList(),
            'hosts' => Host::query()
                ->when($uid > 0, fn ($q) => $q->where('uid', $uid))
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'uid', 'domain', 'productid', 'domainstatus'])
                ->toArray(),
        ]);
    }

    /**
     * `GET clients_services/host_get_timetype` — the date-type dictionary.
     */
    public function getTimeType(Request $request)
    {
        return $this->ok([
            'timetype' => [
                ['value' => 'day', 'name' => '天'],
                ['value' => 'month', 'name' => '月'],
                ['value' => 'year', 'name' => '年'],
            ],
        ]);
    }

    /**
     * `POST provision/default {id, func, reason_type, reason}` — the module
     * action buttons on the business-inner page.
     */
    public function provisionDefault(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $func = (string) $request->input('func', '');

        if ($func === '') {
            return $this->validationFail('请指定要执行的操作');
        }

        if (! array_key_exists($func, ModuleService::ACTION_FUNCTIONS)) {
            return $this->validationFail('不支持的操作');
        }

        // `suspend` carries the administrator-supplied reason.
        $extra = [];

        if ($func === 'suspend') {
            $reason = (string) $request->input('reason', '');

            if (mb_strlen($reason) > 20) {
                return $this->validationFail('暂停原因不能超过20个字符');
            }

            $extra = [
                'reason' => $reason,
                'reason_type' => (int) $request->input('reason_type', 0),
            ];
        }

        try {
            $result = app(ModuleService::class)->dispatch($host, $func, false, $extra);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '操作失败');
        }

        $this->log('执行模块操作 '.$func.' 于产品 #'.$host->id, (int) $host->id);

        return $this->respond($result + $this->envelope());
    }

    /**
     * `POST provision/custom` — module-specific extra actions (cloud rescue,
     * reinstall options, console …).
     */
    public function provisionCustom(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $action = (string) $request->input('func', $request->input('action', ''));

        if ($action === '') {
            return $this->validationFail('请指定要执行的操作');
        }

        $extra = $request->except(['id', 'func', 'action']);

        try {
            $result = app(ModuleService::class)->call(
                (new ModuleService())->moduleNameFor($host),
                $action,
                $host,
                is_array($extra) ? $extra : [],
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '操作失败');
        }

        $this->log('执行自定义模块操作 '.$action.' 于产品 #'.$host->id, (int) $host->id);

        return $this->respond($result + $this->envelope());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Apply the 业务列表 filters.
     */
    private function applyFilters($query, Request $request): void
    {
        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        $username = trim((string) $request->input('username', ''));

        if ($username !== '') {
            $uids = Client::query()
                ->where(function ($q) use ($username) {
                    $q->where('username', 'like', "%{$username}%")
                        ->orWhere('email', 'like', "%{$username}%")
                        ->orWhere('companyname', 'like', "%{$username}%");
                })
                ->pluck('id')
                ->all();

            $query->whereIn('uid', $uids ?: [-1]);
        }

        if (($status = $request->input('domainstatus')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('domainstatus', $status);
        }

        if (($cycle = $request->input('billingcycle')) !== null && $cycle !== '' && $cycle !== 'ALL') {
            $query->where('billingcycle', $cycle);
        }

        if ($domain = trim((string) $request->input('domain', ''))) {
            $query->where('domain', 'like', "%{$domain}%");
        }

        if ($ip = trim((string) $request->input('ip', $request->input('dedicatedip', '')))) {
            $query->where(function ($q) use ($ip) {
                $q->where('dedicatedip', 'like', "%{$ip}%")
                    ->orWhere('assignedips', 'like', "%{$ip}%");
            });
        }

        // 产品类型 filters through the product table, not `shd_host`.
        $productType = $request->input('product_type');

        if ($productType !== null && $productType !== '' && $productType !== 'ALL') {
            $pids = Product::query()->where('type', $productType)->pluck('id')->all();
            $query->whereIn('productid', $pids ?: [-1]);
        }

        if ($request->filled('start_time')) {
            $query->where('regdate', '>=', (int) $this->timestamp($request->input('start_time')));
        }

        if ($request->filled('end_time')) {
            $query->where('regdate', '<=', (int) $this->timestamp($request->input('end_time')));
        }
    }

    /**
     * Decorate host rows with the labels the table renders.
     */
    private function decorate($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $productIds = $rows->pluck('productid')->unique()->all();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $uids = $rows->pluck('uid')->unique()->all();
        $clients = Client::query()->whereIn('id', $uids)->get()->keyBy('id');

        $staff = User::query()->pluck('user_nickname', 'id');

        return $rows->map(function (Host $host) use ($products, $clients, $staff) {
            $product = $products[$host->productid] ?? null;
            $client = $clients[$host->uid] ?? null;

            return [
                'id' => (int) $host->id,
                'uid' => (int) $host->uid,
                'username' => $client?->username,
                'companyname' => $client?->companyname,
                'productid' => (int) $host->productid,
                'productname' => $product?->name,
                'domain' => $host->domain,
                'dedicatedip' => $host->dedicatedip,
                'assignedips' => $host->assignedips,
                'type' => $product?->type,
                'type_zh' => AdminMeta::productTypeLabel($product?->type),
                'regdate' => (int) $host->regdate,
                'nextduedate' => (int) $host->nextduedate,
                'billingcycle' => (string) $host->billingcycle,
                'billingcycle_zh' => AdminMeta::BILLING_CYCLES[(string) $host->billingcycle] ?? (string) $host->billingcycle,
                'amount' => $this->money((float) $host->amount),
                'firstpaymentamount' => $this->money((float) $host->firstpaymentamount),
                'domainstatus' => (string) $host->domainstatus,
                'domainstatus_zh' => AdminMeta::domainStatusLabel((string) $host->domainstatus),
                'suspendreason' => $host->suspendreason,
                'sale_id' => (int) ($client->sale_id ?? 0),
                'user_nickname' => (string) ($staff[$client->sale_id ?? 0] ?? ''),
            ];
        })->all();
    }

    /**
     * The status tabs above the service table.
     */
    private function statusTabs(): array
    {
        $tabs = [['label' => '全部', 'value' => 'ALL']];

        foreach (AdminMeta::DOMAIN_STATUS as $value => $meta) {
            $tabs[] = ['label' => $meta['name'], 'value' => $value];
        }

        return $tabs;
    }

    private function conditionOptions(): array
    {
        return [
            'product_type' => AdminMeta::PRODUCT_TYPES,
            'domainstatus' => AdminMeta::DOMAIN_STATUS,
            'billingcycle' => AdminMeta::BILLING_CYCLES,
            'domain' => '主机名',
            'ip' => 'IP',
            'username' => '客户',
        ];
    }

    /**
     * The canned suspension reasons the 暂停 dialog offers.
     */
    private function suspendReasons(): array
    {
        return [
            ['value' => 1, 'name' => '到期未付款'],
            ['value' => 2, 'name' => '管理员手动暂停'],
            ['value' => 3, 'name' => '资源超限'],
            ['value' => 4, 'name' => '违规使用'],
            ['value' => 5, 'name' => '其他原因'],
        ];
    }

    /**
     * Read the host ids from any of the shapes a bulk action posts.
     *
     * @return array<int,int>
     */
    private function hostIds(Request $request): array
    {
        $ids = $request->input('id', $request->input('hostid', $request->input('ids', [])));

        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * `{ptionId: value}` → `{option, value}` pairs.
     *
     * @return array<int,array{option:int,value:mixed}>
     */
    private function normaliseConfigOptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $value) {
            if (is_array($value) && isset($value['option'])) {
                $out[] = [
                    'option' => (int) $value['option'],
                    'value' => $value['value'] ?? [],
                    'qty' => (int) ($value['qty'] ?? 1),
                ];

                continue;
            }

            if (is_numeric($key)) {
                $out[] = [
                    'option' => (int) $key,
                    'value' => $value,
                    'qty' => is_array($value) && isset($value['qty']) ? (int) $value['qty'] : 1,
                ];
            }
        }

        return $out;
    }

    /**
     * Persist the config-option selections of one host.
     */
    private function saveHostConfigOptions(Host $host, array $raw): void
    {
        DB::table('host_config_options')->where('relid', $host->id)->delete();

        foreach ($this->normaliseConfigOptions($raw) as $selection) {
            $value = $selection['value'];

            foreach ((array) $value as $subId) {
                if (! is_numeric($subId)) {
                    continue;
                }

                DB::table('host_config_options')->insert([
                    'relid' => $host->id,
                    'configid' => (int) $selection['option'],
                    'optionid' => (int) $subId,
                    'qty' => (int) $selection['qty'],
                ]);
            }
        }
    }

    /**
     * Persist the per-host custom field values.
     */
    private function saveHostCustomFields(Host $host, array $raw): void
    {
        foreach ($raw as $fieldId => $value) {
            if (! is_numeric($fieldId)) {
                continue;
            }

            $value = is_array($value) ? implode(',', $value) : (string) $value;

            $exists = DB::table('customfieldsvalues')
                ->where('fieldid', (int) $fieldId)
                ->where('relid', $host->id)
                ->exists();

            if ($exists) {
                DB::table('customfieldsvalues')
                    ->where('fieldid', (int) $fieldId)
                    ->where('relid', $host->id)
                    ->update(['value' => $value]);
            } else {
                DB::table('customfieldsvalues')->insert([
                    'fieldid' => (int) $fieldId,
                    'relid' => $host->id,
                    'type' => 'host',
                    'value' => $value,
                ]);
            }
        }
    }
}
