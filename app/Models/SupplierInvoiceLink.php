<?php

namespace App\Models;

use DomainException;

class SupplierInvoiceLink extends SupplierOwnedModel
{
    protected $fillable = [
        'upstream_order_id',
        'upstream_invoice_id',
        'upstream_status',
        'amount',
        'currency',
        'metadata',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SupplierInvoiceLink $link): void {
            if ($link->upstream_order_id === null && $link->upstream_invoice_id === null) {
                throw new DomainException('An upstream order or invoice ID is required.');
            }
        });
    }

    public function setUpstreamOrderIdAttribute(mixed $identifier): void
    {
        $this->attributes['upstream_order_id'] = $this->normalizeIdentifier($identifier);
    }

    public function setUpstreamInvoiceIdAttribute(mixed $identifier): void
    {
        $this->attributes['upstream_invoice_id'] = $this->normalizeIdentifier($identifier);
    }

    public static function createFor(
        SupplierAccount $account,
        Invoice $invoice,
        ?SupplierServiceLink $serviceLink,
        array $attributes,
    ): static {
        $account = static::requirePersisted($account, 'supplier account');
        $invoice = static::requirePersisted($invoice, 'invoice');
        if ($serviceLink !== null) {
            $serviceLink = static::requirePersisted($serviceLink, 'supplier service link');
        }

        $link = new static($attributes);
        $link->account()->associate($account);
        $link->serviceLink()->associate($serviceLink);
        $link->invoice_id = $invoice->getKey();
        $link->save();

        return $link;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function account()
    {
        return $this->belongsTo(SupplierAccount::class, 'supplier_account_id');
    }

    public function serviceLink()
    {
        return $this->belongsTo(SupplierServiceLink::class, 'supplier_service_link_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function operations()
    {
        return $this->hasMany(SupplierOperation::class);
    }

    protected function supplierOwnedRelations(): array
    {
        return [
            'serviceLink' => 'supplier_service_link_id',
        ];
    }

    private function normalizeIdentifier(mixed $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new DomainException('Upstream identifiers must be strings or integers.');
        }

        $identifier = trim((string) $identifier);

        if (strlen($identifier) > 128) {
            throw new DomainException('Upstream identifiers cannot exceed 128 characters.');
        }
        if ($identifier !== '' && preg_match('/[^\x20-\x7e]/', $identifier)) {
            throw new DomainException(
                'Upstream identifiers must contain printable ASCII characters only.',
            );
        }

        return $identifier === '' ? null : $identifier;
    }
}
