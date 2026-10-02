<?php

namespace App\Http\Controllers\Web;

use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceService;
use App\Services\PricingService;
use App\Support\ApiResponse;
use App\Support\Settings;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Invoices and payments.
 *
 * Covers `/billing`, `/viewbilling`, `/combinedbilling`, `/invoicelist`,
 * `/pay` (with its `?action=` panel fragments) and `/addfunds`, plus the JSON
 * helpers the billing and payment scripts call (`/get_invoices`,
 * `/invoices/<id>`, `/get_combine_invoices`, `/check_order`, ...).
 */
class BillingController extends WebController
{
    public function __construct(
        protected InvoiceService $invoices = new InvoiceService(),
    ) {
    }

    // -----------------------------------------------------------------
    // Invoice list
    // -----------------------------------------------------------------

    public function index(Request $request): View
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $status = (string) $request->input('status', '');
        $keywords = trim((string) $request->input('keywords', ''));

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keywords !== '', function ($query) use ($keywords) {
                $query->where(function ($sub) use ($keywords) {
                    $sub->where('invoice_num', 'like', '%' . $keywords . '%')
                        ->orWhere('id', $keywords);
                });
            })
            ->orderByRaw($this->orderBy($request, ['id', 'subtotal', 'paid_time', 'due_time', 'status'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return view('web.billing.index', array_merge($this->shared(), [
            'Title' => '账单列表',
            'TplName' => 'billing',
            'bills' => $this->billRows($invoices->items()),
            'status' => $status,
            'keywords' => $keywords,
            'Total' => $invoices->total(),
            'Limit' => $invoices->perPage(),
            'Page' => $invoices->currentPage(),
            'Pages' => $invoices->lastPage(),
        ]));
    }

    /**
     * GET|POST /combinebilling — combined payment of several invoices.
     */
    public function combine(Request $request): View|RedirectResponse
    {
        $client = $this->requireClient();
        $ids = $this->invoiceIds($request);

        if ($ids === []) {
            return redirect()->to('/billing')->with('error', '请选择需要合并支付的账单');
        }

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->whereIn('id', $ids)
            ->get();

        if ($invoices->count() < 2) {
            return redirect()->to('/billing')->with('error', '至少选择两个账单才能合并支付');
        }

        if ($request->isMethod('post')) {
            return $this->combinePay($request, $invoices);
        }

        $items = InvoiceItem::query()->whereIn('invoice_id', $invoices->pluck('id'))->get()->groupBy('invoice_id');

        return view('web.billing.combine', array_merge($this->shared(), [
            'Title' => '合并支付',
            'TplName' => 'combinebilling',
            'Combine_billing' => $invoices->map(fn (Invoice $invoice) => [
                'id' => (int) $invoice->id,
                'total' => number_format((float) $invoice->total, 2, '.', ''),
                'items' => ($items[$invoice->id] ?? collect())->map(fn (InvoiceItem $item) => [
                    'description' => (string) $item->description,
                    'amount' => number_format((float) $item->amount, 2, '.', ''),
                ])->all(),
            ])->all(),
            'gateways' => $this->gatewayOptions(),
            'credit' => number_format((float) $client->credit, 2, '.', ''),
            'Total' => number_format((float) $invoices->sum('total'), 2, '.', ''),
        ]));
    }

    /**
     * POST /combine_invoices — merge the selected invoices into one.
     */
    public function combineInvoices(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $ids = $this->invoiceIds($request);

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->where('status', Invoice::STATUS_UNPAID)
            ->whereIn('id', $ids)
            ->get();

        if ($invoices->count() < 2) {
            return $this->fail('至少选择两个未支付账单');
        }

        $target = $invoices->first();

        DB::transaction(function () use ($invoices, $target, $client) {
            foreach ($invoices as $invoice) {
                if ($invoice->id === $target->id) {
                    continue;
                }

                InvoiceItem::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => $target->id]);
                $invoice->status = Invoice::STATUS_CANCELLED;
                $invoice->save();
            }

            InvoiceItem::query()->create([
                'invoice_id' => $target->id,
                'uid' => $client->id,
                'type' => 'combine',
                'rel_id' => 0,
                'description' => '合并账单（共 ' . $invoices->count() . ' 个账单）',
                'amount' => 0,
                'taxed' => 0,
                'due_time' => (int) $target->due_time,
                'notes' => '',
            ]);

            $target->recalculate();
        });

        return $this->ok([
            'invoiceid' => (int) $target->id,
            'url' => '/viewbilling?id=' . $target->id,
        ], '合并成功');
    }

    /**
     * GET /get_combine_invoices — count and total for the selection in the UI.
     */
    public function getCombineInvoices(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $ids = $this->invoiceIds($request);

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->where('status', Invoice::STATUS_UNPAID)
            ->whereIn('id', $ids)
            ->get();

        return $this->ok([
            'count' => $invoices->count(),
            'total' => number_format((float) $invoices->sum('total'), 2, '.', ''),
            'ids' => $invoices->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    // -----------------------------------------------------------------
    // Invoice detail
    // -----------------------------------------------------------------

    public function view(Request $request): View|RedirectResponse
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return redirect()->to('/billing')->with('error', '账单不存在');
        }

        $items = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->where(function ($query) {
                $query->whereNull('delete_time')->orWhere('delete_time', 0);
            })
            ->get();

        $accounts = Account::query()
            ->where('uid', $client->id)
            ->where('invoice_id', $invoice->id)
            ->get();

        return view('web.billing.view', array_merge($this->shared(), [
            'Title' => '账单详情',
            'TplName' => 'viewbilling',
            'ViewBilling' => [
                'detail' => [
                    'id' => (int) $invoice->id,
                    'invoice_num' => $invoice->invoiceNumber(),
                    'status' => (string) $invoice->status,
                    'status_zh' => StatusMap::invoiceStatus((string) $invoice->status, (int) $invoice->use_credit_limit === 1),
                    'status_color' => StatusMap::invoiceStatusColor((string) $invoice->status),
                    'companyname' => (string) ($client->companyname ?: $client->username),
                    'username' => (string) ($client->username ?: $client->email),
                    'phonenumber' => (string) $client->phonenumber,
                    'create_time' => (int) $invoice->create_time,
                    'due_time' => (int) $invoice->due_time,
                    'paid_time' => (int) $invoice->paid_time,
                    'payment_zh' => $this->gatewayTitle((string) $invoice->payment),
                    'subtotal' => number_format((float) $invoice->subtotal, 2, '.', ''),
                    'tax' => number_format((float) $invoice->tax + (float) $invoice->tax2, 2, '.', ''),
                    'total' => number_format((float) $invoice->total, 2, '.', ''),
                    'credit' => number_format((float) $invoice->credit, 2, '.', ''),
                    'url' => '/viewbilling?id=' . $invoice->id,
                ],
                'invoice_items' => $items->map(fn (InvoiceItem $item) => [
                    'type' => (string) $item->type,
                    'type_zh' => StatusMap::invoiceItemType((string) $item->type),
                    'amount' => number_format((float) $item->amount, 2, '.', ''),
                    'description' => (string) $item->description,
                ])->all(),
                'accounts' => $accounts->map(fn (Account $account) => [
                    'trans_id' => (string) $account->trans_id,
                    'amount_in' => number_format((float) $account->amount_in, 2, '.', ''),
                    'gateway' => $this->gatewayTitle((string) $account->gateway),
                    'pay_time' => (int) $account->pay_time,
                ])->all(),
                'currency' => $this->currencyPayload(),
            ],
            'Pay' => $this->payPayload($invoice, $client),
            'paymt' => [
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
                'credit_limit_balance' => number_format((float) $client->credit_limit_balance, 2, '.', ''),
                'subtotal' => number_format((float) $invoice->total, 2, '.', ''),
            ],
            'wakeup' => (int) $request->input('wakeup', 0),
        ]));
    }

    /**
     * GET /invoices/<id> — the invoice payload the SPA-style clients read.
     */
    public function read(Request $request, int $id): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = Invoice::query()->where('uid', $client->id)->find($id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        return $this->ok($this->invoicePayload($invoice));
    }

    /**
     * DELETE /invoices/<id>
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = Invoice::query()->where('uid', $client->id)->find($id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ($invoice->isPaid()) {
            return $this->fail('已支付的账单不能删除');
        }

        $invoice->is_delete = 1;
        $invoice->save();

        return $this->ok(null, '删除成功');
    }

    /**
     * GET /get_invoices — paginated invoice list as JSON.
     */
    public function listJson(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->input('status')))
            ->orderByRaw($this->orderBy($request, ['id', 'subtotal', 'paid_time', 'due_time', 'status'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return $this->ok([
            'list' => collect($invoices->items())->map(fn (Invoice $invoice) => $this->invoicePayload($invoice))->all(),
            'total' => $invoices->total(),
            'page' => $invoices->currentPage(),
            'limit' => $invoices->perPage(),
            'total_page' => $invoices->lastPage(),
        ]);
    }

    /**
     * GET /get_invoices_detail
     */
    public function detailJson(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        $items = InvoiceItem::query()->where('invoice_id', $invoice->id)->get();

        return $this->ok([
            'invoice' => $this->invoicePayload($invoice),
            'items' => $items->map(fn (InvoiceItem $item) => [
                'type' => (string) $item->type,
                'type_zh' => StatusMap::invoiceItemType((string) $item->type),
                'description' => (string) $item->description,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
            ])->all(),
        ]);
    }

    // -----------------------------------------------------------------
    // Payment
    // -----------------------------------------------------------------

    /**
     * POST|GET /pay[?action=billing|recharge]
     *
     * Returns an HTML fragment for the pay modal, or JSON when `pay=true`.
     */
    public function pay(Request $request)
    {
        $client = $this->requireClient();
        $action = (string) $request->input('action', $request->query('action', 'billing'));

        if ($action === 'recharge') {
            return $this->rechargePanel($request, $client);
        }

        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return $request->expectsJson() ? $this->fail('账单不存在') : '<div class="p-6 text-sm text-rose-600">账单不存在</div>';
        }

        if ($invoice->isPaid()) {
            return $request->expectsJson()
                ? $this->ok(['status' => 1000, 'url' => '/viewbilling?id=' . $invoice->id], '账单已支付')
                : '<div class="p-6 text-sm text-emerald-600">该账单已支付</div>';
        }

        $useCredit = (int) $request->input('use_credit', 0) === 1;
        $useCreditLimit = (int) $request->input('use_credit_limit', 0) === 1;
        $gateway = (string) $request->input('payment', '');
        $execute = (int) $request->input('pay', 0) === 1;

        // 信用额支付 is only offered when the administrator enabled it and the
        // client still has headroom.
        if ($useCreditLimit && ! $this->creditLimitAvailable($client)) {
            return $request->expectsJson() ? $this->fail('信用额不可用或额度不足') : '<div class="p-6 text-sm text-rose-600">信用额不可用或额度不足</div>';
        }

        if (! $execute) {
            return $this->fragment('web.billing._pay', [
                'Pay' => $this->payPayload($invoice, $client),
                'invoice' => $invoice,
                'selected_gateway' => $gateway,
                'use_credit' => $useCredit,
                'use_credit_limit' => $useCreditLimit,
            ]);
        }

        return $this->executePay($request, $client, $invoice, $gateway, $useCredit, $useCreditLimit);
    }

    /**
     * Actually settle an invoice: balance first, then credit limit, then the
     * chosen gateway.
     */
    protected function executePay(Request $request, Client $client, Invoice $invoice, string $gateway, bool $useCredit, bool $useCreditLimit)
    {
        $outstanding = $this->invoices->outstanding($invoice);

        if ($outstanding <= 0) {
            return $this->ok(['status' => 1000, 'url' => '/viewbilling?id=' . $invoice->id], '该账单无需支付');
        }

        if ($useCredit) {
            if (! $this->invoices->payWithCredit($invoice, $client)) {
                return $this->fail('余额不足，请选择其他支付方式');
            }

            return $this->ok([
                'status' => 1000,
                'url' => $this->returnUrl($request, $invoice),
            ], '支付成功');
        }

        if ($useCreditLimit) {
            $invoice->use_credit_limit = 1;
            $invoice->paymt = 'credit_limit';
            $invoice->save();

            $client->credit_limit_balance = PricingService::money(
                (float) $client->credit_limit_balance + $outstanding
            );
            $client->save();

            $this->invoices->markPaid($invoice, 'credit_limit');

            return $this->ok([
                'status' => 1000,
                'url' => $this->returnUrl($request, $invoice),
            ], '信用额支付成功');
        }

        if ($gateway === '') {
            return $this->fail('请选择支付方式');
        }

        $result = $this->gatewayPayment($invoice, $gateway);

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'gateway' => $gateway,
            'pay_html' => $result,
            'url' => $this->returnUrl($request, $invoice),
        ], '请完成支付');
    }

    /**
     * POST /check_order — payment status poll from the pay modal.
     */
    public function checkOrder(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = Invoice::query()->where('uid', $client->id)->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ($invoice->isPaid()) {
            return $this->ok([
                'status' => 1000,
                'url' => $this->returnUrl($request, $invoice),
            ], '支付成功');
        }

        return $this->ok(['status' => 0], '等待支付');
    }

    /**
     * POST /change_paymt — switch the modal between cash and credit limit.
     */
    public function changePaymt(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        $paymt = (int) $request->input('paymt', 0) === 1 ? 'credit_limit' : 'cash';
        $invoice->paymt = $paymt;
        $invoice->save();

        return $this->ok(['paymt' => $paymt]);
    }

    /**
     * GET /order_list — payable invoices for the pay panel.
     */
    public function orderList(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->where('status', Invoice::STATUS_UNPAID)
            ->orderBy('due_time')
            ->get();

        return $this->ok([
            'list' => $invoices->map(fn (Invoice $invoice) => $this->invoicePayload($invoice))->all(),
            'total' => number_format((float) $invoices->sum('total'), 2, '.', ''),
            'credit' => number_format((float) $client->credit, 2, '.', ''),
        ]);
    }

    /**
     * GET /recharge_page — the add-funds panel fragment.
     */
    public function rechargePage(Request $request): string
    {
        $client = $this->requireClient();

        return $this->fragment('web.billing._recharge', [
            'Addfunds' => $this->addfundsPayload($client),
        ]);
    }

    /**
     * POST /recharge — top up the balance.
     *
     * `beforeCheck=1` only validates the amount and returns the panel again.
     */
    public function recharge(Request $request)
    {
        $client = $this->requireClient();
        $amount = PricingService::money((float) $request->input('amount', 0));
        $payload = $this->addfundsPayload($client);

        if (! Settings::on('addfunds_enabled', true)) {
            return $this->fail('充值功能未开启', ApiResponse::FAIL);
        }

        if ($amount < (float) $payload['addfunds_minimum']) {
            return $this->fail('充值金额不能低于 ' . $payload['addfunds_minimum']);
        }

        if ($amount > (float) $payload['addfunds_maximum']) {
            return $this->fail('单次充值金额不能超过 ' . $payload['addfunds_maximum']);
        }

        if ((float) $client->credit + $amount > (float) $payload['addfunds_maximum_balance']) {
            return $this->fail('超出允许的余额上限');
        }

        if ((int) $request->input('beforeCheck', 0) === 1) {
            return $this->ok(['amount' => number_format($amount, 2, '.', '')], '金额校验通过');
        }

        $gateway = (string) $request->input('payment', '');

        if ($gateway === '') {
            return $this->fail('请选择支付方式');
        }

        // A recharge invoice is created first, exactly as the original does, so
        // the gateway callback has something to settle.
        $invoice = $this->invoices->create($client, [[
            'type' => 'recharge',
            'rel_id' => 0,
            'description' => '账户充值',
            'amount' => $amount,
        ]], time() + 3600, 'recharge', ['is_cron' => 1]);

        $result = $this->gatewayPayment($invoice, $gateway);

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'amount' => number_format($amount, 2, '.', ''),
            'gateway' => $gateway,
            'pay_html' => $result,
        ], '请完成支付');
    }

    /**
     * GET /use_credit_page — credit-limit repayment panel.
     */
    public function useCreditPage(Request $request): string
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        return $this->fragment('web.billing._credit_limit', [
            'invoice' => $invoice,
            'credit_limit_balance' => number_format((float) $client->credit_limit_balance, 2, '.', ''),
            'currency' => $this->currencyPayload(),
        ]);
    }

    /**
     * POST /invoice_page — one invoice rendered for a modal.
     */
    public function invoicePage(Request $request): string
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return '<div class="p-6 text-sm text-rose-600">账单不存在</div>';
        }

        return $this->fragment('web.billing._invoice_row', [
            'invoice' => $invoice,
            'total' => number_format((float) $invoice->total, 2, '.', ''),
            'currency' => $this->currencyPayload(),
            'created' => (int) $invoice->create_time,
            'due' => (int) $invoice->due_time,
        ]);
    }

    /**
     * POST /apply_credit — apply the outstanding balance to an invoice.
     */
    public function applyCredit(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if (! $this->invoices->payWithCredit($invoice, $client)) {
            return $this->fail('余额不足');
        }

        return $this->ok(['url' => $this->returnUrl($request, $invoice)], '支付成功');
    }

    /**
     * POST /apply_credit_limit — settle with credit limit.
     */
    public function applyCreditLimit(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if (! $this->creditLimitAvailable($client)) {
            return $this->fail('信用额不可用或额度不足');
        }

        $outstanding = $this->invoices->outstanding($invoice);

        $invoice->use_credit_limit = 1;
        $invoice->paymt = 'credit_limit';
        $invoice->save();

        $client->credit_limit_balance = PricingService::money((float) $client->credit_limit_balance + $outstanding);
        $client->save();

        $this->invoices->markPaid($invoice, 'credit_limit');

        return $this->ok(['url' => $this->returnUrl($request, $invoice)], '信用额支付成功');
    }

    /**
     * POST /credit_limit/prepayment — move a credit-limit invoice to prepaid.
     */
    public function prepayment(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $invoice = $this->findInvoice($request, $client->id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ((int) $invoice->use_credit_limit !== 1) {
            return $this->fail('该账单不是信用额账单');
        }

        $outstanding = $this->invoices->outstanding($invoice);

        $invoice->use_credit_limit = 0;
        $invoice->paymt = 'cash';
        $invoice->credit_limit_prepayment = 1;
        $invoice->save();

        $client->credit_limit_balance = PricingService::money(
            max(0, (float) $client->credit_limit_balance - $outstanding)
        );
        $client->save();

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'amount' => number_format($outstanding, 2, '.', ''),
        ], '已转为提前还款');
    }

    // -----------------------------------------------------------------
    // Add funds
    // -----------------------------------------------------------------

    public function addfunds(Request $request): View
    {
        $client = $this->requireClient();

        return view('web.billing.addfunds', array_merge($this->shared(), [
            'Title' => '账户充值',
            'TplName' => 'addfunds',
            'Addfunds' => ['addfunds' => $this->addfundsPayload($client)],
        ]));
    }

    /**
     * GET /finance_record — the account ledger behind /transaction.
     */
    public function financeRecord(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $rows = Account::query()
            ->where('uid', $client->id)
            ->orderByRaw($this->orderBy($request, ['id', 'invoice_id', 'amount_in', 'amount_out', 'pay_time', 'trans_id'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return $this->ok([
            'list' => $rows->map(fn (Account $row) => $this->accountPayload($row))->all(),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'limit' => $rows->perPage(),
            'total_page' => $rows->lastPage(),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function billRows(array $invoices): array
    {
        return array_map(function (Invoice $invoice) {
            return [
                'id' => (int) $invoice->id,
                'invoice_num' => $invoice->invoiceNumber(),
                'type' => (string) $invoice->type,
                'type_zh' => StatusMap::invoiceItemType((string) ($invoice->type ?: 'product')),
                'subtotal' => number_format((float) $invoice->total, 2, '.', ''),
                'create_time' => (int) $invoice->create_time,
                'paid_time' => (int) $invoice->paid_time,
                'due_time' => (int) $invoice->due_time,
                'payment_zh' => $this->gatewayTitle((string) $invoice->payment),
                'status' => (string) $invoice->status,
                'status_zh' => [
                    'name' => StatusMap::invoiceStatus((string) $invoice->status, (int) $invoice->use_credit_limit === 1),
                    'color' => StatusMap::invoiceStatusColor((string) $invoice->status),
                ],
                'use_credit_limit' => (int) $invoice->use_credit_limit,
            ];
        }, $invoices);
    }

    protected function invoicePayload(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_num' => $invoice->invoiceNumber(),
            'subtotal' => number_format((float) $invoice->subtotal, 2, '.', ''),
            'total' => number_format((float) $invoice->total, 2, '.', ''),
            'credit' => number_format((float) $invoice->credit, 2, '.', ''),
            'status' => (string) $invoice->status,
            'status_zh' => StatusMap::invoiceStatus((string) $invoice->status, (int) $invoice->use_credit_limit === 1),
            'type' => (string) $invoice->type,
            'payment' => (string) $invoice->payment,
            'create_time' => (int) $invoice->create_time,
            'due_time' => (int) $invoice->due_time,
            'paid_time' => (int) $invoice->paid_time,
            'use_credit_limit' => (int) $invoice->use_credit_limit,
        ];
    }

    protected function accountPayload(Account $row): array
    {
        $incoming = (float) $row->amount_in > 0;

        return [
            'id' => (int) $row->id,
            'invoice_id' => (int) $row->invoice_id,
            'amount_in' => number_format((float) $row->amount_in, 2, '.', ''),
            'amount_out' => number_format((float) $row->amount_out, 2, '.', ''),
            'amount' => number_format($incoming ? (float) $row->amount_in : (float) $row->amount_out, 2, '.', ''),
            'refund' => (int) $row->refund,
            'description' => (string) $row->description,
            'payment_zh' => $this->gatewayTitle((string) $row->gateway),
            'type_zh' => $incoming ? '收入' : '支出',
            'pay_time' => (int) $row->pay_time,
            'create_time' => (int) $row->create_time,
            'trans_id' => (string) $row->trans_id,
        ];
    }

    /**
     * The `$Pay` payload rendered into the payment modal.
     */
    protected function payPayload(Invoice $invoice, Client $client): array
    {
        $outstanding = $this->invoices->outstanding($invoice);

        return [
            'invoiceid' => (int) $invoice->id,
            'total' => number_format($outstanding, 2, '.', ''),
            'PayStatus' => (string) $invoice->status,
            'payment' => (string) $invoice->payment,
            'gateway_list' => $this->gatewayOptions(),
            'credit' => number_format((float) $client->credit, 2, '.', ''),
            'credit_enough' => (float) $client->credit >= $outstanding,
            'use_credit' => (float) $client->credit > 0,
            'use_credit_limit' => $this->creditLimitAvailable($client),
            'credit_limit_balance' => number_format((float) $client->credit_limit_balance, 2, '.', ''),
            'currency' => $this->currencyPayload(),
            'pay_html' => ['type' => '', 'data' => ''],
        ];
    }

    /**
     * Whether the client may still draw on their credit limit.
     */
    protected function creditLimitAvailable(?Client $client): bool
    {
        if ($client === null || (int) $client->is_open_credit_limit !== 1) {
            return false;
        }

        return (float) $client->credit_limit_balance < (float) $client->credit_limit;
    }

    /**
     * Build the gateway hand-off payload.
     *
     * No gateway plugins ship with this build, so the response tells the caller
     * how the payment would be completed rather than pretending to process it.
     *
     * @return array{type:string, data:string}
     */
    protected function gatewayPayment(Invoice $invoice, string $gateway): array
    {
        $row = \App\Models\PaymentGateway::query()->find($gateway);

        if ($row === null) {
            return ['type' => 'html', 'data' => '支付方式不可用，请联系管理员'];
        }

        $settings = $row->settings();
        $authorUrl = (string) ($settings['author_url'] ?? '');

        if ($authorUrl !== '') {
            $url = $authorUrl . (str_contains($authorUrl, '?') ? '&' : '?')
                . http_build_query([
                    'invoiceid' => $invoice->id,
                    'amount' => number_format((float) $invoice->total, 2, '.', ''),
                    'return_url' => url('/check_order'),
                ]);

            return ['type' => 'jump', 'data' => $url];
        }

        return [
            'type' => 'html',
            'data' => sprintf(
                '<div class="text-sm text-slate-600">请通过 %s 完成支付，账单号 #%s，金额 %s。</div>',
                htmlspecialchars($row->displayName(), ENT_QUOTES),
                htmlspecialchars($invoice->invoiceNumber(), ENT_QUOTES),
                htmlspecialchars(number_format((float) $invoice->total, 2, '.', ''), ENT_QUOTES)
            ),
        ];
    }

    protected function gatewayTitle(string $gateway): string
    {
        if ($gateway === '' || $gateway === 'credit') {
            return $gateway === 'credit' ? '余额支付' : '';
        }

        if ($gateway === 'credit_limit') {
            return '信用额支付';
        }

        $row = \App\Models\PaymentGateway::query()->find($gateway);

        return $row?->displayName() ?? $gateway;
    }

    protected function addfundsPayload(Client $client): array
    {
        return [
            'credit' => number_format((float) $client->credit, 2, '.', ''),
            'currency' => $this->currencyPayload(),
            'addfunds_minimum' => number_format(Settings::float('addfunds_minimum', 1.0), 2, '.', ''),
            'addfunds_maximum' => number_format(Settings::float('addfunds_maximum', 100000.0), 2, '.', ''),
            'addfunds_maximum_balance' => number_format(Settings::float('addfunds_maximum_balance', 1000000.0), 2, '.', ''),
            'gateways' => $this->gatewayOptions(),
            'enabled' => Settings::on('addfunds_enabled', true),
        ];
    }

    /**
     * Where the client lands after a successful payment; mirrors the original's
     * `$ReturnUrl` → invoice url → service list preference chain.
     */
    protected function returnUrl(Request $request, Invoice $invoice): string
    {
        $submitted = (string) $request->input('return_url', $request->input('url', ''));

        if ($submitted !== '' && str_starts_with($submitted, '/') && ! str_starts_with($submitted, '//')) {
            return $submitted;
        }

        if ((string) $invoice->type === 'recharge') {
            return '/addfunds';
        }

        return '/viewbilling?id=' . $invoice->id;
    }

    protected function findInvoice(Request $request, int $clientId): ?Invoice
    {
        $id = (int) $request->input('id', $request->input('invoiceid', 0));

        if ($id <= 0) {
            return null;
        }

        return Invoice::query()->where('uid', $clientId)->where('is_delete', 0)->find($id);
    }

    /**
     * @return array<int, int>
     */
    protected function invoiceIds(Request $request): array
    {
        $raw = $request->input('ids', []);

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_filter(array_map('intval', (array) $raw), fn ($id) => $id > 0));
    }

    /**
     * POST /combinebilling — pay several invoices in one go.
     */
    protected function combinePay(Request $request, $invoices): RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $target = $invoices->first();

        DB::transaction(function () use ($invoices, $target, $client) {
            foreach ($invoices as $invoice) {
                if ($invoice->id === $target->id) {
                    continue;
                }

                InvoiceItem::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => $target->id]);
                $invoice->status = Invoice::STATUS_CANCELLED;
                $invoice->save();
            }

            $target->recalculate();
        });

        return redirect()->to('/viewbilling?id=' . $target->id . '&wakeup=1');
    }
}
