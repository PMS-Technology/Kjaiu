<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order (`shd_orders`).
 */
class Order extends ShdModel
{
    protected $table = 'orders';

    protected $casts = [
        'uid' => 'integer',
        'amount' => 'decimal:2',
        'promo_value' => 'decimal:2',
        'pay_time' => 'integer',
        'create_time' => 'integer',
        'invoiceid' => 'integer',
    ];

    public const STATUS_UNPAID = 'Unpaid';
    public const STATUS_PAID = 'Paid';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_REFUNDED = 'Refunded';
    public const STATUS_FRAUD = 'Fraud';

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoiceid');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'rel_id')->where('type', 'hosting');
    }
}
