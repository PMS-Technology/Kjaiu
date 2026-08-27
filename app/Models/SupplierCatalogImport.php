<?php

namespace App\Models;

class SupplierCatalogImport extends SupplierOwnedModel
{
    protected $fillable = ['imported_by'];

    public static function createFor(
        SupplierAccount $account,
        SupplierCatalogProduct $catalogProduct,
        Product $product,
        User $administrator,
    ): static {
        $import = new static(['imported_by' => $administrator->getKey()]);
        $import->account()->associate(static::requirePersisted($account, 'supplier account'));
        $import->catalogProduct()->associate(static::requirePersisted($catalogProduct, 'supplier catalog product'));
        $import->product()->associate(static::requirePersisted($product, 'product'));
        $import->save();

        return $import;
    }

    public function account()
    {
        return $this->belongsTo(SupplierAccount::class, 'supplier_account_id');
    }

    public function catalogProduct()
    {
        return $this->belongsTo(SupplierCatalogProduct::class, 'supplier_catalog_product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function administrator()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    protected function supplierOwnedRelations(): array
    {
        return ['catalogProduct' => 'supplier_catalog_product_id'];
    }
}
