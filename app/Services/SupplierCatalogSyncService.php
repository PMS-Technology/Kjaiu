<?php

namespace App\Services;

use App\Integrations\Idcsmart\FinanceClient;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierErrorSanitizer;
use App\Models\SupplierOperation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplierCatalogSyncService
{
    private const MAX_PAGES = 25;

    private const MAX_PRODUCTS = 500;

    private const KNOWN_CYCLES = [
        'free',
        'hourly',
        'daily',
        'monthly',
        'quarterly',
        'semiannually',
        'annually',
        'biennially',
        'triennially',
        'onetime',
    ];

    public function sync(SupplierAccount $account): array
    {
        $fingerprint = $this->accountFingerprint($account);
        $sensitiveValues = $this->sensitiveValues($account->credentials ?? []);
        $client = new FinanceClient($account);
        $productIds = $this->fetchProductIds($client);
        $products = [];

        foreach ($productIds as $productId) {
            if ($this->containsSensitiveValue($productId, $sensitiveValues)) {
                throw new RuntimeException('The supplier returned an unsafe product identifier.');
            }

            $response = $client->product($productId);
            $detail = $response->data['product'];
            $detailId = $this->identifier($detail['id'] ?? null);
            if (! hash_equals($productId, $detailId)) {
                throw new RuntimeException('The supplier returned an inconsistent product identifier.');
            }

            $products[] = $this->normalizeProduct(
                $detail,
                $detailId,
                $response->data,
                $sensitiveValues,
            );
        }

        $syncedAt = now();

        return DB::transaction(function () use (
            $account,
            $fingerprint,
            $productIds,
            $products,
            $syncedAt,
        ): array {
            $lockedAccount = SupplierAccount::query()->lockForUpdate()->findOrFail($account->getKey());
            if (! hash_equals($fingerprint, $this->accountFingerprint($lockedAccount))) {
                throw new RuntimeException('The supplier account changed during catalog synchronization.');
            }

            $existingProducts = $lockedAccount->catalogProducts()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('upstream_product_id');
            $incomingProducts = collect($products)->keyBy('upstream_product_id');
            $mappings = $lockedAccount->productMappings()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $catalogById = $existingProducts->keyBy('id');
            $mappingIdsToDeactivate = [];
            $guardedMappingIds = [];
            $deactivatedProductCount = $existingProducts->filter(function (
                SupplierCatalogProduct $product,
            ) use ($incomingProducts): bool {
                $incoming = $incomingProducts->get($product->upstream_product_id);

                return $product->is_active && ($incoming === null || ! $incoming['is_active']);
            })->count();

            foreach ($mappings as $mapping) {
                $catalogProduct = $catalogById->get($mapping->supplier_catalog_product_id);
                $incoming = $catalogProduct === null
                    ? null
                    : $incomingProducts->get($catalogProduct->upstream_product_id);
                $catalogWillDeactivate = $catalogProduct !== null
                    && $catalogProduct->is_active
                    && ($incoming === null || ! $incoming['is_active']);
                $mappingWillDeactivate = $mapping->is_active
                    && ($incoming === null
                        || ! $incoming['is_active']
                        || ! in_array(
                            $mapping->upstream_billing_cycle,
                            $incoming['billing_cycles'],
                            true,
                        ));

                if ($catalogWillDeactivate || $mappingWillDeactivate) {
                    $guardedMappingIds[] = $mapping->id;
                }
                if ($mappingWillDeactivate) {
                    $mappingIdsToDeactivate[] = $mapping->id;
                }
            }

            if ($guardedMappingIds !== []
                && SupplierOperation::query()
                    ->whereIn('supplier_product_mapping_id', array_values(array_unique($guardedMappingIds)))
                    ->whereIn('status', SupplierOperation::NONTERMINAL_STATUSES)
                    ->lockForUpdate()
                    ->exists()) {
                throw new RuntimeException(
                    'The supplier catalog cannot invalidate mappings while operations are nonterminal.',
                );
            }

            foreach ($products as $attributes) {
                $catalogProduct = $existingProducts->get($attributes['upstream_product_id']);
                $attributes += [
                    'last_seen_at' => $syncedAt,
                    'synced_at' => $syncedAt,
                ];

                if ($catalogProduct === null) {
                    SupplierCatalogProduct::createForAccount($lockedAccount, $attributes);
                } else {
                    $catalogProduct->fill($attributes)->save();
                }
            }

            $productIdLookup = array_fill_keys($productIds, true);
            foreach ($existingProducts as $existingProduct) {
                if (array_key_exists($existingProduct->upstream_product_id, $productIdLookup)) {
                    continue;
                }

                $existingProduct->forceFill([
                    'is_active' => false,
                    'synced_at' => $syncedAt,
                ])->save();
            }

            $mappingIdLookup = array_fill_keys($mappingIdsToDeactivate, true);
            foreach ($mappings as $mapping) {
                if (! array_key_exists($mapping->id, $mappingIdLookup)) {
                    continue;
                }

                $mapping->is_active = false;
                $mapping->save();
            }

            $lockedAccount->forceFill([
                'last_catalog_synced_at' => $syncedAt,
                'last_connected_at' => $syncedAt,
                'last_error' => null,
            ])->save();

            return [
                'product_count' => count($products),
                'active_count' => collect($products)->where('is_active', true)->count(),
                'deactivated_product_count' => $deactivatedProductCount,
                'deactivated_mapping_count' => count($mappingIdsToDeactivate),
                'synced_at' => $syncedAt,
            ];
        }, 3);
    }

    public function billingCycles(SupplierCatalogProduct $product): array
    {
        $cycles = [];
        foreach ($this->cycleValues($product->billing_cycles) as $cycle) {
            $cycles[$cycle] = $cycle;
        }

        $metadata = is_array($product->metadata) ? $product->metadata : [];
        foreach (['default_billing_cycle', 'primary_billing_cycle'] as $field) {
            foreach ($this->cycleValues($metadata[$field] ?? null) as $cycle) {
                $cycles[$cycle] = $cycle;
            }
        }

        return array_values($cycles);
    }

    private function fetchProductIds(FinanceClient $client): array
    {
        $page = 1;
        $requestCount = 0;
        $productIds = [];
        $expectedTotal = null;
        $expectedLastPage = null;
        $expectedPerPage = null;
        $seenProductIds = [];

        do {
            $requestCount++;
            if ($requestCount > self::MAX_PAGES) {
                throw new RuntimeException('The supplier catalog pagination is incomplete.');
            }

            $response = $client->products($page === 1 ? [] : ['page' => $page]);
            $pageIds = $this->extractProductIds($response->data['list']);
            $previousCount = count($productIds);
            foreach ($pageIds as $productId) {
                if (array_key_exists($productId, $seenProductIds)) {
                    throw new RuntimeException(
                        'The supplier catalog returned overlapping pagination results.',
                    );
                }
                $seenProductIds[$productId] = true;
                $productIds[$productId] = $productId;
            }
            if (count($productIds) > self::MAX_PRODUCTS) {
                throw new RuntimeException('The supplier catalog exceeds the synchronization limit.');
            }

            $pagination = $this->pagination(
                $response->data,
                $response->envelope(),
                $page,
                count($pageIds),
            );
            if ($expectedTotal !== null
                && $pagination['total'] !== null
                && $pagination['total'] !== $expectedTotal) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
            $expectedTotal ??= $pagination['total'];
            if ($expectedLastPage !== null
                && $pagination['last_page'] !== null
                && $pagination['last_page'] !== $expectedLastPage) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
            $expectedLastPage ??= $pagination['last_page'];
            if ($expectedPerPage !== null
                && $pagination['per_page'] !== null
                && $pagination['per_page'] !== $expectedPerPage) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
            $expectedPerPage ??= $pagination['per_page'];
            if ($expectedTotal !== null && $expectedTotal > self::MAX_PRODUCTS) {
                throw new RuntimeException('The supplier catalog exceeds the synchronization limit.');
            }
            if ($expectedLastPage !== null && $expectedLastPage > self::MAX_PAGES) {
                throw new RuntimeException('The supplier catalog exceeds the pagination limit.');
            }
            if ($expectedTotal !== null && count($productIds) > $expectedTotal) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }

            $signals = [];
            if ($expectedTotal !== null) {
                $signals[] = count($productIds) < $expectedTotal;
            }
            if ($expectedLastPage !== null) {
                if ($page > $expectedLastPage) {
                    throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
                }
                $signals[] = $page < $expectedLastPage;
            }
            if ($pagination['has_next'] !== null) {
                $signals[] = $pagination['has_next'];
            }
            if ($pagination['next_page_present']) {
                $signals[] = $pagination['next_page'] !== null;
            }
            if ($pagination['complete'] === true) {
                if ($pagination['paginated'] !== false) {
                    throw new RuntimeException(
                        'The supplier catalog returned invalid completeness metadata.',
                    );
                }
                $signals[] = false;
            } elseif ($pagination['complete'] === false && $signals === []) {
                throw new RuntimeException('The supplier catalog pagination is incomplete.');
            } elseif ($pagination['complete'] === false) {
                $signals[] = true;
            }
            if ($signals === []) {
                throw new RuntimeException(
                    'The supplier catalog did not prove that its response was complete.',
                );
            }

            $hasNext = $signals[0];
            if (collect($signals)->contains(fn (bool $signal): bool => $signal !== $hasNext)) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
            if ($hasNext && ($pageIds === [] || count($productIds) === $previousCount)) {
                throw new RuntimeException('The supplier catalog pagination is incomplete.');
            }
            if ($hasNext
                && $expectedPerPage !== null
                && count($pageIds) !== $expectedPerPage) {
                throw new RuntimeException('The supplier catalog returned a truncated page.');
            }

            $nextPage = $pagination['next_page'] ?? ($page + 1);
            if ((! $hasNext && $pagination['next_page_present'] && $pagination['next_page'] !== null)
                || ($hasNext && $pagination['next_page_present'] && $pagination['next_page'] === null)
                || ($hasNext && $nextPage !== $page + 1)) {
                throw new RuntimeException('The supplier catalog returned invalid pagination metadata.');
            }
            $page = $nextPage;
        } while ($hasNext);

        if ($expectedTotal !== null && count($productIds) !== $expectedTotal) {
            throw new RuntimeException('The supplier catalog pagination is incomplete.');
        }

        return array_values($productIds);
    }

    private function extractProductIds(array $list): array
    {
        $productIds = [];
        $this->collectProductIds($list, $productIds, array_is_list($list), 0);
        $uniqueProductIds = array_values(array_unique($productIds, SORT_STRING));
        if (count($uniqueProductIds) !== count($productIds)) {
            throw new RuntimeException('The supplier catalog returned duplicate product identifiers.');
        }

        return $uniqueProductIds;
    }

    private function collectProductIds(
        array $node,
        array &$productIds,
        bool $collection,
        int $depth,
    ): void {
        if ($depth > 12) {
            throw new RuntimeException('The supplier catalog list is nested too deeply.');
        }

        if ($collection || array_is_list($node)) {
            foreach ($node as $item) {
                if (is_string($item) || is_int($item)) {
                    $productIds[] = $this->identifier($item);
                } elseif (is_array($item)) {
                    $this->collectProductIds($item, $productIds, false, $depth + 1);
                } else {
                    throw new RuntimeException('The supplier catalog list contains an invalid item.');
                }
                if (count($productIds) > self::MAX_PRODUCTS) {
                    throw new RuntimeException('The supplier catalog exceeds the synchronization limit.');
                }
            }

            return;
        }

        $foundCollection = false;
        foreach ([
            'products',
            'product_list',
            'items',
            'groups',
            'group',
            'children',
            'first_group',
            'list',
        ] as $field) {
            if (array_key_exists($field, $node)) {
                if (! is_array($node[$field])) {
                    throw new RuntimeException('The supplier catalog list contains an invalid collection.');
                }
                $this->collectProductIds(
                    $node[$field],
                    $productIds,
                    array_is_list($node[$field]),
                    $depth + 1,
                );
                $foundCollection = true;
            }
        }
        if ($foundCollection) {
            return;
        }

        if (array_key_exists('product', $node)) {
            if (! is_array($node['product'])) {
                throw new RuntimeException('The supplier catalog list contains an invalid product.');
            }
            $this->collectProductIds(
                $node['product'],
                $productIds,
                array_is_list($node['product']),
                $depth + 1,
            );

            return;
        }

        $hasIdentifier = array_key_exists('id', $node)
            || array_key_exists('product_id', $node)
            || array_key_exists('pid', $node);
        if (! $hasIdentifier
            && $node !== []
            && collect($node)->every(fn (mixed $item): bool => is_array($item))) {
            foreach ($node as $item) {
                $this->collectProductIds($item, $productIds, false, $depth + 1);
            }

            return;
        }

        $productIds[] = $this->identifier(
            $node['id'] ?? $node['product_id'] ?? $node['pid'] ?? null,
        );
    }

    private function pagination(
        array $data,
        array $envelope,
        int $page,
        int $itemCount,
    ): array {
        $scopes = [];
        $metadataFields = [
            'current_page',
            'currentPage',
            'page',
            'page_num',
            'pageNum',
            'last_page',
            'lastPage',
            'total_pages',
            'totalPages',
            'pages',
            'page_count',
            'pageCount',
            'total',
            'total_count',
            'totalCount',
            'has_more',
            'hasMore',
            'more',
            'has_next',
            'hasNext',
            'is_last_page',
            'isLastPage',
            'complete',
            'is_complete',
            'isComplete',
            'paginated',
            'is_paginated',
            'isPaginated',
            'next_page',
            'nextPage',
            'per_page',
            'perPage',
            'page_size',
            'pageSize',
            'limit',
            'count',
        ];
        foreach ([[$data, 'info'], [$data, 'pagination'], [$envelope, 'info'], [$envelope, 'pagination']] as [$scope, $field]) {
            if (! array_key_exists($field, $scope)) {
                continue;
            }
            if (! is_array($scope[$field])) {
                throw new RuntimeException('The supplier catalog returned invalid pagination metadata.');
            }
            if (array_diff(array_keys($scope[$field]), $metadataFields) !== []) {
                throw new RuntimeException('The supplier catalog returned unknown pagination metadata.');
            }
            $scopes[] = $scope[$field];
        }
        $scopes[] = $data;
        unset($envelope['data']);
        $scopes[] = $envelope;

        $currentPage = $this->metadataValue(
            $scopes,
            ['current_page', 'currentPage', 'page', 'page_num', 'pageNum'],
            fn (mixed $value): ?int => $this->positiveInteger($value),
        );
        if ($currentPage !== null && $currentPage !== $page) {
            throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
        }

        $lastPage = $this->metadataValue(
            $scopes,
            ['last_page', 'lastPage', 'total_pages', 'totalPages', 'pages', 'page_count', 'pageCount'],
            fn (mixed $value): ?int => $this->positiveInteger($value),
        );
        $total = $this->metadataValue(
            $scopes,
            ['total', 'total_count', 'totalCount'],
            fn (mixed $value): ?int => $this->nonNegativeInteger($value),
        );
        $perPage = $this->metadataValue(
            $scopes,
            ['per_page', 'perPage', 'page_size', 'pageSize', 'limit'],
            fn (mixed $value): ?int => $this->positiveInteger($value),
        );
        $this->metadataValue(
            $scopes,
            ['count'],
            fn (mixed $value): ?int => $this->nonNegativeInteger($value),
        );
        if ($perPage !== null && $itemCount > $perPage) {
            throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
        }
        $hasNext = $this->metadataValue(
            $scopes,
            ['has_more', 'hasMore', 'more', 'has_next', 'hasNext'],
            fn (mixed $value): ?bool => filter_var(
                $value,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ),
        );
        $isLastPage = $this->metadataValue(
            $scopes,
            ['is_last_page', 'isLastPage'],
            fn (mixed $value): ?bool => filter_var(
                $value,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ),
        );
        if ($isLastPage !== null) {
            if ($hasNext !== null && $hasNext === $isLastPage) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
            $hasNext = ! $isLastPage;
        }
        if ($lastPage !== null) {
            if ($page > $lastPage || ($hasNext !== null && $hasNext !== ($page < $lastPage))) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
        }

        $complete = $this->metadataValue(
            $scopes,
            ['complete', 'is_complete', 'isComplete'],
            fn (mixed $value): ?bool => filter_var(
                $value,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ),
        );
        $paginated = $this->metadataValue(
            $scopes,
            ['paginated', 'is_paginated', 'isPaginated'],
            fn (mixed $value): ?bool => filter_var(
                $value,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ),
        );
        if ($paginated === false) {
            if ($page !== 1 || $lastPage !== null || $hasNext === true || $complete === false) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
        }

        $nextPage = $this->metadataValue(
            $scopes,
            ['next_page', 'nextPage'],
            fn (mixed $value): ?int => $this->positiveInteger($value),
            true,
        );
        $nextPagePresent = $this->metadataFieldPresent($scopes, ['next_page', 'nextPage']);

        return [
            'has_next' => $hasNext,
            'next_page' => $nextPage,
            'total' => $total,
            'last_page' => $lastPage,
            'complete' => $complete,
            'paginated' => $paginated,
            'per_page' => $perPage,
            'next_page_present' => $nextPagePresent,
        ];
    }

    private function normalizeProduct(
        array $product,
        string $productId,
        array $responseData,
        array $sensitiveValues,
    ): array {
        $singularCycleValues = [];
        foreach (['billingcycle', 'billing_cycle', 'default_billing_cycle', 'cycle'] as $field) {
            if (! array_key_exists($field, $product)) {
                continue;
            }
            $singularCycleValues = array_merge(
                $singularCycleValues,
                $this->cycleValues($product[$field]),
            );
        }
        $singularCycleValues = array_values(array_unique($singularCycleValues));
        $defaultCycle = $singularCycleValues[0] ?? null;
        $defaultPrice = $this->amount(
            is_array($product['product_price'] ?? null)
                ? null
                : ($product['product_price'] ?? $product['price'] ?? $product['sale_price'] ?? null),
        );
        $defaultSetupFee = $this->amount(
            $product['setup_fee'] ?? $product['setup'] ?? $product['setup_price'] ?? null,
        );
        $cycles = [];
        foreach ($singularCycleValues as $cycle) {
            $this->addCycle(
                $cycles,
                $cycle,
                $cycle === $defaultCycle ? $defaultPrice : null,
                $cycle === $defaultCycle ? $defaultSetupFee : null,
                $cycle === $defaultCycle,
            );
        }
        foreach (['billingcycle', 'billing_cycle', 'default_billing_cycle', 'cycle'] as $field) {
            if (is_array($product[$field] ?? null)) {
                $this->collectCyclePrices([$product[$field]], $cycles);
            }
        }

        foreach ([
            'prices',
            'product_price',
            'product_prices',
            'billing_cycles',
            'billingcycles',
            'cycle_prices',
            'price_list',
            'cycles',
        ] as $field) {
            if (is_array($product[$field] ?? null)) {
                $this->collectCyclePrices($product[$field], $cycles);
            }
        }

        foreach (self::KNOWN_CYCLES as $cycle) {
            foreach ([$cycle.'_price', 'price_'.$cycle, $cycle] as $priceField) {
                $price = $this->amount($product[$priceField] ?? null);
                if ($price !== null) {
                    $this->addCycle(
                        $cycles,
                        $cycle,
                        $price,
                        $this->amount(
                            $product[$cycle.'_setup_fee'] ?? $product['setup_fee_'.$cycle] ?? null,
                        ),
                    );
                    break;
                }
            }
        }

        foreach (array_keys($cycles) as $cycle) {
            if ($this->containsSensitiveValue($cycle, $sensitiveValues)) {
                throw new RuntimeException('The supplier returned an unsafe billing cycle.');
            }
        }

        if ($defaultCycle === null) {
            foreach ($cycles as $cycle => $pricing) {
                if ($pricing['is_default']) {
                    $defaultCycle = $cycle;
                    break;
                }
            }
            $defaultCycle ??= array_key_first($cycles);
        }
        if ($defaultCycle !== null && array_key_exists($defaultCycle, $cycles)) {
            $defaultPrice ??= $cycles[$defaultCycle]['price'];
            $defaultSetupFee ??= $cycles[$defaultCycle]['setup_fee'];
            $cycles[$defaultCycle]['is_default'] = true;
        }

        $prices = collect($cycles)
            ->pluck('price')
            ->filter(fn (mixed $price): bool => is_string($price))
            ->values();
        if ($prices->isEmpty() && $defaultPrice !== null) {
            $prices->push($defaultPrice);
        }

        return [
            'upstream_product_id' => $productId,
            'upstream_group_id' => $this->text(
                $product['gid'] ?? $product['group_id'] ?? $product['product_group_id'] ?? null,
                128,
                $sensitiveValues,
            ),
            'type' => $this->text(
                $product['type'] ?? $product['product_type'] ?? null,
                64,
                $sensitiveValues,
            ),
            'name' => $this->text(
                $product['name'] ?? $product['product_name'] ?? null,
                191,
                $sensitiveValues,
            )
                ?? 'Upstream product '.$productId,
            'description' => $this->text(
                $product['description'] ?? $product['desc'] ?? null,
                10000,
                $sensitiveValues,
            ),
            'currency' => $this->currency(
                $product['currency_code']
                    ?? $product['currency']
                    ?? $responseData['currency_code']
                    ?? $responseData['currency']
                    ?? null,
                $sensitiveValues,
            ),
            'minimum_price' => $prices->isEmpty() ? null : $prices->min(),
            'billing_cycles' => array_keys($cycles),
            'is_active' => $this->productIsActive($product),
            'metadata' => [
                'primary_billing_cycle' => $defaultCycle,
                'default_billing_cycle' => $defaultCycle,
                'default_price' => $defaultPrice,
                'default_setup_fee' => $defaultSetupFee,
                'prices' => $cycles,
            ],
        ];
    }

    private function collectCyclePrices(array $entries, array &$cycles): void
    {
        foreach ($entries as $key => $entry) {
            if (is_array($entry)) {
                $cycle = $this->cycle(
                    $entry['billingcycle']
                        ?? $entry['billing_cycle']
                        ?? $entry['cycle']
                        ?? (is_string($key) ? $key : null),
                );
                if ($cycle !== null) {
                    $this->addCycle(
                        $cycles,
                        $cycle,
                        $this->amount(
                            $entry['product_price'] ?? $entry['price'] ?? $entry['sale_price'] ?? null,
                        ),
                        $this->amount(
                            $entry['setup_fee'] ?? $entry['setup'] ?? $entry['setup_price'] ?? null,
                        ),
                        filter_var(
                            $entry['is_default'] ?? false,
                            FILTER_VALIDATE_BOOL,
                        ),
                    );
                }

                continue;
            }

            if (is_string($key)) {
                $cycle = $this->cycle($key);
                if ($cycle !== null) {
                    $this->addCycle($cycles, $cycle, $this->amount($entry), null);
                }
            } elseif (is_string($entry)) {
                $cycle = $this->cycle($entry);
                if ($cycle !== null) {
                    $this->addCycle($cycles, $cycle, null, null);
                }
            }
        }
    }

    private function addCycle(
        array &$cycles,
        string $cycle,
        ?string $price,
        ?string $setupFee,
        bool $default = false,
    ): void {
        $existing = $cycles[$cycle] ?? [
            'price' => null,
            'setup_fee' => null,
            'is_default' => false,
        ];
        $cycles[$cycle] = [
            'price' => $price ?? $existing['price'],
            'setup_fee' => $setupFee ?? $existing['setup_fee'],
            'is_default' => $default || $existing['is_default'],
        ];
    }

    private function productIsActive(array $product): bool
    {
        foreach (['is_active', 'active'] as $field) {
            if (! array_key_exists($field, $product)) {
                continue;
            }
            $active = filter_var($product[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                return $active;
            }

            throw new RuntimeException('The supplier product activity flag is invalid.');
        }
        if (array_key_exists('hidden', $product)) {
            $hidden = filter_var($product['hidden'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($hidden !== null) {
                return ! $hidden;
            }

            throw new RuntimeException('The supplier product visibility flag is invalid.');
        }

        return ! in_array(
            strtolower(trim((string) ($product['status'] ?? ''))),
            ['disabled', 'inactive', 'hidden', 'deleted'],
            true,
        );
    }

    private function accountFingerprint(SupplierAccount $account): string
    {
        $credentials = $account->credentials;

        return hash('sha256', json_encode([
            'id' => $account->getKey(),
            'code' => $account->code,
            'driver' => $account->driver,
            'base_url' => $account->base_url,
            'is_active' => $account->is_active,
            'options' => $account->options,
            'last_catalog_synced_at' => $account->last_catalog_synced_at?->format('Y-m-d H:i:s.uP'),
            'credentials' => is_array($credentials) ? $credentials : [],
        ], JSON_THROW_ON_ERROR));
    }

    private function identifier(mixed $identifier): string
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new RuntimeException('The supplier product identifier is invalid.');
        }

        $identifier = trim((string) $identifier);
        if ($identifier === ''
            || $identifier === '0'
            || strlen($identifier) > 128
            || preg_match('/[^\x20-\x7e]/', $identifier)
            || preg_match('/\AeyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?\z/', $identifier)) {
            throw new RuntimeException('The supplier product identifier is invalid.');
        }

        return $identifier;
    }

    private function cycle(mixed $cycle): ?string
    {
        if (! is_string($cycle) && ! is_int($cycle)) {
            return null;
        }

        $cycle = strtolower(trim((string) $cycle));

        return $cycle !== ''
            && strlen($cycle) <= 32
            && preg_match('/^[a-z0-9_.-]+$/', $cycle)
            ? $cycle
            : null;
    }

    private function cycleValues(mixed $value, int $depth = 0): array
    {
        if ($depth > 8) {
            throw new RuntimeException('The supplier billing cycles are nested too deeply.');
        }
        if (is_string($value) || is_int($value)) {
            $cycle = $this->cycle($value);

            return $cycle === null ? [] : [$cycle];
        }
        if (! is_array($value)) {
            return [];
        }

        $cycles = [];
        $cycleFields = [
            'billingcycle',
            'billing_cycle',
            'default_billing_cycle',
            'primary_billing_cycle',
            'cycle',
            'value',
        ];
        $hasCycleField = false;
        foreach ($cycleFields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }
            $hasCycleField = true;
            $cycles = array_merge($cycles, $this->cycleValues($value[$field], $depth + 1));
        }
        if ($hasCycleField) {
            return array_values(array_unique($cycles));
        }

        foreach ($value as $key => $entry) {
            if (is_string($key) && ! ctype_digit($key)) {
                $cycle = $this->cycle($key);
                if ($cycle !== null) {
                    $cycles[] = $cycle;
                }
                if (is_array($entry)) {
                    $cycles = array_merge($cycles, $this->cycleValues($entry, $depth + 1));
                }

                continue;
            }
            $cycles = array_merge($cycles, $this->cycleValues($entry, $depth + 1));
        }

        return array_values(array_unique($cycles));
    }

    private function amount(mixed $amount): ?string
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }
        if (is_float($amount)) {
            if (! is_finite($amount) || $amount < 0) {
                return null;
            }
            $amount = number_format($amount, 8, '.', '');
        }

        $amount = trim((string) $amount);
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            return null;
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 16
            || (strlen($whole) === 16 && strcmp($whole, '9999999999999999') > 0)) {
            return null;
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $minor = ((int) $whole * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $minor++;
        }
        if ($minor > 999999999999999999) {
            return null;
        }

        return Money::format($minor);
    }

    private function text(mixed $value, int $maxLength, array $sensitiveValues = []): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($this->containsSensitiveValue($value, $sensitiveValues)) {
            return null;
        }
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $value) ?? '';
        $value = SupplierErrorSanitizer::sanitize($value) ?? '';
        $value = preg_replace(
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?\b/',
            '[REDACTED]',
            $value,
        ) ?? '';

        return $value === '' ? null : mb_substr(trim($value), 0, $maxLength);
    }

    private function currency(mixed $currency, array $sensitiveValues): ?string
    {
        if (is_array($currency)) {
            $currency = $currency['code'] ?? $currency['currency_code'] ?? null;
        }
        $currency = $this->text($currency, 8, $sensitiveValues);
        $currency = $currency === null ? null : strtoupper($currency);

        return $currency !== null && preg_match('/^[A-Z0-9]{3,8}$/D', $currency)
            ? $currency
            : null;
    }

    private function containsSensitiveValue(string $value, array $sensitiveValues): bool
    {
        if ($value === '') {
            return false;
        }

        foreach ($sensitiveValues as $sensitiveValue) {
            if ($sensitiveValue !== '' && str_contains($value, $sensitiveValue)) {
                return true;
            }
        }

        return false;
    }

    private function sensitiveValues(mixed $payload): array
    {
        $values = [];
        if (! is_array($payload)) {
            return $values;
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->sensitiveValues($value));
            } elseif (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = $this->nonNegativeInteger($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function metadataValue(
        array $scopes,
        array $fields,
        callable $normalize,
        bool $allowNull = false,
    ): mixed {
        $values = [];

        foreach ($scopes as $scope) {
            foreach ($fields as $field) {
                if (! array_key_exists($field, $scope)) {
                    continue;
                }
                if ($allowNull && $scope[$field] === null) {
                    $values[] = null;

                    continue;
                }
                $value = $normalize($scope[$field]);
                if ($value === null) {
                    throw new RuntimeException('The supplier catalog returned invalid pagination metadata.');
                }
                $values[] = $value;
            }
        }
        if ($values === []) {
            return null;
        }

        $first = $values[0];
        foreach ($values as $value) {
            if ($value !== $first) {
                throw new RuntimeException('The supplier catalog returned inconsistent pagination metadata.');
            }
        }

        return $first;
    }

    private function metadataFieldPresent(array $scopes, array $fields): bool
    {
        foreach ($scopes as $scope) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $scope)) {
                    return true;
                }
            }
        }

        return false;
    }
}
