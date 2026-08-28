<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupplierAccount extends Model
{
    public const DRIVER_IDCSMART_FINANCE = 'idcsmart_finance';

    protected $fillable = [
        'code',
        'name',
        'driver',
        'base_url',
        'credentials',
        'options',
        'is_active',
        'last_tested_at',
        'last_connected_at',
        'last_catalog_synced_at',
        'last_error',
    ];

    protected $hidden = ['credentials'];

    protected static function booted(): void
    {
        static::saving(function (SupplierAccount $account): void {
            if (! array_key_exists('code', $account->getAttributes()) && ! $account->exists) {
                do {
                    $account->code = 'supplier-'.Str::lower(Str::random(24));
                } while (static::query()->where('code', $account->code)->exists());
            }

            if (! is_string($account->code) || trim($account->code) === '') {
                throw new DomainException('A non-empty supplier account code is required.');
            }
            if (strlen(trim($account->code)) > 64) {
                throw new DomainException('The supplier account code cannot exceed 64 characters.');
            }
            if ($account->exists) {
                $connectionIdentityChange = $account->isDirty([
                    'code',
                    'base_url',
                    'driver',
                    'options',
                ]) || $account->isDirty('is_active');
                $credentialsChange = $account->isDirty('credentials');
                $credentialRotationIsSafe = $credentialsChange
                    && ! $connectionIdentityChange
                    && $account->is_active
                    && (bool) $account->getOriginal('is_active');
                if (($connectionIdentityChange || ($credentialsChange && ! $credentialRotationIsSafe))
                    && ($account->hasNonterminalOperations() || $account->hasPendingOrderItemRoutes())) {
                    throw new DomainException(
                        'Supplier connection settings cannot change while operations are nonterminal.',
                    );
                }
            }

            $account->code = trim($account->code);
            $account->last_error = SupplierErrorSanitizer::sanitize(
                $account->last_error,
                [$account->credentials ?? []],
            );
        });
    }

    public function setLastErrorAttribute(mixed $error): void
    {
        $this->attributes['last_error'] = SupplierErrorSanitizer::sanitize($error);
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_catalog_synced_at' => 'datetime',
        ];
    }

    public function catalogProducts()
    {
        return $this->hasMany(SupplierCatalogProduct::class);
    }

    public function catalogImports()
    {
        return $this->hasMany(SupplierCatalogImport::class);
    }

    public function productMappings()
    {
        return $this->hasMany(SupplierProductMapping::class);
    }

    public function serviceLinks()
    {
        return $this->hasMany(SupplierServiceLink::class);
    }

    public function invoiceLinks()
    {
        return $this->hasMany(SupplierInvoiceLink::class);
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

    public function hasPendingOrderItemRoutes(): bool
    {
        return $this->orderItemRoutes()
            ->whereHas('orderItem.order', fn ($query) => $query->where('status', 'Pending'))
            ->exists();
    }

    public function allowsLegacyUnboundedCreditPayment(): bool
    {
        $options = is_array($this->options) ? $this->options : [];

        return ($options['allow_legacy_unbounded_credit_payment'] ?? null) === true;
    }

    public function verifiesTls(): bool
    {
        $options = is_array($this->options) ? $this->options : [];

        return ($options['verify_tls'] ?? true) !== false;
    }
}
