<?php

namespace App\Models;

use DomainException;

class SupplierServiceLink extends SupplierOwnedModel
{
    protected $fillable = [
        'upstream_service_id',
        'upstream_status',
        'metadata',
        'synced_at',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SupplierServiceLink $link): void {
            if (! is_string($link->upstream_service_id) || trim($link->upstream_service_id) === '') {
                throw new DomainException('A non-empty upstream service ID is required.');
            }
            if (strlen(trim($link->upstream_service_id)) > 128) {
                throw new DomainException('The upstream service ID cannot exceed 128 characters.');
            }
            if (preg_match('/[^\x20-\x7e]/', trim($link->upstream_service_id))) {
                throw new DomainException(
                    'The upstream service ID must contain printable ASCII characters only.',
                );
            }
            $link->upstream_service_id = trim($link->upstream_service_id);
        });
    }

    public function setUpstreamServiceIdAttribute(mixed $identifier): void
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new DomainException('The upstream service ID must be a string or integer.');
        }

        $this->attributes['upstream_service_id'] = (string) $identifier;
    }

    public static function createFor(
        SupplierAccount $account,
        Service $service,
        ?SupplierProductMapping $productMapping,
        array $attributes,
    ): static {
        $account = static::requirePersisted($account, 'supplier account');
        $service = static::requirePersisted($service, 'service');
        if ($productMapping !== null) {
            $productMapping = static::requirePersisted($productMapping, 'supplier product mapping');
            if ($service->product_id !== null
                && (string) $service->product_id !== (string) $productMapping->product_id) {
                throw new DomainException('The service does not reference the mapped product.');
            }
            if ($productMapping->local_billing_cycle !== $service->billing_cycle) {
                throw new DomainException('The service does not use the mapped billing cycle.');
            }
        }

        $link = new static($attributes);
        $link->account()->associate($account);
        $link->productMapping()->associate($productMapping);
        $link->service_id = $service->getKey();
        $link->save();

        return $link;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(SupplierAccount::class, 'supplier_account_id');
    }

    public function productMapping()
    {
        return $this->belongsTo(SupplierProductMapping::class, 'supplier_product_mapping_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function invoiceLinks()
    {
        return $this->hasMany(SupplierInvoiceLink::class);
    }

    public function operations()
    {
        return $this->hasMany(SupplierOperation::class);
    }

    protected function supplierOwnedRelations(): array
    {
        return [
            'productMapping' => 'supplier_product_mapping_id',
        ];
    }
}
