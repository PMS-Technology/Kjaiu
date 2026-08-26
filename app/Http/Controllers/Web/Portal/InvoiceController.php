<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->input('status');
        $invoices = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->select([
                'id', 'user_id', 'order_id', 'number', 'status', 'subtotal', 'credit', 'total',
                'currency', 'due_at', 'paid_at', 'payment_method', 'created_at',
            ])
            ->when(in_array($status, ['Unpaid', 'Paid', 'Cancelled', 'Refunded'], true), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('portal.invoices.index', compact('invoices', 'status'));
    }

    public function show(Request $request, int $invoice): View
    {
        $userId = $request->user()->id;
        $invoice = $this->ownedInvoice($request, $invoice)
            ->load([
                'items:id,invoice_id,type,billing_cycle,description,amount,service_id',
                'transactions' => fn ($query) => $query
                    ->where('user_id', $userId)
                    ->select([
                        'id', 'user_id', 'invoice_id', 'transaction_number', 'type', 'gateway',
                        'amount_in', 'amount_out', 'paid_at',
                    ]),
                'order' => fn ($query) => $query
                    ->where('user_id', $userId)
                    ->select(['id', 'user_id']),
            ]);

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'canCancelRenewal' => $this->canCancelRenewal($invoice),
            'gateways' => $invoice->status === 'Unpaid'
                ? PaymentGateway::query()
                    ->where('is_active', true)
                    ->whereRaw('LOWER(name) != ?', ['credit'])
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'title', 'icon'])
                : collect(),
        ]);
    }

    public function pay(Request $request, int $invoice, BillingService $billing): RedirectResponse
    {
        $invoice = $this->ownedInvoice($request, $invoice);
        $data = $request->validate([
            'payment' => ['required', 'string', 'max:64'],
        ]);
        $payment = trim($data['payment']);
        if ($invoice->status === 'Paid') {
            return redirect()->route('portal.invoices.show', $invoice)->with('success', '账单已支付');
        }

        try {
            if (strcasecmp($payment, 'Credit') === 0) {
                $paid = $billing->payWithCredit($request->user(), $invoice);

                return redirect()->route('portal.invoices.show', $paid)
                    ->with('success', $paid->payment_method === 'Credit'
                        ? '账单已使用账户余额支付'
                        : '账单已支付');
            }

            $gateway = PaymentGateway::query()
                ->where('is_active', true)
                ->where('name', $payment)
                ->first(['id', 'name', 'title']);
            if (! $gateway || strcasecmp($gateway->name, 'Credit') === 0) {
                return back()->withErrors(['payment' => '支付方式不可用']);
            }

            $prepared = $billing->prepareGatewayPayment($request->user(), $invoice, $gateway->name);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        if ($prepared->status === 'Paid') {
            return redirect()->route('portal.invoices.show', $prepared)->with('success', '账单已支付');
        }

        return redirect()->route('portal.invoices.show', $prepared)->with(
            'pending',
            "账单 {$prepared->number} 尚未完成付款。请联系 ".config('kjaiu.company_email')." 并提供账单号获取{$gateway->title}付款指引；到账确认前账单将保持待支付。",
        );
    }

    public function cancelRenewal(Request $request, int $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $cancelled = DB::transaction(function () use ($request, $invoice, $billing) {
                $invoice = Invoice::query()
                    ->where('user_id', $request->user()->id)
                    ->with('items:id,invoice_id,type')
                    ->lockForUpdate()
                    ->findOrFail($invoice);
                if (! $this->canCancelRenewal($invoice)) {
                    throw ValidationException::withMessages([
                        'invoice' => '只有未支付的续费账单可以由客户取消',
                    ]);
                }

                return $billing->cancelInvoice($invoice);
            }, 3);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('portal.invoices.show', $cancelled)
            ->with('success', '续费账单已取消，现在可以重新选择续费周期');
    }

    private function ownedInvoice(Request $request, int $invoice): Invoice
    {
        return Invoice::query()
            ->where('user_id', $request->user()->id)
            ->select([
                'id', 'user_id', 'order_id', 'number', 'status', 'subtotal', 'credit', 'total',
                'currency', 'due_at', 'paid_at', 'payment_method', 'renewal_key', 'created_at', 'updated_at',
            ])
            ->findOrFail($invoice);
    }

    private function canCancelRenewal(Invoice $invoice): bool
    {
        return $invoice->status === 'Unpaid'
            && $invoice->order_id === null
            && filled($invoice->renewal_key)
            && $invoice->relationLoaded('items')
            && $invoice->items->isNotEmpty()
            && $invoice->items->every(fn ($item) => $item->type === 'renew');
    }
}
