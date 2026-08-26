<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'credit' => 'decimal:2',
            'total' => 'decimal:2',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'renewal_due_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function supplierInvoiceLinks()
    {
        return $this->hasMany(SupplierInvoiceLink::class);
    }

    public function supplierOperations()
    {
        return $this->hasMany(SupplierOperation::class);
    }
}
