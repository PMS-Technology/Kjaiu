<?php

namespace App\Models;

use DomainException;

class SupplierCatalogProduct extends SupplierOwnedModel
{
    protected $fillable = [
        'upstream_product_id',
        'upstream_group_id',
        'type',
        'name',
        'description',
        'currency',
        'minimum_price',
        'billing_cycles',
        'is_active',
        'metadata',
        'last_seen_at',
        'synced_at',
    ];

    public static function createForAccount(SupplierAccount $account, array $attributes): static
    {
        $account = static::requirePersisted($account, 'supplier account');

        $product = new static($attributes);
        $product->account()->associate($account);
        $product->save();

        return $product;
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SupplierCatalogProduct $product): void {
            if (! is_string($product->upstream_product_id) || trim($product->upstream_product_id) === '') {
                throw new DomainException('A non-empty upstream product ID is required.');
            }
            if (strlen(trim($product->upstream_product_id)) > 128) {
                throw new DomainException('The upstream product ID cannot exceed 128 characters.');
            }
            if (preg_match('/[^\x20-\x7e]/', trim($product->upstream_product_id))) {
                throw new DomainException('The upstream product ID must contain printable ASCII characters only.');
            }
            if ($product->upstream_group_id !== null) {
                if (! is_string($product->upstream_group_id)
                    || strlen(trim($product->upstream_group_id)) > 128
                    || preg_match('/[^\x20-\x7e]/', trim($product->upstream_group_id))) {
                    throw new DomainException(
                        'The upstream product group ID must contain at most 128 printable ASCII characters.',
                    );
                }
                $product->upstream_group_id = trim($product->upstream_group_id);
            }
            if ($product->exists
                && ($product->isDirty('upstream_product_id')
                    || ($product->isDirty('is_active') && ! $product->is_active))
                && $product->hasNonterminalOperations()) {
                throw new DomainException(
                    'Supplier catalog routing cannot change while operations are nonterminal.',
                );
            }
            if ($product->exists
                && $product->isDirty('upstream_product_id')
                && ($product->orderItemRoutes()->exists()
                    || $product->mappings()->where(function ($query): void {
                        $query->whereHas('serviceLinks')
                            ->orWhereHas('operations')
                            ->orWhereHas('orderItemRoutes');
                    })->exists())) {
                throw new DomainException(
                    'Upstream product IDs referenced by supplier history are immutable.',
                );
            }

            $product->upstream_product_id = trim($product->upstream_product_id);
        });
    }

    public function setUpstreamProductIdAttribute(mixed $identifier): void
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new DomainException('The upstream product ID must be a string or integer.');
        }

        $this->attributes['upstream_product_id'] = (string) $identifier;
    }

    public function setUpstreamGroupIdAttribute(mixed $identifier): void
    {
        if ($identifier === null) {
            $this->attributes['upstream_group_id'] = null;

            return;
        }
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new DomainException('The upstream product group ID must be a string or integer.');
        }

        $this->attributes['upstream_group_id'] = (string) $identifier;
    }

    protected function casts(): array
    {
        return [
            'minimum_price' => 'decimal:2',
            'billing_cycles' => 'array',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(SupplierAccount::class, 'supplier_account_id');
    }

    public function mappings()
    {
        return $this->hasMany(SupplierProductMapping::class);
    }

    public function catalogImport()
    {
        return $this->hasOne(SupplierCatalogImport::class);
    }

    public function orderItemRoutes()
    {
        return $this->hasMany(SupplierOrderItemRoute::class);
    }

    public function hasNonterminalOperations(): bool
    {
        return SupplierOperation::query()
            ->whereIn('supplier_product_mapping_id', $this->mappings()->select('id'))
            ->whereIn('status', SupplierOperation::NONTERMINAL_STATUSES)
            ->exists();
    }
}
