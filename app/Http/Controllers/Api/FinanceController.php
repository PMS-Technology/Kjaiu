<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinanceController extends ApiController
{
    public function invoices(Request $request): JsonResponse
    {
        $limit = max(1, min(100, $request->integer('limit', 20)));
        $query = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->input('status')))
            ->latest('id');
        $paginator = $query->paginate($limit);

        return $this->success([
            'total' => $paginator->total(),
            'invoices' => collect($paginator->items())->map(fn (Invoice $invoice) => $this->invoiceSummary($invoice)),
            'currency' => config('kjaiu.currency'),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->user_id !== $request->user()->id) {
            return $this->error('账单不存在', 404);
        }

        $invoice->load(['user', 'items', 'transactions']);
        $data = [
            'invoices' => [
                'id' => $invoice->id,
                'invoice_num' => $invoice->number,
                'logo' => '/favicon.ico',
                'username' => $invoice->user->name,
                'companyname' => config('kjaiu.company_name'),
                'create_time' => $invoice->created_at->timestamp,
                'due_time' => $invoice->due_at?->timestamp ?? 0,
                'paid_time' => $invoice->paid_at?->timestamp ?? 0,
                'status' => $invoice->status,
                'subtotal' => $invoice->subtotal,
                'credit' => $invoice->credit,
                'total' => $invoice->total,
                'invoice_items' => $invoice->items->map(fn ($item) => [
                    'type' => $item->type,
                    'description' => $item->description,
                    'amount' => $item->amount,
                    'rel_id' => $item->rel_id,
                ]),
            ],
            'currency' => config('kjaiu.currency'),
        ];

        if ($invoice->status === 'Unpaid') {
            $data['gateways'] = $this->gateways();
        }

        if ($invoice->status === 'Paid') {
            $data['accounts'] = $invoice->transactions->map(fn (Transaction $transaction) => [
                'trans_id' => $transaction->transaction_number,
                'amount_in' => $transaction->amount_in,
                'amount_out' => $transaction->amount_out,
                'gateway' => $transaction->gateway,
                'pay_time' => $transaction->paid_at?->timestamp ?? 0,
            ]);
        }

        return $this->success($data);
    }

    public function funds(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return $this->success([
            'currency' => config('kjaiu.currency'),
            'allow_recharge' => 1,
            'credit' => $request->user()->credit,
            'gateways' => $this->gateways(true),
            'count' => $transactions->count(),
            'invoices' => $transactions->map(fn (Transaction $transaction) => [
                'trans_id' => $transaction->transaction_number,
                'amount_in' => $transaction->amount_in,
                'pay_time' => $transaction->paid_at?->timestamp ?? 0,
                'gateway' => $transaction->gateway,
                'invoice_id' => $transaction->invoice_id,
                'description' => $transaction->type === 'recharge' ? '账户充值' : '账单支付',
                'type' => $transaction->type,
            ]),
            'addfunds_minimum' => config('kjaiu.funds.minimum'),
            'addfunds_maximum' => config('kjaiu.funds.maximum'),
            'addfunds_maximum_balance' => config('kjaiu.funds.maximum_balance'),
        ]);
    }

    public function createFunds(Request $request, BillingService $billing): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/', 'min:0.01', 'max:1000000'],
            'payment' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $gateway = PaymentGateway::query()
            ->where('name', $request->input('payment'))
            ->where('is_active', true)
            ->first();
        if (! $gateway || strtolower($gateway->name) === 'credit') {
            return $this->error('支付方式不可用');
        }

        try {
            $invoice = $billing->createRechargeInvoice(
                $request->user(),
                $request->input('amount'),
                $gateway->name,
                $request->input('idempotency_key', $request->header('Idempotency-Key')),
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception->validator->errors());
        }

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoiceid' => $invoice->id,
        ], '充值账单创建成功');
    }

    public function pay(Request $request, BillingService $billing): JsonResponse
    {
        $request->merge(['invoice_id' => $request->input('invoice_id', $request->input('invoiceid'))]);
        $validator = Validator::make($request->all(), [
            'invoice_id' => ['required', 'integer'],
            'payment' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $invoice = Invoice::query()->where('user_id', $request->user()->id)->find($request->integer('invoice_id'));
        if (! $invoice) {
            return $this->error('账单不存在', 404);
        }

        if (strtolower($request->string('payment')->toString()) === 'credit') {
            try {
                $invoice = $billing->payWithCredit($request->user(), $invoice);
            } catch (ValidationException $exception) {
                return $this->validationError($exception->validator->errors());
            }

            return $this->success([
                'invoice_id' => $invoice->id,
                'invoiceid' => $invoice->id,
                'status' => $invoice->status,
            ], '支付成功');
        }

        $gateway = PaymentGateway::query()
            ->where('name', $request->input('payment'))
            ->where('is_active', true)
            ->first();
        if (! $gateway) {
            return $this->error('支付方式不可用');
        }

        try {
            $invoice = $billing->prepareGatewayPayment($request->user(), $invoice, $gateway->name);
        } catch (ValidationException $exception) {
            return $this->validationError($exception->validator->errors());
        }

        if ($invoice->status === 'Paid') {
            return $this->success([
                'invoice_id' => $invoice->id,
                'invoiceid' => $invoice->id,
                'status' => $invoice->status,
            ], '账单已支付');
        }

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoiceid' => $invoice->id,
            'status' => $invoice->status,
            'payment' => $gateway->name,
            'requires_gateway' => true,
        ], '请前往支付网关完成付款');
    }

    public function payWithCredit(Request $request, BillingService $billing): JsonResponse
    {
        $request->merge(['invoice_id' => $request->input('invoice_id', $request->input('invoiceid'))]);
        $validator = Validator::make($request->all(), [
            'invoice_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $invoice = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->find($request->integer('invoice_id'));
        if (! $invoice) {
            return $this->error('账单不存在', 404);
        }

        try {
            $invoice = $billing->payWithCredit($request->user(), $invoice);
        } catch (ValidationException $exception) {
            return $this->validationError($exception->validator->errors());
        }

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoiceid' => $invoice->id,
            'status' => $invoice->status,
        ], '支付成功');
    }

    public function paymentStatus(Request $request): JsonResponse
    {
        $request->merge(['invoice_id' => $request->input('invoice_id', $request->input('invoiceid'))]);
        $invoice = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->find($request->integer('invoice_id'));

        if (! $invoice) {
            return $this->error('账单不存在', 404);
        }

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoiceid' => $invoice->id,
            'status' => $invoice->status,
        ]);
    }

    public function invoiceStatus(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->user_id !== $request->user()->id) {
            return $this->error('账单不存在', 404);
        }

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoiceid' => $invoice->id,
            'status' => $invoice->status,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $limit = max(1, min(100, $request->integer('limit', 20)));
        $paginator = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($limit);

        return $this->success([
            'total' => $paginator->total(),
            'accounts' => collect($paginator->items())->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'trans_id' => $transaction->transaction_number,
                'invoice_id' => $transaction->invoice_id,
                'amount_in' => $transaction->amount_in,
                'amount_out' => $transaction->amount_out,
                'fees' => $transaction->fee,
                'gateway' => $transaction->gateway,
                'pay_time' => $transaction->paid_at?->timestamp ?? 0,
                'type' => $transaction->type,
            ]),
            'currency' => config('kjaiu.currency'),
        ]);
    }

    private function gateways(bool $withStatus = false)
    {
        return PaymentGateway::query()
            ->where('is_active', true)
            ->where('name', '!=', 'Credit')
            ->orderBy('sort_order')
            ->get()
            ->map(function (PaymentGateway $gateway) use ($withStatus) {
                $value = [
                    'id' => $gateway->id,
                    'name' => $gateway->name,
                    'title' => $gateway->title,
                    'url' => $gateway->icon,
                    'author_url' => '',
                ];
                if ($withStatus) {
                    $value['status'] = 1;
                    $value['module'] = $gateway->name;
                }

                return $value;
            });
    }

    private function invoiceSummary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_num' => $invoice->number,
            'status' => $invoice->status,
            'subtotal' => $invoice->subtotal,
            'credit' => $invoice->credit,
            'total' => $invoice->total,
            'create_time' => $invoice->created_at->timestamp,
            'due_time' => $invoice->due_at?->timestamp ?? 0,
            'paid_time' => $invoice->paid_at?->timestamp ?? 0,
        ];
    }
}
