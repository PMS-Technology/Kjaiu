<?php

namespace App\Http\Controllers\Web;

use App\Models\Account;
use App\Models\Credit;
use App\Models\Invoice;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Transaction records (`/transaction`).
 *
 * Six views share the page, switched by `?action=`:
 * accounts_record (交易流水), credit_record (余额), credit_limit (信用额),
 * recharge_record (充值), refund_record (退款), withdraw_record (提现).
 * Each also has a JSON route so the table can be refreshed in place.
 */
class TransactionController extends WebController
{
    protected const ACTIONS = [
        'accounts_record',
        'credit_record',
        'credit_limit',
        'recharge_record',
        'refund_record',
        'withdraw_record',
        'consume_record',
    ];

    public function index(Request $request): View
    {
        $client = $this->requireClient();
        $action = (string) $request->input('action', 'accounts_record');

        if (! in_array($action, self::ACTIONS, true)) {
            $action = 'accounts_record';
        }

        return view('web.transaction.index', array_merge($this->shared(), [
            'Title' => '交易记录',
            'TplName' => 'transaction',
            'Transaction' => [
                'action' => $action,
                'rows' => $this->rows($request, $action, $client->id),
                'accounts_record' => $this->accountRows($request, $client->id),
                'credit_record' => $this->creditRows($request, $client->id),
                'credit_limit' => $this->creditLimitRows($request, $client->id),
                'recharge_record' => $this->rechargeRows($request, $client->id),
                'refund_record' => $this->refundRows($request, $client->id),
                'withdraw_record' => $this->withdrawRows($request, $client->id),
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
                'keywords' => (string) $request->input('keywords', ''),
            ],
            'Pager' => $this->pagerPayload($request, $action, $client->id),
        ]));
    }

    /**
     * GET /credit_limit/list — the credit-limit view of the same data.
     */
    public function creditLimitList(Request $request): JsonResponse
    {
        return $this->json($request, 'credit_limit');
    }

    /**
     * Bare per-record URLs (`/accounts_record`, `/refund_record`, ...);
     * the record name rides along as a route default.
     */
    public function jsonAction(Request $request): JsonResponse
    {
        return $this->json($request, (string) $request->route('recordAction', 'accounts_record'));
    }

    /**
     * One JSON endpoint per view, matching the original's route names.
     */
    public function json(Request $request, string $action): JsonResponse
    {
        $client = $this->requireClient();

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->fail('未知的记录类型');
        }

        [$page, $limit] = $this->pager($request, 20);

        return $this->ok([
            'list' => $this->rows($request, $action, $client->id),
            'pager' => $this->pagerPayload($request, $action, $client->id),
            'action' => $action,
        ]);
    }

    // -----------------------------------------------------------------
    // Row builders
    // -----------------------------------------------------------------

    protected function rows(Request $request, string $action, int $clientId): array
    {
        return match ($action) {
            'credit_record' => $this->creditRows($request, $clientId),
            'credit_limit' => $this->creditLimitRows($request, $clientId),
            'recharge_record' => $this->rechargeRows($request, $clientId),
            'refund_record' => $this->refundRows($request, $clientId),
            'withdraw_record' => $this->withdrawRows($request, $clientId),
            'consume_record' => $this->consumeRows($request, $clientId),
            default => $this->accountRows($request, $clientId),
        };
    }

    /**
     * 交易流水 — every ledger entry, income and expenditure.
     */
    protected function accountRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = Account::query()
            ->where('uid', $clientId)
            ->where(function ($query) {
                $query->whereNull('delete_time')->orWhere('delete_time', 0);
            })
            ->when($this->keywords($request) !== '', fn ($query) => $query->where(function ($sub) use ($request) {
                $keywords = '%' . $this->keywords($request) . '%';
                $sub->where('description', 'like', $keywords)
                    ->orWhere('trans_id', 'like', $keywords)
                    ->orWhere('invoice_id', 'like', $keywords);
            }))
            ->orderByRaw($this->orderBy($request, ['id', 'invoice_id', 'amount_in', 'amount_out', 'pay_time', 'trans_id'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(function (Account $row) {
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
                'trans_id' => (string) $row->trans_id,
            ];
        }, $rows->items());
    }

    /**
     * 充值记录 — ledger entries funded through a gateway or by hand.
     */
    protected function rechargeRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = Account::query()
            ->where('uid', $clientId)
            ->where('amount_in', '>', 0)
            ->where(function ($query) {
                $query->whereNull('delete_time')->orWhere('delete_time', 0);
            })
            ->orderByRaw($this->orderBy($request, ['id', 'invoice_id', 'amount_in', 'pay_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(fn (Account $row) => [
            'id' => (int) $row->id,
            'invoice_id' => (int) $row->invoice_id,
            'amount_in' => number_format((float) $row->amount_in, 2, '.', ''),
            'payment_zh' => $this->gatewayTitle((string) $row->gateway),
            'description' => (string) $row->description,
            'pay_time' => (int) $row->pay_time,
            'trans_id' => (string) $row->trans_id,
        ], $rows->items());
    }

    /**
     * 退款记录 — money that left the account as a refund.
     */
    protected function refundRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = Account::query()
            ->where('uid', $clientId)
            ->where('refund', 1)
            ->orderByRaw($this->orderBy($request, ['id', 'invoice_id', 'amount_out', 'pay_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(fn (Account $row) => [
            'id' => (int) $row->id,
            'invoice_id' => (int) $row->invoice_id,
            'amount_out' => number_format((float) $row->amount_out, 2, '.', ''),
            'description' => (string) $row->description,
            'pay_time' => (int) $row->pay_time,
        ], $rows->items());
    }

    /**
     * 消费记录 — non-recharge expenditure.
     */
    protected function consumeRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = Account::query()
            ->where('uid', $clientId)
            ->where('amount_in', 0)
            ->where('refund', 0)
            ->orderByRaw($this->orderBy($request, ['id', 'invoice_id', 'amount_out', 'pay_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(fn (Account $row) => [
            'id' => (int) $row->id,
            'invoice_id' => (int) $row->invoice_id,
            'amount_out' => number_format((float) $row->amount_out, 2, '.', ''),
            'description' => (string) $row->description,
            'pay_time' => (int) $row->pay_time,
        ], $rows->items());
    }

    /**
     * 余额 — the `shd_credit` balance ledger.
     */
    protected function creditRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = Credit::query()
            ->where('uid', $clientId)
            ->orderByRaw($this->orderBy($request, ['id', 'amount', 'create_time', 'balance'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(fn (Credit $row) => [
            'id' => (int) $row->id,
            'amount' => number_format((float) $row->amount, 2, '.', ''),
            'balance' => number_format((float) $row->balance, 2, '.', ''),
            'description' => (string) $row->description,
            'type' => (float) $row->amount >= 0 ? '收入' : '支出',
            'create_time' => (int) $row->create_time,
            'relid' => (int) $row->relid,
        ], $rows->items());
    }

    /**
     * 信用额 — credit-limit invoices.
     */
    protected function creditLimitRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = Invoice::query()
            ->where('uid', $clientId)
            ->where('is_delete', 0)
            ->where('use_credit_limit', 1)
            ->orderByRaw($this->orderBy($request, ['id', 'subtotal', 'paid_time', 'due_time', 'status'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(fn (Invoice $invoice) => [
            'id' => (int) $invoice->id,
            'subtotal' => number_format((float) $invoice->total, 2, '.', ''),
            'type' => (string) $invoice->type,
            'type_zh' => StatusMap::creditLimitInvoiceStatus((string) $invoice->status),
            'status' => (string) $invoice->status,
            'status_zh' => StatusMap::invoiceStatus((string) $invoice->status, true),
            'status_color' => StatusMap::invoiceStatusColor((string) $invoice->status),
            'create_time' => (int) $invoice->create_time,
            'paid_time' => (int) $invoice->paid_time,
            'due_time' => (int) $invoice->due_time,
        ], $rows->items());
    }

    /**
     * 提现记录 — affiliate withdrawals.
     */
    protected function withdrawRows(Request $request, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $rows = DB::table('affiliates_withdraw')
            ->where('uid', $clientId)
            ->orderByRaw($this->orderBy($request, ['id', 'num', 'create_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return array_map(fn ($row) => [
            'id' => (int) $row->id,
            'num' => number_format((float) $row->num, 2, '.', ''),
            'des' => '提现申请',
            'reason' => (string) ($row->reason ?: StatusMap::WITHDRAW_STATUS[(int) $row->status] ?? ''),
            'type' => StatusMap::WITHDRAW_TYPE[(int) $row->type] ?? '',
            'status' => StatusMap::WITHDRAW_STATUS[(int) $row->status] ?? '',
            'create_time' => (int) $row->create_time,
        ], $rows->items());
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Pagination figures for whichever view is active.
     */
    protected function pagerPayload(Request $request, string $action, int $clientId): array
    {
        [$page, $limit] = $this->pager($request, 20);

        $query = match ($action) {
            'credit_record' => Credit::query()->where('uid', $clientId),
            'credit_limit' => Invoice::query()->where('uid', $clientId)->where('is_delete', 0)->where('use_credit_limit', 1),
            'recharge_record' => Account::query()->where('uid', $clientId)->where('amount_in', '>', 0),
            'refund_record' => Account::query()->where('uid', $clientId)->where('refund', 1),
            'withdraw_record' => DB::table('affiliates_withdraw')->where('uid', $clientId),
            default => Account::query()->where('uid', $clientId),
        };

        $total = $query->count();

        return [
            'Total' => $total,
            'Limit' => $limit,
            'Page' => $page,
            'Pages' => (int) max(1, ceil($total / $limit)),
        ];
    }

    protected function keywords(Request $request): string
    {
        return trim((string) $request->input('keywords', ''));
    }

    protected function gatewayTitle(string $gateway): string
    {
        if ($gateway === '') {
            return '';
        }

        if ($gateway === 'credit') {
            return '余额支付';
        }

        if ($gateway === 'credit_limit') {
            return '信用额支付';
        }

        $row = \App\Models\PaymentGateway::query()->find($gateway);

        return $row?->displayName() ?? $gateway;
    }
}
