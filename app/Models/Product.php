<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public const MAX_STOCK = 4_294_967_295;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'stock_control' => 'boolean',
            'quantity' => 'integer',
            'auto_setup' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function supplierMappings()
    {
        return $this->hasMany(SupplierProductMapping::class);
    }

    public function supplierCatalogImport()
    {
        return $this->hasOne(SupplierCatalogImport::class);
    }

    public function supplierOrderItemRoutes()
    {
        return $this->hasMany(SupplierOrderItemRoute::class, 'local_product_id');
    }

    public function priceFor(string $billingCycle): ?ProductPrice
    {
        if ($billingCycle === $this->billing_cycle) {
            return new ProductPrice([
                'billing_cycle' => $this->billing_cycle,
                'price' => $this->price,
                'setup_fee' => $this->setup_fee,
                'is_active' => true,
            ]);
        }

        return $this->prices->first(
            fn (ProductPrice $price) => $price->is_active && $price->billing_cycle === $billingCycle
        );
    }
}
