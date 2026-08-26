<?php

namespace App\Models;

use App\Services\Money;
use DomainException;
use InvalidArgumentException;
use JsonException;

class SupplierOrderItemRoute extends SupplierOwnedModel
{
    private const MAX_DATABASE_AMOUNT_MINOR = 999_999_999_999_999_999;

    protected $guarded = ['*'];

    protected $hidden = ['snapshot'];

    public static function createFor(
        SupplierAccount $account,
        SupplierProductMapping $mapping,
        SupplierCatalogProduct $catalogProduct,
        OrderItem $orderItem,
        array $snapshot,
    ): static {
        $account = static::requirePersisted($account, 'supplier account');
        $mapping = static::requirePersisted($mapping, 'supplier product mapping');
        $catalogProduct = static::requirePersisted($catalogProduct, 'supplier catalog product');
        $orderItem = static::requirePersisted($orderItem, 'order item');

        $route = new static;
        $route->account()->associate($account);
        $route->productMapping()->associate($mapping);
        $route->catalogProduct()->associate($catalogProduct);
        $route->orderItem()->associate($orderItem);
        $route->local_product_id = $snapshot['local']['product_id'] ?? null;
        $route->local_billing_cycle = $snapshot['local']['billing_cycle'] ?? null;
        $route->upstream_product_id = $snapshot['upstream']['product_id'] ?? null;
        $route->upstream_billing_cycle = $snapshot['upstream']['billing_cycle'] ?? null;
        $route->local_unit_amount = $snapshot['local']['unit_price'] ?? null;
        $route->local_setup_amount = $snapshot['local']['setup_fee'] ?? null;
        $route->local_currency = $snapshot['local']['currency'] ?? null;
        $route->expected_upstream_amount = $snapshot['upstream']['expected_amount'] ?? null;
        $route->expected_upstream_currency = $snapshot['upstream']['currency'] ?? null;
        $route->account_identity_hash = $snapshot['account']['identity_hash'] ?? null;
        $route->snapshot = $snapshot;
        $route->request_hash = static::snapshotHash($snapshot);
        $route->save();

        return $route;
    }

    public static function accountIdentityHash(SupplierAccount $account): string
    {
        return hash('sha256', json_encode([
            'supplier_account_id' => $account->getKey(),
            'driver' => (string) $account->driver,
            'base_url' => rtrim(trim((string) $account->base_url), '/'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function validatedSnapshot(): array
    {
        $snapshot = $this->snapshot;
        if (! is_array($snapshot)
            || ! is_string($this->request_hash)
            || ! hash_equals($this->request_hash, static::snapshotHash($snapshot))) {
            throw new DomainException('The supplier order item route snapshot is invalid.');
        }

        return $snapshot;
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SupplierOrderItemRoute $route): void {
            if ($route->exists && $route->isDirty()) {
                throw new DomainException('Supplier order item routes are immutable.');
            }

            $snapshot = $route->snapshot;
            if (! is_array($snapshot)) {
                throw new DomainException('A supplier order item route snapshot is required.');
            }
            if (! is_string($route->request_hash)
                || ! hash_equals($route->request_hash, static::snapshotHash($snapshot))) {
                throw new DomainException('The supplier order item route hash does not match its snapshot.');
            }
            if (! is_string($route->upstream_product_id)
                || trim($route->upstream_product_id) === ''
                || strlen(trim($route->upstream_product_id)) > 128
                || preg_match('/[^\x20-\x7e]/', trim($route->upstream_product_id))) {
                throw new DomainException(
                    'The frozen upstream product ID must contain at most 128 printable ASCII characters.',
                );
            }
            foreach (['local_billing_cycle', 'upstream_billing_cycle'] as $attribute) {
                if (! is_string($route->getAttribute($attribute))
                    || preg_match('/\A[a-z0-9_.-]{1,32}\z/', $route->getAttribute($attribute)) !== 1) {
                    throw new DomainException('Frozen supplier billing cycles are invalid.');
                }
            }

            $mirrors = [
                'supplier_account_id' => $snapshot['account']['supplier_account_id'] ?? null,
                'supplier_product_mapping_id' => $snapshot['mapping']['supplier_product_mapping_id'] ?? null,
                'supplier_catalog_product_id' => $snapshot['mapping']['supplier_catalog_product_id'] ?? null,
                'order_item_id' => $snapshot['local']['order_item_id'] ?? null,
                'local_product_id' => $snapshot['local']['product_id'] ?? null,
                'local_billing_cycle' => $snapshot['local']['billing_cycle'] ?? null,
                'upstream_product_id' => $snapshot['upstream']['product_id'] ?? null,
                'upstream_billing_cycle' => $snapshot['upstream']['billing_cycle'] ?? null,
                'local_unit_amount' => $snapshot['local']['unit_price'] ?? null,
                'local_setup_amount' => $snapshot['local']['setup_fee'] ?? null,
                'local_currency' => $snapshot['local']['currency'] ?? null,
                'expected_upstream_amount' => $snapshot['upstream']['expected_amount'] ?? null,
                'expected_upstream_currency' => $snapshot['upstream']['currency'] ?? null,
                'account_identity_hash' => $snapshot['account']['identity_hash'] ?? null,
            ];
            foreach ($mirrors as $attribute => $expected) {
                if ($expected === null
                    || (string) $route->getAttribute($attribute) !== (string) $expected) {
                    throw new DomainException('The supplier order item route snapshot is inconsistent.');
                }
            }
            $account = $route->account;
            $mapping = $route->productMapping;
            $catalog = $route->catalogProduct;
            $orderItem = $route->orderItem;
            $snapshotAccount = is_array($snapshot['account'] ?? null) ? $snapshot['account'] : [];
            $snapshotMapping = is_array($snapshot['mapping'] ?? null) ? $snapshot['mapping'] : [];
            $snapshotLocal = is_array($snapshot['local'] ?? null) ? $snapshot['local'] : [];
            $snapshotUpstream = is_array($snapshot['upstream'] ?? null) ? $snapshot['upstream'] : [];
            $mappingOptions = static::canonicalize(is_array($mapping?->options) ? $mapping->options : []);
            $configOptions = $mappingOptions['configoption']
                ?? $mappingOptions['config_options']
                ?? $mappingOptions;
            $configuration = $orderItem?->configuration;
            try {
                $unitPriceMinor = static::amountMinor($snapshotLocal['unit_price'] ?? null);
                $setupFeeMinor = static::amountMinor($snapshotLocal['setup_fee'] ?? null);
                $unitTotalMinor = static::amountMinor($snapshotLocal['unit_total'] ?? null);
                $orderItemAmount = static::amountMinor($orderItem?->amount);
                $expectedAmountMinor = static::amountMinor(
                    $snapshotUpstream['expected_amount'] ?? null,
                );
            } catch (InvalidArgumentException $exception) {
                throw new DomainException(
                    'The supplier order item route monetary snapshot is invalid.',
                    0,
                    $exception,
                );
            }
            $unitPrice = Money::format($unitPriceMinor);
            $setupFee = Money::format($setupFeeMinor);
            $expectedAmount = Money::format($expectedAmountMinor);
            $quantity = $snapshotLocal['quantity'] ?? null;
            if (($snapshot['version'] ?? null) !== 1
                || ! static::hasExactKeys($snapshot, [
                    'version',
                    'account',
                    'mapping',
                    'local',
                    'upstream',
                ])
                || ! static::hasExactKeys($snapshotAccount, [
                    'supplier_account_id',
                    'driver',
                    'base_url',
                    'identity_hash',
                ])
                || ! static::hasExactKeys($snapshotMapping, [
                    'supplier_product_mapping_id',
                    'supplier_catalog_product_id',
                    'options',
                ])
                || ! static::hasExactKeys($snapshotLocal, [
                    'order_id',
                    'order_item_id',
                    'product_id',
                    'billing_cycle',
                    'quantity',
                    'unit_price',
                    'setup_fee',
                    'unit_total',
                    'currency',
                ])
                || ! static::hasExactKeys($snapshotUpstream, [
                    'product_id',
                    'billing_cycle',
                    'qty',
                    'options',
                    'configoption',
                    'expected_amount',
                    'currency',
                ])
                || $account === null
                || $mapping === null
                || $catalog === null
                || $orderItem === null
                || ! static::isPositiveId($snapshotAccount['supplier_account_id'] ?? null)
                || ! static::isPositiveId($snapshotMapping['supplier_product_mapping_id'] ?? null)
                || ! static::isPositiveId($snapshotMapping['supplier_catalog_product_id'] ?? null)
                || ! static::isPositiveId($snapshotLocal['order_id'] ?? null)
                || ! static::isPositiveId($snapshotLocal['order_item_id'] ?? null)
                || ! static::isPositiveId($snapshotLocal['product_id'] ?? null)
                || (string) $route->supplier_account_id !== (string) $mapping->supplier_account_id
                || (string) $route->supplier_account_id !== (string) $route->catalogProduct?->supplier_account_id
                || (string) $mapping->supplier_catalog_product_id !== (string) $route->supplier_catalog_product_id
                || (string) $mapping->product_id !== (string) $route->local_product_id
                || (string) $orderItem->product_id !== (string) $route->local_product_id
                || (string) $orderItem->billing_cycle !== (string) $route->local_billing_cycle
                || (string) $orderItem->order_id !== (string) ($snapshotLocal['order_id'] ?? null)
                || ! is_int($quantity)
                || (int) $quantity !== (int) $orderItem->quantity
                || (int) $quantity < 1
                || (int) $quantity > 100
                || $unitPrice !== (string) $orderItem->unit_price
                || $setupFee !== (string) $orderItem->setup_fee
                || $unitPriceMinor > self::MAX_DATABASE_AMOUNT_MINOR - $setupFeeMinor
                || $unitTotalMinor !== $unitPriceMinor + $setupFeeMinor
                || $unitTotalMinor > intdiv(self::MAX_DATABASE_AMOUNT_MINOR, (int) $quantity)
                || $orderItemAmount !== $unitTotalMinor * (int) $quantity
                || $expectedAmount !== (string) $route->expected_upstream_amount
                || preg_match('/\A[A-Z0-9]{3,8}\z/', (string) ($snapshotLocal['currency'] ?? '')) !== 1
                || preg_match('/\A[A-Z0-9]{3,8}\z/', (string) ($snapshotUpstream['currency'] ?? '')) !== 1
                || ((is_array($configuration) && $configuration !== [])
                    || (! is_array($configuration) && $configuration !== null))
                || ! hash_equals(
                    (string) $route->account_identity_hash,
                    static::accountIdentityHash($account),
                )
                || (string) ($snapshotAccount['driver'] ?? '') !== (string) $account->driver
                || rtrim((string) ($snapshotAccount['base_url'] ?? ''), '/')
                    !== rtrim(trim((string) $account->base_url), '/')
                || ($snapshotMapping['options'] ?? null) !== $mappingOptions
                || ($snapshotUpstream['options'] ?? null) !== $mappingOptions
                || ! is_array($configOptions)
                || ($snapshotUpstream['configoption'] ?? null) !== $configOptions
                || (string) ($snapshotUpstream['product_id'] ?? '') !== (string) $catalog->upstream_product_id
                || (string) ($snapshotUpstream['billing_cycle'] ?? '')
                    !== (string) $mapping->upstream_billing_cycle
                || ($snapshotUpstream['qty'] ?? null) !== 1) {
                throw new DomainException('The supplier order item route references are inconsistent.');
            }
        });

        static::deleting(function (): void {
            throw new DomainException('Supplier order item routes are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'local_unit_amount' => 'decimal:2',
            'local_setup_amount' => 'decimal:2',
            'expected_upstream_amount' => 'decimal:2',
            'snapshot' => 'encrypted:array',
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

    public function catalogProduct()
    {
        return $this->belongsTo(SupplierCatalogProduct::class, 'supplier_catalog_product_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'local_product_id');
    }

    public function operations()
    {
        return $this->hasMany(SupplierOperation::class);
    }

    protected function supplierOwnedRelations(): array
    {
        return [
            'productMapping' => 'supplier_product_mapping_id',
            'catalogProduct' => 'supplier_catalog_product_id',
        ];
    }

    private static function snapshotHash(array $snapshot): string
    {
        try {
            return hash('sha256', json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new DomainException('The supplier order item route snapshot is not JSON encodable.', 0, $exception);
        }
    }

    private static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private static function amountMinor(mixed $amount): int
    {
        if (! is_string($amount)
            || preg_match('/\A\d{1,16}\.\d{2}\z/', $amount) !== 1) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        return Money::toMinor($amount);
    }

    private static function isPositiveId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = static::canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
