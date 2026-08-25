<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_payment_amount' => 'decimal:2',
            'renew_amount' => 'decimal:2',
            'registered_at' => 'datetime',
            'activated_at' => 'datetime',
            'next_due_at' => 'datetime',
            'assigned_ips' => 'array',
            'auto_renew' => 'boolean',
            'billing_anchor_day' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
