<?php

namespace App\Models;

use DomainException;

class SupplierProductMapping extends SupplierOwnedModel
{
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'local_billing_cycle',
        'upstream_billing_cycle',
        'options',
        'is_active',
    ];

    public static function createFor(
        SupplierAccount $account,
        SupplierCatalogProduct $catalogProduct,
        Product $product,
        array $attributes = [],
    ): static {
        $account = static::requirePersisted($account, 'supplier account');
        $catalogProduct = static::requirePersisted($catalogProduct, 'supplier catalog product');
        $product = static::requirePersisted($product, 'product');

        $mapping = new static($attributes);
        $mapping->account()->associate($account);
        $mapping->catalogProduct()->associate($catalogProduct);
        $mapping->product_id = $product->getKey();
        $mapping->save();

        return $mapping;
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SupplierProductMapping $mapping): void {
            foreach (['local_billing_cycle', 'upstream_billing_cycle'] as $attribute) {
                $cycle = $mapping->getAttribute($attribute);
                if (! is_string($cycle) || trim($cycle) === '') {
                    throw new DomainException('Both local and upstream billing cycles are required.');
                }
                if (strlen(trim($cycle)) > 32) {
                    throw new DomainException('Supplier billing cycles cannot exceed 32 characters.');
                }
                if (preg_match('/\A[a-z0-9_.-]+\z/', trim($cycle)) !== 1) {
                    throw new DomainException(
                        'Supplier billing cycles may contain lowercase letters, numbers, dots, dashes, and underscores only.',
                    );
                }

                $mapping->setAttribute($attribute, trim($cycle));
            }

            if ($mapping->is_active
                && (! $mapping->exists
                    || $mapping->isDirty(['product_id', 'local_billing_cycle', 'is_active']))) {
                $duplicate = static::query()
                    ->where('product_id', $mapping->product_id)
                    ->where('local_billing_cycle', $mapping->local_billing_cycle)
                    ->where('is_active', true)
                    ->when(
                        $mapping->exists,
                        fn ($query) => $query->where('id', '!=', $mapping->getKey()),
                    )
                    ->exists();
                if ($duplicate) {
                    throw new DomainException(
                        'The local product and billing cycle already have an active supplier mapping.',
                    );
                }
            }

            $identityChange = $mapping->exists && $mapping->isDirty([
                'supplier_account_id',
                'supplier_catalog_product_id',
                'product_id',
                'local_billing_cycle',
                'upstream_billing_cycle',
                'options',
            ]);
            $protectedChange = $mapping->exists
                && ($identityChange || $mapping->isDirty('is_active'));
            if ($protectedChange && $mapping->hasNonterminalOperations()) {
                throw new DomainException(
                    'Supplier mappings cannot change while referenced operations are nonterminal.',
                );
            }
            if ($identityChange && $mapping->hasReferences()) {
                throw new DomainException(
                    'Supplier mappings with historical references cannot change routing identity.',
                );
            }
        });

        static::deleting(function (SupplierProductMapping $mapping): void {
            if ($mapping->hasNonterminalOperations()) {
                throw new DomainException(
                    'Supplier mappings cannot be deleted while referenced operations are nonterminal.',
                );
            }
            if ($mapping->hasReferences()) {
                throw new DomainException(
                    'Supplier mappings with historical references cannot be deleted.',
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
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

    public function serviceLinks()
    {
        return $this->hasMany(SupplierServiceLink::class);
    }

    public function operations()
    {
        return $this->hasMany(SupplierOperation::class);
    }

    public function orderItemRoutes()
    {
        return $this->hasMany(SupplierOrderItemRoute::class);
    }

    public function hasNonterminalOperations(): bool
    {
        return $this->operations()
            ->whereIn('status', SupplierOperation::NONTERMINAL_STATUSES)
            ->exists();
    }

    public function hasReferences(): bool
    {
        return $this->serviceLinks()->exists()
            || $this->operations()->exists()
            || $this->orderItemRoutes()->exists();
    }

    protected function supplierOwnedRelations(): array
    {
        return [
            'catalogProduct' => 'supplier_catalog_product_id',
        ];
    }
}
