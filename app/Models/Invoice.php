<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice (`shd_invoices`).
 */
class Invoice extends ShdModel
{
    protected $table = 'invoices';

    protected $casts = [
        'uid' => 'integer',
        'subtotal' => 'decimal:2',
        'credit' => 'decimal:2',
        'tax' => 'decimal:2',
        'tax2' => 'decimal:2',
        'total' => 'decimal:2',
        'taxrate' => 'decimal:2',
        'taxrate2' => 'decimal:2',
        'create_time' => 'integer',
        'due_time' => 'integer',
        'paid_time' => 'integer',
        'is_delete' => 'integer',
        'use_credit_limit' => 'integer',
    ];

    public const STATUS_UNPAID = 'Unpaid';
    public const STATUS_PAID = 'Paid';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_REFUNDED = 'Refunded';
    public const STATUS_COLLECTIONS = 'Collections';

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function isPaid(): bool
    {
        return (string) $this->status === self::STATUS_PAID;
    }

    public function isUnpaid(): bool
    {
        return in_array((string) $this->status, [self::STATUS_UNPAID, self::STATUS_COLLECTIONS], true);
    }

    /**
     * Recalculate subtotal and total from the invoice items.
     */
    public function recalculate(): void
    {
        $subtotal = (float) $this->items()
            ->where(function ($query) {
                $query->whereNull('delete_time')->orWhere('delete_time', 0);
            })
            ->sum('amount');

        $tax = round($subtotal * ((float) $this->taxrate / 100), 2);
        $tax2 = round($subtotal * ((float) $this->taxrate2 / 100), 2);

        $this->subtotal = round($subtotal, 2);
        $this->tax = $tax;
        $this->tax2 = $tax2;
        $this->total = round($subtotal + $tax + $tax2 - (float) $this->credit, 2);
        $this->save();
    }

    public function invoiceNumber(): string
    {
        return trim((string) $this->invoice_num) !== '' ? (string) $this->invoice_num : (string) $this->id;
    }
}
