<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'amount' => 'decimal:2',
            'reserved_quantity' => 'integer',
            'configuration' => 'array',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function supplierOperations()
    {
        return $this->hasMany(SupplierOperation::class);
    }

    public function supplierRoute()
    {
        return $this->hasOne(SupplierOrderItemRoute::class);
    }
}
