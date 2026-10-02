<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Account;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoices, balance, credit limit and payment entry points.
 *
 * Invoice line amounts, the balance ledger (`shd_accounts`) and the credit
 * ledger (`shd_credit`) are all kept in step with the original's behaviour:
 * applying balance to an invoice writes an `amount_out` row, removing it
 * writes the matching refund row.
 */
class FinanceController extends ApiController
{
    /** Balance source markers stored on `shd_accounts`.refund. */
    protected const REFUND_NONE = 0;

    /** Status codes the payment-poll endpoint answers with. */
    protected const PAY_PENDING = 1000;
    protected const PAY_DONE = 200;

    public function __construct(
        protected InvoiceService $invoices = new InvoiceService(),
        protected PaymentService $payments = new PaymentService(),
    ) {
    }

    /* ---------------------------------------------------------------------
     | Invoice detail / merge
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/invoices/{id}
     */
    public function show(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $invoice = $this->findInvoice($client, $id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        $currency = Currency::default();
        $invoice->load('items');

        $items = $invoice->items->map(fn (InvoiceItem $item) => [
            'id' => (int) $item->id,
            'type' => $this->itemType($item),
            'description' => (string) $item->description,
            'amount' => $this->money((float) $item->amount),
            'rel_id' => (int) $item->rel_id,
            'delete_time' => (int) $item->delete_time,
        ])->values()->all();

        $accounts = Account::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Account $account) => [
                'trans_id' => (string) $account->trans_id,
                'amount_in' => $this->money((float) $account->amount_in),
                'amount_out' => $this->money((float) $account->amount_out),
                'gateway' => (string) $account->gateway,
                'pay_time' => (int) ($account->pay_time ?: $account->create_time),
                'description' => (string) $account->description,
            ])
            ->values()
            ->all();

        $payload = [
            'id' => (int) $invoice->id,
            'logo' => (string) Configuration::value('logo_url', ''),
            'username' => (string) ($client->username ?: $client->email),
            'companyname' => (string) Configuration::value('company_name', ''),
            'create_time' => (int) $invoice->create_time,
            'due_time' => (int) $invoice->due_time,
            'paid_time' => (int) $invoice->paid_time,
            'status' => (string) $invoice->status,
            'status_zh' => $this->statusLabel((string) $invoice->status),
            'type' => (string) $invoice->type,
            'subtotal' => $this->money((float) $invoice->subtotal),
            'tax' => $this->money((float) $invoice->tax),
            'credit' => $this->money((float) $invoice->credit),
            'total' => $this->money((float) $invoice->total),
            'outstanding' => $this->money($this->invoices->outstanding($invoice)),
            'payment' => (string) $invoice->payment,
            'invoice_items' => $items,
            'currency' => $this->currencyPayload(),
            'accounts' => $accounts,
        ];

        // Gateways are only meaningful while there is something to pay.
        if ($invoice->isUnpaid()) {
            $payload['gateways'] = $this->gateways();
            $payload['client'] = [
                'credit' => $this->money((float) $client->credit),
                'credit_limit' => $this->money((float) $client->credit_limit),
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
                'amount_to_be_settled' => $this->money($this->settledCreditLimit($client)),
                'credit_limit_used' => $this->money($this->usedCreditLimit($client)),
                'credit_limit_balance' => $this->money(max(0, (float) $client->credit_limit - $this->usedCreditLimit($client))),
            ];
        }

        return $this->ok($payload);
    }

    /**
     * POST /v1/invoices/combines — merge unpaid invoices.
     *
     * Every merged invoice keeps its line items but is moved onto the new
     * invoice, and the original → new mapping is recorded in
     * `shd_invoicesid_tmp` so the original can be restored on removal.
     */
    public function combine(Request $request)
    {
        $client = $this->requireClient($request);
        $ids = $this->idsFrom($request, 'ids');

        if (count($ids) < 2) {
            return $this->fail('请选择至少两个账单进行合并');
        }

        $invoices = Invoice::query()
            ->whereIn('id', $ids)
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->get();

        if ($invoices->count() !== count($ids)) {
            return $this->fail('账单不存在或不属于当前用户');
        }

        foreach ($invoices as $invoice) {
            if (! $invoice->isUnpaid()) {
                return $this->fail('账单 #' . $invoice->id . ' 不是未支付状态，无法合并');
            }

            if ($this->invoices->outstanding($invoice) <= 0) {
                return $this->fail('账单 #' . $invoice->id . ' 没有待支付金额');
            }
        }

        $merged = DB::transaction(function () use ($client, $invoices) {
            $total = 0.0;

            foreach ($invoices as $invoice) {
                $total += (float) $invoice->total;
            }

            $new = $this->invoices->create($client, [], time() + 86400 * 7, 'combine', [
                'notes' => '合并账单：' . $invoices->pluck('id')->implode(', '),
            ]);

            // The new invoice's total is the sum of the merged ones, so the
            // empty item list is corrected right after creation.
            $new->subtotal = PricingService::money($total);
            $new->tax = 0;
            $new->tax2 = 0;
            $new->total = PricingService::money($total);
            $new->save();

            foreach ($invoices as $invoice) {
                InvoiceItem::query()->where('invoice_id', $invoice->id)->update([
                    'invoice_id' => $new->id,
                ]);

                DB::table('invoicesid_tmp')->insert([
                    'original_invoicesid' => (int) $invoice->id,
                    'old_invoicesid' => (int) $invoice->id,
                    'new_invoicesid' => (int) $new->id,
                    'total' => PricingService::money((float) $invoice->total),
                ]);

                $invoice->status = Invoice::STATUS_CANCELLED;
                $invoice->notes = trim((string) $invoice->notes . "\n合并至账单 #" . $new->id);
                $invoice->save();
            }

            $new->recalculate();

            return $new;
        });

        return $this->ok([
            'id' => (int) $merged->id,
            'invoice_id' => (int) $merged->id,
            'total' => $this->money((float) $merged->total),
        ], '合并成功');
    }

    /* ---------------------------------------------------------------------
     | Balance / credit
     | ------------------------------------------------------------------ */

    /**
     * POST /v1/invoices/{id}/fund — apply the client balance to an invoice.
     *
     * Balance is applied partially: when it does not cover the whole invoice
     * the remainder still has to be paid through a gateway, which the
     * controller reports with the platform's `1001` soft-success status.
     */
    public function fund(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $invoice = $this->findInvoice($client, $id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ($invoice->isPaid()) {
            return $this->fail('账单已支付');
        }

        $outstanding = $this->invoices->outstanding($invoice);

        if ($outstanding <= 0) {
            return $this->ok(['invoiceid' => (int) $invoice->id, 'url' => $this->payments->returnUrl($invoice)], '无需支付');
        }

        $credit = (float) $client->credit;

        if ($credit <= 0) {
            return $this->fail('余额不足');
        }

        $applied = PricingService::money(min($credit, $outstanding));

        DB::transaction(function () use ($client, $invoice, $applied) {
            $client->credit = PricingService::money((float) $client->credit - $applied);
            $client->save();

            $invoice->credit = PricingService::money((float) $invoice->credit + $applied);
            $invoice->save();

            Credit::create([
                'uid' => (int) $client->id,
                'create_time' => time(),
                'description' => '账单余额支付 #' . $invoice->invoiceNumber(),
                'amount' => PricingService::money(-$applied),
                'relid' => (int) $invoice->id,
                'aff_refund' => 0,
                'notes' => '',
                'balance' => PricingService::money((float) $client->credit),
            ]);

            Account::create([
                'uid' => (int) $client->id,
                'currency' => (string) (Currency::default()?->code ?? 'CNY'),
                'gateway' => 'credit',
                'create_time' => time(),
                'update_time' => time(),
                'pay_time' => time(),
                'description' => '账单使用余额 #' . $invoice->invoiceNumber(),
                'amount_in' => 0,
                'fees' => 0,
                'amount_out' => $applied,
                'rate' => 1,
                'trans_id' => '',
                'invoice_id' => (int) $invoice->id,
                'refund' => self::REFUND_NONE,
                'delete_time' => 0,
                'aff_refund' => 0,
                'refund_credit' => 0,
            ]);
        });

        $invoice = $invoice->fresh();
        $client = $client->fresh();

        // Fully covered by balance: settle the invoice outright.
        if ($this->invoices->outstanding($invoice) <= 0) {
            $this->invoices->markPaid($invoice, 'credit');

            return $this->ok([
                'invoiceid' => (int) $invoice->id,
                'url' => $this->payments->returnUrl($invoice),
                'credit' => $this->money((float) $client->credit),
            ], '支付完成');
        }

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'url' => $this->payments->returnUrl($invoice),
            'hostid' => $this->payments->relatedHostIds($invoice),
            'credit' => $this->money((float) $client->credit),
            'outstanding' => $this->money($this->invoices->outstanding($invoice)),
            'prompt' => 1,
        ], '余额已部分支付，请继续支付剩余金额');
    }

    /**
     * DELETE /v1/invoices/{id}/fund — remove balance previously applied.
     */
    public function fundDelete(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $invoice = $this->findInvoice($client, $id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ($invoice->isPaid()) {
            return $this->fail('账单已支付，无法取消余额支付');
        }

        if ((float) $invoice->credit <= 0) {
            return $this->fail('该账单未使用余额');
        }

        $amount = PricingService::money((float) $invoice->credit);

        DB::transaction(function () use ($client, $invoice, $amount) {
            $client->credit = PricingService::money((float) $client->credit + $amount);
            $client->save();

            $invoice->credit = 0;
            $invoice->save();

            // Total is denormalised the way the original keeps it, so it has
            // to follow the reversal.
            $invoice->recalculate();

            Credit::create([
                'uid' => (int) $client->id,
                'create_time' => time(),
                'description' => '账单取消余额支付 #' . $invoice->invoiceNumber(),
                'amount' => $amount,
                'relid' => (int) $invoice->id,
                'aff_refund' => 0,
                'notes' => '',
                'balance' => PricingService::money((float) $client->credit),
            ]);

            // The matching ledger row is removed so the transaction list
            // no longer reports money that was never spent.
            Account::query()
                ->where('invoice_id', $invoice->id)
                ->where('gateway', 'credit')
                ->where('amount_out', '>', 0)
                ->where('delete_time', 0)
                ->update(['delete_time' => time()]);
        });

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'credit' => $this->money((float) $client->fresh()->credit),
        ], '已取消余额支付');
    }

    /**
     * POST /v1/invoices/{id}/credit — settle with the credit limit.
     */
    public function credit(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $invoice = $this->findInvoice($client, $id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ((int) $client->is_open_credit_limit !== 1) {
            return $this->fail('您的账号未开通信用额');
        }

        if ($invoice->isPaid()) {
            return $this->fail('账单已支付');
        }

        $outstanding = $this->invoices->outstanding($invoice);

        if ($outstanding <= 0) {
            return $this->ok(['invoiceid' => (int) $invoice->id, 'url' => $this->payments->returnUrl($invoice)], '无需支付');
        }

        $available = PricingService::money(max(0, (float) $client->credit_limit - $this->usedCreditLimit($client)));

        if ($available < $outstanding) {
            return $this->fail('信用额不足，剩余可用信用额 ' . $this->money($available));
        }

        DB::transaction(function () use ($client, $invoice, $outstanding) {
            $invoice->use_credit_limit = 1;
            $invoice->save();

            // The remaining allowance is derived from the settled invoices, so
            // the cached balance column is kept in step for the admin panel.
            $balance = PricingService::money(
                max(0, (float) $client->credit_limit - $this->usedCreditLimit($client))
            );

            $client->credit_limit_balance = $balance;
            $client->save();

            DB::table('credit_limit')->insert([
                'uid' => (int) $client->id,
                'create_time' => time(),
                'description' => '账单信用额支付 #' . $invoice->invoiceNumber(),
                'type' => 'consume',
                'notes' => '',
                'handle_id' => 0,
                'ip' => (string) request()->ip(),
            ]);
        });

        $this->invoices->markPaid($invoice, 'credit_limit');

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'url' => $this->payments->returnUrl($invoice->refresh()),
            'credit_limit_balance' => $this->money((float) $client->fresh()->credit_limit_balance),
        ], '支付完成');
    }

    /* ---------------------------------------------------------------------
     | Recharge / transactions
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/funds — recharge page information.
     */
    public function fundsInfo(Request $request)
    {
        $client = $this->requireClient($request);
        $currency = Currency::default();

        $invoices = Invoice::query()
            ->where('uid', $client->id)
            ->where('type', 'recharge')
            ->where('is_delete', 0)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $accounts = Account::query()
            ->where('uid', $client->id)
            ->where('delete_time', 0)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->ok([
            'currency' => $this->currencyPayload($currency),
            'allow_recharge' => (int) Configuration::value('addfunds_enabled', 1),
            'credit' => $this->money((float) $client->credit),
            'gateways' => $this->gateways(),
            'addfunds_minimum' => $this->money((float) Configuration::value('addfunds_minimum', 1)),
            'addfunds_maximum' => $this->money((float) Configuration::value('addfunds_maximum', 100000)),
            'addfunds_maximum_balance' => $this->money((float) Configuration::value('addfunds_maximum_balance', 1000000)),
            'count' => $invoices->count(),
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'trans_id' => (string) $invoice->invoiceNumber(),
                'amount_in' => $this->money((float) $invoice->total),
                'pay_time' => (int) $invoice->paid_time,
                'gateway' => (string) $invoice->payment,
                'amount_out' => '0.00',
                'invoice_id' => (int) $invoice->id,
                'description' => '用户充值',
                'type' => '充值',
            ])->values()->all(),
            'accounts' => $accounts->map(fn (Account $account) => $this->accountPayload($account))->values()->all(),
        ]);
    }

    /**
     * POST /v1/funds — create a recharge invoice for the given amount.
     */
    public function funds(Request $request)
    {
        $client = $this->requireClient($request);

        if ((int) Configuration::value('addfunds_enabled', 1) !== 1) {
            return $this->fail('充值功能已关闭');
        }

        $amount = round((float) $request->input('amount', 0), 2);
        $minimum = (float) Configuration::value('addfunds_minimum', 1);
        $maximum = (float) Configuration::value('addfunds_maximum', 100000);
        $balanceCap = (float) Configuration::value('addfunds_maximum_balance', 1000000);

        if ($amount < $minimum) {
            return $this->fail('充值金额不能低于 ' . $this->money($minimum));
        }

        if ($amount > $maximum) {
            return $this->fail('充值金额不能高于 ' . $this->money($maximum));
        }

        if ((float) $client->credit + $amount > $balanceCap) {
            return $this->fail('超出允许的余额上限');
        }

        $invoice = $this->invoices->create($client, [[
            'type' => 'credit',
            'rel_id' => 0,
            'description' => '账户充值 ' . $this->money($amount),
            'amount' => PricingService::money($amount),
            'taxed' => 0,
        ]], time() + 86400, 'recharge');

        return $this->ok([
            'invoice_id' => (int) $invoice->id,
            'invoiceid' => (int) $invoice->id,
            'amount' => $this->money($amount),
            'payment' => (string) $request->input('payment', ''),
        ], '充值订单已生成');
    }

    /**
     * GET /v1/transactions/funds — paginated transaction ledger.
     *
     * `type` filters the ledger: recharge (充值), consume (消费), refund
     * (退款), withdraw (提现) and credit (余额).
     */
    public function accountsRecord(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $type = (string) $request->input('type', '');

        if ($type === 'credit') {
            return $this->creditRecord($client, $page, $limit, $request);
        }

        $query = Account::query()
            ->where('uid', $client->id)
            ->where('delete_time', 0);

        switch ($type) {
            case 'recharge':
                $query->where('amount_in', '>', 0)->where('gateway', '<>', 'credit');
                break;
            case 'consume':
                $query->where('amount_out', '>', 0);
                break;
            case 'refund':
                $query->where('refund', '<>', self::REFUND_NONE);
                break;
            case 'withdraw':
                $query->where(function ($sub) {
                    $sub->where('gateway', 'withdraw')->orWhere('description', 'like', '%提现%');
                });
                break;
            case '':
                break;
            default:
                $query->where('gateway', $type);
                break;
        }

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where(function ($sub) use ($keywords) {
                $sub->where('description', 'like', '%' . $keywords . '%')
                    ->orWhere('trans_id', 'like', '%' . $keywords . '%')
                    ->orWhere('invoice_id', $keywords);
            });
        }

        if ($request->filled('start_time')) {
            $query->where('pay_time', '>=', (int) $request->input('start_time'));
        }

        if ($request->filled('end_time')) {
            $query->where('pay_time', '<=', (int) $request->input('end_time'));
        }

        $orderby = (string) $request->input('orderby', 'id');
        $sort = strtoupper((string) $request->input('sort', 'DESC')) === 'ASC' ? 'asc' : 'desc';

        if (! preg_match('/^[A-Za-z0-9_]+$/', $orderby)) {
            $orderby = 'id';
        }

        $paginator = $query->orderBy($orderby, $sort)->paginate($limit, ['*'], 'page', $page);

        return $this->paginated($paginator, fn (Account $account) => $this->accountPayload($account), [
            'currency' => $this->currencyPayload(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Payment
     | ------------------------------------------------------------------ */

    /**
     * POST /v1/pay — start a payment for an invoice.
     */
    public function pay(Request $request)
    {
        $client = $this->requireClient($request);

        $invoiceId = (int) $request->input('invoiceid', $request->input('invoice_id', 0));
        $gateway = trim((string) $request->input('payment', $request->input('gateway', '')));

        $invoice = $this->findInvoice($client, $invoiceId);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        if ($invoice->isPaid()) {
            return $this->fail('账单已支付');
        }

        // `use_credit` settles as much as possible from the balance first.
        if ((int) $request->input('use_credit', 0) === 1 && (float) $client->credit > 0) {
            $fundResponse = $this->fund($request, $invoiceId);

            if ((int) $fundResponse->getData(true)['status'] !== \App\Support\ApiResponse::OK) {
                return $fundResponse;
            }

            $invoice = $invoice->fresh();

            if ($invoice->isPaid()) {
                $data = $fundResponse->getData(true)['data'];

                return $this->ok(array_merge($data, [
                    'payment' => 'credit',
                    'total' => '0.00',
                    'pay_html' => ['type' => 'url', 'data' => $this->payments->returnUrl($invoice)],
                    'gateway_list' => $this->gateways(),
                ]), '支付完成');
            }
        }

        $result = $this->payments->pay($invoice, $client, $gateway);

        if (! $result['status']) {
            // Nothing left to pay is a soft success in the original.
            if (($result['data']['code'] ?? null) === 1001) {
                return response()->json(\App\Support\ApiResponse::success([
                    'invoiceid' => (int) $invoice->id,
                    'url' => $this->payments->returnUrl($invoice),
                ], $result['msg'], ['status' => 1001]));
            }

            return $this->fail($result['msg']);
        }

        $data = $result['data'];

        return $this->ok([
            'payment' => $gateway !== '' ? $gateway : PaymentService::OFFLINE,
            'total' => $this->money($this->invoices->outstanding($invoice)),
            'total_desc' => $this->money($this->invoices->outstanding($invoice)) . (string) (Currency::default()?->suffix ?? ''),
            'credit' => $this->money((float) $client->credit),
            'invoiceid' => (int) $invoice->id,
            'pay_html' => $data['pay_html'] ?? null,
            'trans_id' => $data['trans_id'] ?? 0,
            'gateway_list' => $this->gateways(),
            'is_open_shd_credit_limit' => (int) $client->is_open_credit_limit,
            'client' => [
                'credit' => $this->money((float) $client->credit),
                'credit_limit' => $this->money((float) $client->credit_limit),
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
                'amount_to_be_settled' => $this->money($this->settledCreditLimit($client)),
                'credit_limit_used' => $this->money($this->usedCreditLimit($client)),
                'credit_limit_balance' => $this->money(max(0, (float) $client->credit_limit - $this->usedCreditLimit($client))),
            ],
        ], $result['msg']);
    }

    /**
     * GET /v1/invoices/{id}/status — payment polling.
     */
    public function status(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $invoice = $this->findInvoice($client, $id);

        if ($invoice === null) {
            return $this->fail('账单不存在');
        }

        $status = $this->payments->status($invoice);

        return $this->ok([
            'invoiceid' => (int) $invoice->id,
            'status' => $status['code'] === self::PAY_DONE ? self::PAY_DONE : self::PAY_PENDING,
            'paid' => $status['paid'],
            'url' => $status['url'],
            'hid' => $status['hid'],
        ], $status['msg']);
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    /**
     * Balance ledger for the "余额" tab of the transaction page.
     */
    protected function creditRecord(Client $client, int $page, int $limit, Request $request)
    {
        $query = Credit::query()->where('uid', $client->id);

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where('description', 'like', '%' . $keywords . '%');
        }

        $paginator = $query->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);

        return $this->paginated($paginator, fn (Credit $credit) => [
            'id' => (int) $credit->id,
            'invoice_id' => (int) $credit->relid,
            'amount' => $this->money((float) $credit->amount),
            'balance' => $this->money((float) $credit->balance),
            'description' => (string) $credit->description,
            'type' => (float) $credit->amount >= 0 ? 'recharge' : 'consume',
            'pay_time' => (int) $credit->create_time,
            'trans_id' => '',
            'amount_in' => $this->money(max(0, (float) $credit->amount)),
            'amount_out' => (float) $credit->amount < 0 ? $this->money(abs((float) $credit->amount)) : '0.00',
        ], ['currency' => $this->currencyPayload()]);
    }

    protected function accountPayload(Account $account): array
    {
        $refunds = Account::query()
            ->where('invoice_id', $account->invoice_id)
            ->where('refund', '<>', self::REFUND_NONE)
            ->where('delete_time', 0)
            ->get()
            ->map(fn (Account $refund) => [
                'id' => (int) $refund->id,
                'amount_out' => $this->money((float) $refund->amount_out),
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $account->id,
            'invoice_id' => (int) $account->invoice_id,
            'pay_time' => (int) ($account->pay_time ?: $account->create_time),
            'payment' => (string) $account->gateway,
            'payment_zh' => $this->gatewayTitle((string) $account->gateway),
            'description' => (string) $account->description,
            'type' => $this->accountType($account),
            'trans_id' => (string) $account->trans_id,
            'amount_in' => $this->money((float) $account->amount_in),
            'amount_out' => $this->money((float) $account->amount_out),
            'refund' => $refunds,
        ];
    }

    protected function accountType(Account $account): string
    {
        if ((int) $account->refund !== self::REFUND_NONE) {
            return 'refund';
        }

        if ((string) $account->gateway === 'withdraw') {
            return 'withdraw';
        }

        if ((float) $account->amount_out > 0) {
            return 'consume';
        }

        $invoice = $account->invoice;

        return $invoice !== null && (string) $invoice->type === 'recharge' ? 'recharge' : 'consume';
    }

    protected function gatewayTitle(string $gateway): string
    {
        $row = PaymentGateway::query()->where('gateway', $gateway)->first();

        if ($row !== null) {
            return $row->displayName();
        }

        return match ($gateway) {
            'credit' => '余额支付',
            'credit_limit' => '信用额支付',
            PaymentService::OFFLINE => '对公转账',
            default => $gateway,
        };
    }

    protected function itemType(InvoiceItem $item): string
    {
        return match ((string) $item->type) {
            'hosting' => 'host',
            'configoptions' => 'upgrade',
            default => (string) $item->type,
        };
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_PAID => '已支付',
            Invoice::STATUS_UNPAID => '未支付',
            Invoice::STATUS_REFUNDED => '已退款',
            Invoice::STATUS_CANCELLED => '被取消',
            'Draft' => '已草稿',
            'Overdue' => '已逾期',
            Invoice::STATUS_COLLECTIONS => '已收藏',
            default => $status,
        };
    }

    protected function currencyPayload(?Currency $currency = null): array
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

    protected function gateways(): array
    {
        return array_map(function (array $gateway) {
            return [
                'id' => $gateway['id'],
                'name' => $gateway['name'],
                'title' => $gateway['title'],
                'url' => $gateway['url'],
                'author_url' => $gateway['author_url'],
            ];
        }, $this->payments->gateways());
    }

    /**
     * Credit limit already consumed: settled invoices plus unpaid ones using
     * the limit, matching `credit_limit_used` in the original payload.
     */
    protected function usedCreditLimit(Client $client): float
    {
        $used = Invoice::query()
            ->where('uid', $client->id)
            ->where('use_credit_limit', 1)
            ->where('is_delete', 0)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
            ->sum('total');

        return PricingService::money((float) $used);
    }

    /**
     * Credit limit already repaid by the client.
     */
    protected function settledCreditLimit(Client $client): float
    {
        $settled = Invoice::query()
            ->where('uid', $client->id)
            ->where('use_credit_limit', 1)
            ->where('is_delete', 0)
            ->where('status', Invoice::STATUS_PAID)
            ->sum('total');

        return PricingService::money((float) $settled);
    }

    /**
     * Invoice belonging to the client, or null.
     */
    protected function findInvoice(Client $client, int $id): ?Invoice
    {
        if ($id <= 0) {
            return null;
        }

        return Invoice::query()
            ->where('id', $id)
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->first();
    }

    protected function idsFrom(Request $request, string $key): array
    {
        $raw = $request->input($key, []);

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_filter(array_map('intval', (array) $raw), fn ($id) => $id > 0));
    }
}
