<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Invoice lifecycle: creation from order items, balance settlement, credit
 * application and payment status queries.
 *
 * The original platform operates in unix timestamps and keeps invoice totals
 * denormalised on the invoice row; both behaviours are preserved.
 */
class InvoiceService
{
    public function __construct(
        protected PricingService $pricing = new PricingService(),
    ) {
    }

    /**
     * Create an invoice for a client with the given line items.
     *
     * @param  array<int, array{type:string, rel_id?:int, description:string, amount:float, taxed?:int}>  $items
     */
    public function create(
        Client $client,
        array $items,
        ?int $dueTime = null,
        string $type = 'hosting',
        array $attributes = [],
    ): Invoice {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['amount'];
        }

        $subtotal = PricingService::money($subtotal);

        $taxRate = $client->taxexempt ? 0.0 : (float) ($attributes['taxrate'] ?? 0);
        $taxRate2 = $client->taxexempt ? 0.0 : (float) ($attributes['taxrate2'] ?? 0);
        $tax = round($subtotal * ($taxRate / 100), 2);
        $tax2 = round($subtotal * ($taxRate2 / 100), 2);

        $invoice = Invoice::create(array_merge([
            'uid' => $client->id,
            'invoice_num' => $this->nextNumber(),
            'create_time' => time(),
            'update_time' => time(),
            'due_time' => $dueTime ?? (time() + 86400 * 7),
            'paid_time' => 0,
            'subtotal' => $subtotal,
            'credit' => 0,
            'tax' => $tax,
            'tax2' => $tax2,
            'total' => PricingService::money($subtotal + $tax + $tax2),
            'taxrate' => $taxRate,
            'taxrate2' => $taxRate2,
            'status' => Invoice::STATUS_UNPAID,
            'type' => $type,
        ], $attributes));

        foreach ($items as $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'uid' => $client->id,
                'type' => $item['type'],
                'rel_id' => $item['rel_id'] ?? 0,
                'description' => $item['description'],
                'amount' => PricingService::money((float) $item['amount']),
                'taxed' => $item['taxed'] ?? 0,
                'due_time' => $invoice->due_time,
                'notes' => $item['notes'] ?? '',
            ]);
        }

        return $invoice->refresh();
    }

    /**
     * Settle an invoice from the client's balance.
     *
     * Returns false when the balance is insufficient; nothing is written in
     * that case so the caller can fall back to a payment gateway.
     */
    public function payWithCredit(Invoice $invoice, ?Client $client = null): bool
    {
        $client = $client ?? $invoice->client;

        if ($client === null) {
            return false;
        }

        $outstanding = $this->outstanding($invoice);

        if ($outstanding <= 0) {
            return true;
        }

        if ((float) $client->credit < $outstanding) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $client, $outstanding) {
            // Route the balance movement through the ledger so the client-area
            // transaction history records it, rather than adjusting the column
            // directly and leaving no trace.
            $client->addCredit(-$outstanding, '账单支付 #' . $invoice->invoiceNumber(), (int) $invoice->id);

            $invoice->credit = PricingService::money((float) $invoice->credit + $outstanding);
            $invoice->status = Invoice::STATUS_PAID;
            $invoice->paid_time = time();
            $invoice->payment = $invoice->payment ?: 'credit';
            $invoice->payment_status = Invoice::STATUS_PAID;
            $invoice->save();

            return true;
        });
    }

    /**
     * Mark an invoice paid as the result of a payment gateway callback.
     */
    public function markPaid(Invoice $invoice, string $gateway, ?float $amount = null): Invoice
    {
        $invoice->status = Invoice::STATUS_PAID;
        $invoice->paid_time = time();
        $invoice->payment = $gateway;
        $invoice->payment_status = Invoice::STATUS_PAID;
        $invoice->save();

        $invoice->order?->update([
            'status' => Order::STATUS_PAID,
            'pay_time' => time(),
            'payment' => $gateway,
        ]);

        return $invoice;
    }

    /**
     * Amount still owed on an invoice.
     */
    public function outstanding(Invoice $invoice): float
    {
        if ($invoice->isPaid()) {
            return 0.0;
        }

        return PricingService::money(max(0, (float) $invoice->total - (float) $invoice->credit));
    }

    /**
     * Reverse the balance movement recorded for an invoice, used by refunds.
     */
    public function refund(Invoice $invoice, float $amount, string $reason = ''): bool
    {
        $amount = PricingService::money(min($amount, (float) $invoice->total));

        if ($amount <= 0) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $amount, $reason) {
            $client = $invoice->client;

            if ($client !== null) {
                $client->addCredit($amount, $reason !== '' ? $reason : '账单退款 #' . $invoice->invoiceNumber());
            }

            $invoice->status = Invoice::STATUS_REFUNDED;
            $invoice->save();

            return true;
        });
    }

    /**
     * Sequential invoice number, mirroring the original "YYYYMMDDnnnn" shape.
     */
    public function nextNumber(): string
    {
        $prefix = date('Ymd');
        $sequence = Invoice::query()->where('invoice_num', 'like', $prefix . '%')->count() + 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Invoice list for a client, paginated the way the client area expects.
     */
    public function paginateForClient(Client $client, int $limit = 20, ?string $status = null, string $keywords = '')
    {
        return Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($keywords !== '', function ($q) use ($keywords) {
                $q->where(function ($sub) use ($keywords) {
                    $sub->where('invoice_num', 'like', '%' . $keywords . '%')
                        ->orWhere('id', $keywords);
                });
            })
            ->orderByDesc('id')
            ->paginate($limit);
    }
}
