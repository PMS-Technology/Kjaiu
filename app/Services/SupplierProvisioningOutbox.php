<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierOperation;
use App\Models\SupplierOrderItemRoute;
use App\Models\SupplierProductMapping;
use App\Models\SupplierServiceLink;
use DomainException;
use Illuminate\Support\Facades\DB;

class SupplierProvisioningOutbox
{
    private const MAX_DATABASE_AMOUNT_MINOR = 999_999_999_999_999_999;

    public function activeMapping(
        ?int $productId,
        string $billingCycle,
        ?int $supplierAccountId = null,
    ): ?SupplierProductMapping {
        if ($productId === null) {
            return null;
        }

        $mapping = $this->activeMappings([[
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
        ]])[$this->mappingKey($productId, $billingCycle)] ?? null;

        return $mapping !== null
            && ($supplierAccountId === null
                || (string) $mapping->supplier_account_id === (string) $supplierAccountId)
                    ? $mapping
                    : null;
    }

    /**
     * @param  array<int, array{product_id: int, billing_cycle: string}>  $routes
     * @return array<string, SupplierProductMapping>
     */
    public function activeMappings(array $routes): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn (): array => $this->activeMappings($routes), 3);
        }

        $routes = collect($routes)
            ->map(fn (array $route): array => [
                'product_id' => (int) $route['product_id'],
                'billing_cycle' => (string) $route['billing_cycle'],
            ])
            ->unique(fn (array $route): string => $this->mappingKey(
                $route['product_id'],
                $route['billing_cycle'],
            ))
            ->sortBy(fn (array $route): string => sprintf(
                '%020d:%s',
                $route['product_id'],
                $route['billing_cycle'],
            ))
            ->values();
        if ($routes->isEmpty()) {
            return [];
        }

        $candidates = SupplierProductMapping::query()
            ->where(function ($query) use ($routes): void {
                foreach ($routes as $route) {
                    $query->orWhere(fn ($candidate) => $candidate
                        ->where('product_id', $route['product_id'])
                        ->where('local_billing_cycle', $route['billing_cycle']));
                }
            })
            ->orderBy('product_id')
            ->orderBy('local_billing_cycle')
            ->orderBy('id')
            ->get([
                'id',
                'supplier_account_id',
                'supplier_catalog_product_id',
                'product_id',
                'local_billing_cycle',
            ]);

        $accounts = SupplierAccount::query()
            ->whereIn('id', $candidates->pluck('supplier_account_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $catalogs = SupplierCatalogProduct::query()
            ->whereIn('id', $candidates->pluck('supplier_catalog_product_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $mappings = SupplierProductMapping::query()
            ->whereIn('id', $candidates->pluck('id')->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lockedIds = SupplierProductMapping::query()
            ->where(function ($query) use ($routes): void {
                foreach ($routes as $route) {
                    $query->orWhere(fn ($candidate) => $candidate
                        ->where('product_id', $route['product_id'])
                        ->where('local_billing_cycle', $route['billing_cycle']));
                }
            })
            ->orderBy('product_id')
            ->orderBy('local_billing_cycle')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $candidateIds = $candidates->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        if ($lockedIds !== $candidateIds) {
            throw new DomainException('Supplier mappings changed during checkout; retry the request.');
        }

        $active = [];
        foreach ($candidates as $candidate) {
            $account = $accounts->get($candidate->supplier_account_id);
            $catalog = $catalogs->get($candidate->supplier_catalog_product_id);
            $mapping = $mappings->get($candidate->id);
            if ($account === null
                || $catalog === null
                || $mapping === null
                || (string) $catalog->supplier_account_id !== (string) $account->id
                || (string) $mapping->supplier_account_id !== (string) $account->id
                || (string) $mapping->supplier_catalog_product_id !== (string) $catalog->id
                || (string) $mapping->product_id !== (string) $candidate->product_id
                || (string) $mapping->local_billing_cycle !== (string) $candidate->local_billing_cycle) {
                throw new DomainException('A supplier mapping is account-inconsistent or changed during checkout.');
            }
            if (! $account->is_active || ! $catalog->is_active || ! $mapping->is_active) {
                continue;
            }

            $mapping->setRelation('account', $account);
            $mapping->setRelation('catalogProduct', $catalog);
            $key = $this->mappingKey(
                (int) $mapping->product_id,
                (string) $mapping->local_billing_cycle,
            );
            if (array_key_exists($key, $active)) {
                throw new DomainException(
                    'Multiple active supplier mappings exist for a local product and billing cycle.',
                );
            }
            $active[$key] = $mapping;
        }

        return $active;
    }

    public function isSupplierManaged(Service $service, ?string $billingCycle = null): bool
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(
                fn (): bool => $this->isSupplierManaged($service, $billingCycle),
                3,
            );
        }

        $hasServiceLink = SupplierServiceLink::query()
            ->where('service_id', $service->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(1)
            ->get(['id'])
            ->isNotEmpty();
        if ($hasServiceLink) {
            return true;
        }

        if ($service->product_id === null) {
            return false;
        }

        $cycles = array_values(array_unique([
            (string) $service->billing_cycle,
            $billingCycle ?? (string) $service->billing_cycle,
        ]));

        return $this->activeMappings(array_map(
            fn (string $cycle): array => [
                'product_id' => (int) $service->product_id,
                'billing_cycle' => $cycle,
            ],
            $cycles,
        )) !== [];
    }

    public function ensureLocalRenewalAvailable(Service $service, string $billingCycle): void
    {
        if ($this->isSupplierManaged($service, $billingCycle)) {
            throw new DomainException('当前版本暂不支持上游供应商服务续费');
        }
    }

    public function queueProvision(
        Invoice $invoice,
        OrderItem $orderItem,
        Service $service,
        SupplierOrderItemRoute $route,
    ): SupplierOperation {
        $account = SupplierAccount::query()
            ->lockForUpdate()
            ->find($route->supplier_account_id);
        $mapping = SupplierProductMapping::query()
            ->lockForUpdate()
            ->find($route->supplier_product_mapping_id);
        $lockedRoute = SupplierOrderItemRoute::query()
            ->lockForUpdate()
            ->find($route->getKey());
        $lockedOrderItem = $lockedRoute?->orderItem()->lockForUpdate()->first();
        if ($lockedRoute !== null) {
            $lockedRoute->setRelation('account', $account);
            $lockedRoute->setRelation('productMapping', $mapping);
            $lockedRoute->setRelation('orderItem', $lockedOrderItem);
        }
        $route = $lockedRoute;
        if ($route === null) {
            throw new DomainException('The supplier order item route no longer exists.');
        }
        $routeSnapshot = $route->validatedSnapshot();
        if ($account === null
            || $mapping === null
            || $lockedOrderItem === null
            || (string) $route->order_item_id !== (string) $orderItem->id
            || (string) $lockedOrderItem->id !== (string) $orderItem->id
            || (string) $route->supplier_account_id !== (string) $account->id
            || (string) $route->supplier_product_mapping_id !== (string) $mapping->id
            || ! hash_equals(
                (string) $route->account_identity_hash,
                SupplierOrderItemRoute::accountIdentityHash($account),
            )) {
            throw new DomainException('The supplier order item route is incomplete or account-inconsistent.');
        }
        $payload = $this->canonicalize([
            'version' => 2,
            'action' => SupplierOperation::ACTION_PROVISION,
            'local' => [
                'order_id' => $invoice->order_id,
                'order_item_id' => $orderItem->id,
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'unit_index' => $service->unit_index,
            ],
            'route' => $routeSnapshot,
        ]);

        return $this->createOrVerify(
            account: $account,
            idempotencyKey: 'provision:service:'.$service->id,
            payload: $payload,
            references: [
                'orderItemRoute' => $route,
                'productMapping' => $mapping,
                'order' => $invoice->order,
                'orderItem' => $orderItem,
                'invoice' => $invoice,
                'service' => $service,
            ],
        );
    }

    private function createOrVerify(
        SupplierAccount $account,
        string $idempotencyKey,
        array $payload,
        array $references,
    ): SupplierOperation {
        $existing = SupplierOperation::query()
            ->where('supplier_account_id', $account->id)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            $existingPayload = $existing->request_payload;
            $correlation = is_array($existingPayload)
                && is_array($existingPayload['correlation'] ?? null)
                    ? $existingPayload['correlation']
                    : null;
            if ($correlation === null) {
                throw new DomainException('The existing supplier operation has no correlation snapshot.');
            }

            $payload['correlation'] = $correlation;
            $payload = $this->canonicalize($payload);
            if (! hash_equals($existing->request_hash, $this->requestHash($payload))
                || ! $this->referencesMatch($existing, $references)) {
                throw new DomainException('The existing supplier operation does not match the settlement snapshot.');
            }

            return $existing;
        }

        $payload['correlation'] = $this->correlation();
        $payload = $this->canonicalize($payload);
        $operation = SupplierOperation::createFor(
            account: $account,
            attributes: [
                'action' => $payload['action'],
                'status' => SupplierOperation::STATUS_QUEUED,
                'step' => 'queued',
                'idempotency_key' => $idempotencyKey,
                'request_payload' => $payload,
                'attempts' => 0,
                'metadata' => [
                    'correlation_id' => hash('sha256', $idempotencyKey),
                ],
                'available_at' => now(),
            ],
            productMapping: $references['productMapping'] ?? null,
            orderItemRoute: $references['orderItemRoute'] ?? null,
            order: $references['order'] ?? null,
            orderItem: $references['orderItem'] ?? null,
            invoice: $references['invoice'] ?? null,
            service: $references['service'] ?? null,
            serviceLink: $references['serviceLink'] ?? null,
        );

        return $operation;
    }

    public function freezeRoute(
        OrderItem $orderItem,
        SupplierProductMapping $mapping,
        string $localCurrency,
    ): SupplierOrderItemRoute {
        $account = $mapping->account;
        $catalog = $mapping->catalogProduct;
        if ($account === null
            || $catalog === null
            || (string) $catalog->supplier_account_id !== (string) $mapping->supplier_account_id) {
            throw new DomainException('The supplier mapping snapshot is incomplete or account-inconsistent.');
        }
        if ((string) $orderItem->product_id !== (string) $mapping->product_id
            || (string) $orderItem->billing_cycle !== (string) $mapping->local_billing_cycle) {
            throw new DomainException('The order item does not match its supplier mapping.');
        }
        $configuration = $orderItem->configuration;
        if ((is_array($configuration) && $configuration !== [])
            || (! is_array($configuration) && $configuration !== null)) {
            throw new DomainException('Mapped supplier products cannot use customer configuration.');
        }

        $options = is_array($mapping->options) ? $mapping->options : [];
        $configOptions = $options['configoption'] ?? $options['config_options'] ?? $options;
        if (! is_array($configOptions)) {
            throw new DomainException('Supplier mapping configuration options must be an array.');
        }
        [$expectedAmount, $expectedCurrency] = $this->expectedUpstreamPrice(
            $catalog,
            (string) $mapping->upstream_billing_cycle,
        );
        $localCurrency = $this->currency($localCurrency);
        $unitPrice = Money::format(Money::toMinor($orderItem->unit_price));
        $setupFee = Money::format(Money::toMinor($orderItem->setup_fee));
        $snapshot = $this->canonicalize([
            'version' => 1,
            'account' => [
                'supplier_account_id' => $account->id,
                'driver' => (string) $account->driver,
                'base_url' => rtrim(trim((string) $account->base_url), '/'),
                'identity_hash' => SupplierOrderItemRoute::accountIdentityHash($account),
            ],
            'mapping' => [
                'supplier_product_mapping_id' => $mapping->id,
                'supplier_catalog_product_id' => $catalog->id,
                'options' => $options,
            ],
            'local' => [
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'billing_cycle' => (string) $orderItem->billing_cycle,
                'quantity' => (int) $orderItem->quantity,
                'unit_price' => $unitPrice,
                'setup_fee' => $setupFee,
                'unit_total' => Money::format(Money::toMinor($unitPrice) + Money::toMinor($setupFee)),
                'currency' => $localCurrency,
            ],
            'upstream' => [
                'product_id' => (string) $catalog->upstream_product_id,
                'billing_cycle' => (string) $mapping->upstream_billing_cycle,
                'qty' => 1,
                'options' => $options,
                'configoption' => $configOptions,
                'expected_amount' => $expectedAmount,
                'currency' => $expectedCurrency,
            ],
        ]);

        return SupplierOrderItemRoute::createFor($account, $mapping, $catalog, $orderItem, $snapshot);
    }

    private function correlation(): array
    {
        return [
            'downstream_url' => $this->downstreamUrl(),
            'downstream_token' => bin2hex(random_bytes(16)),
            'downstream_id' => random_int(100_000_000_000_000, 999_999_999_999_999),
        ];
    }

    private function downstreamUrl(): string
    {
        $url = rtrim(trim((string) config('app.url')), '/');
        $parts = parse_url($url);
        if ($url === ''
            || preg_match('/[\x00-\x20\x7f]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new DomainException('A safe application URL is required for supplier operations.');
        }

        return $url;
    }

    private function requestHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function referencesMatch(SupplierOperation $operation, array $references): bool
    {
        foreach ([
            'orderItemRoute' => 'supplier_order_item_route_id',
            'productMapping' => 'supplier_product_mapping_id',
            'order' => 'order_id',
            'orderItem' => 'order_item_id',
            'invoice' => 'invoice_id',
            'service' => 'service_id',
            'serviceLink' => 'supplier_service_link_id',
        ] as $reference => $column) {
            $expected = $references[$reference] ?? null;
            if ((string) $operation->getAttribute($column) !== (string) $expected?->getKey()) {
                return false;
            }
        }

        return true;
    }

    private function expectedUpstreamPrice(
        SupplierCatalogProduct $catalog,
        string $billingCycle,
    ): array {
        $metadata = is_array($catalog->metadata) ? $catalog->metadata : [];
        $prices = is_array($metadata['prices'] ?? null) ? $metadata['prices'] : [];
        $pricing = is_array($prices[$billingCycle] ?? null) ? $prices[$billingCycle] : null;
        if ($pricing === null
            && in_array($billingCycle, [
                $metadata['primary_billing_cycle'] ?? null,
                $metadata['default_billing_cycle'] ?? null,
            ], true)) {
            $pricing = [
                'price' => $metadata['default_price'] ?? null,
                'setup_fee' => $metadata['default_setup_fee'] ?? null,
            ];
        }
        if (! is_array($pricing)
            || (! is_string($pricing['price'] ?? null)
                && ! is_int($pricing['price'] ?? null)
                && ! is_float($pricing['price'] ?? null))
            || (array_key_exists('setup_fee', $pricing)
                && $pricing['setup_fee'] !== null
                && ! is_string($pricing['setup_fee'])
                && ! is_int($pricing['setup_fee'])
                && ! is_float($pricing['setup_fee']))) {
            throw new DomainException('The synchronized supplier price is incomplete for the mapped cycle.');
        }

        try {
            $price = $this->synchronizedAmountMinor($pricing['price']);
            $setupFee = $this->synchronizedAmountMinor($pricing['setup_fee'] ?? '0.00');
        } catch (\InvalidArgumentException $exception) {
            throw new DomainException(
                'The synchronized supplier price is invalid for the mapped cycle.',
                0,
                $exception,
            );
        }
        if ($price < 0 || $setupFee < 0) {
            throw new DomainException('The synchronized supplier price cannot be negative.');
        }
        if ($price > self::MAX_DATABASE_AMOUNT_MINOR - $setupFee) {
            throw new DomainException('The synchronized supplier price exceeds the supported range.');
        }

        return [
            Money::format($price + $setupFee),
            $this->currency($catalog->currency),
        ];
    }

    private function synchronizedAmountMinor(string|int|float $amount): int
    {
        if (is_float($amount) && ! is_finite($amount)) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }
        $value = is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount);
        if (preg_match('/\A(-?)(\d+)(?:\.(\d{1,2}))?\z/', $value, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }
        if (strlen($matches[2]) > 16) {
            throw new DomainException('The synchronized supplier price exceeds the supported range.');
        }

        return Money::toMinor($value);
    }

    private function currency(mixed $currency): string
    {
        if (! is_string($currency)
            || preg_match('/\A[A-Za-z0-9]{3,8}\z/', trim($currency)) !== 1) {
            throw new DomainException('A synchronized supplier currency is required.');
        }

        return strtoupper(trim($currency));
    }

    private function mappingKey(int $productId, string $billingCycle): string
    {
        return $productId."\0".$billingCycle;
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
