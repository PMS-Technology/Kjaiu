<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogImport;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierProductMapping;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierCatalogImportService
{
    public function import(
        Request $request,
        SupplierAccount $account,
        ProductGroup $group,
        User $administrator,
        array $catalogProductIds,
        string $exchangeRate = '1',
    ): array {
        return DB::transaction(function () use (
            $request,
            $account,
            $group,
            $administrator,
            $catalogProductIds,
            $exchangeRate,
        ): array {
            $account = SupplierAccount::query()->lockForUpdate()->findOrFail($account->getKey());
            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'catalog_products' => '供应商账户已停用，不能导入商品。',
                ]);
            }

            $group = ProductGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            if ($group->parent_id === null || ! $group->is_active) {
                throw ValidationException::withMessages([
                    'product_group_id' => '请选择正常启用的子商品分组。',
                ]);
            }

            $catalogProducts = SupplierCatalogProduct::query()
                ->where('supplier_account_id', $account->getKey())
                ->whereIn('id', $catalogProductIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($catalogProducts->count() !== count($catalogProductIds)) {
                throw ValidationException::withMessages([
                    'catalog_products' => '选择中包含不属于该供应商的商品。',
                ]);
            }

            $localCurrency = strtoupper((string) config('kjaiu.currency.code', 'CNY'));
            $foreignCurrencies = $catalogProducts
                ->map(fn (SupplierCatalogProduct $product): string => strtoupper((string) $product->currency))
                ->reject(fn (string $currency): bool => $currency === $localCurrency)
                ->unique();
            if ($foreignCurrencies->count() > 1) {
                throw ValidationException::withMessages([
                    'catalog_products' => '一次只能导入一种外币商品，请按币种分批导入并填写对应汇率。',
                ]);
            }

            $existingImports = SupplierCatalogImport::query()
                ->whereIn('supplier_catalog_product_id', $catalogProductIds)
                ->lockForUpdate()
                ->pluck('supplier_catalog_product_id');
            if ($existingImports->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'catalog_products' => '选择中包含已经导入的商品，请刷新列表后重试。',
                ]);
            }

            $imported = [];
            foreach ($catalogProducts as $catalogProduct) {
                if (! $catalogProduct->is_active) {
                    throw ValidationException::withMessages([
                        'catalog_products' => "上游商品 {$catalogProduct->name} 已停用。",
                    ]);
                }
                $upstreamCurrency = strtoupper((string) $catalogProduct->currency);
                $rate = $upstreamCurrency === $localCurrency ? '1' : $exchangeRate;
                $prices = $this->prices($catalogProduct, $rate);
                if ($prices === []) {
                    throw ValidationException::withMessages([
                        'catalog_products' => "上游商品 {$catalogProduct->name} 没有可导入的价格周期。",
                    ]);
                }
                $defaultCycle = $this->defaultCycle($catalogProduct, $prices);
                $defaultPrice = $prices[$defaultCycle];
                $product = Product::create([
                    'product_group_id' => $group->getKey(),
                    'type' => $catalogProduct->type ?: 'server',
                    'name' => $catalogProduct->name,
                    'description' => $catalogProduct->description,
                    'billing_cycle' => $defaultCycle,
                    'price' => $defaultPrice['price'],
                    'setup_fee' => $defaultPrice['setup_fee'],
                    'stock_control' => false,
                    'quantity' => null,
                    'auto_setup' => false,
                    'is_active' => false,
                    'metadata' => [
                        'supplier_catalog_product_id' => $catalogProduct->getKey(),
                        'supplier_account_id' => $account->getKey(),
                        'upstream_currency' => $upstreamCurrency,
                        'local_currency' => $localCurrency,
                        'import_exchange_rate' => $rate,
                    ],
                ]);

                foreach ($prices as $cycle => $price) {
                    if ($cycle !== $defaultCycle) {
                        $product->prices()->create([
                            'billing_cycle' => $cycle,
                            'price' => $price['price'],
                            'setup_fee' => $price['setup_fee'],
                            'is_active' => true,
                        ]);
                    }
                    SupplierProductMapping::createFor($account, $catalogProduct, $product, [
                        'local_billing_cycle' => $cycle,
                        'upstream_billing_cycle' => $cycle,
                        'options' => [],
                        'is_active' => true,
                    ]);
                }

                SupplierCatalogImport::createFor(
                    $account,
                    $catalogProduct,
                    $product,
                    $administrator,
                );
                $imported[] = [
                    'catalog_product_id' => $catalogProduct->getKey(),
                    'product_id' => $product->getKey(),
                    'cycle_count' => count($prices),
                ];
            }

            AuditLog::record($request, 'supplier.catalog_products_imported', $account, null, [
                'product_group_id' => $group->getKey(),
                'imported_count' => count($imported),
                'products' => $imported,
            ]);

            return $imported;
        }, 3);
    }

    private function prices(SupplierCatalogProduct $catalogProduct, string $exchangeRate): array
    {
        $metadata = is_array($catalogProduct->metadata) ? $catalogProduct->metadata : [];
        $source = is_array($metadata['prices'] ?? null) ? $metadata['prices'] : [];
        $availableCycles = array_fill_keys(
            is_array($catalogProduct->billing_cycles) ? $catalogProduct->billing_cycles : [],
            true,
        );
        $prices = [];
        foreach ($source as $cycle => $price) {
            if (! is_string($cycle)
                || ! array_key_exists($cycle, $availableCycles)
                || preg_match('/\A[a-z0-9_.-]+\z/', $cycle) !== 1
                || strlen($cycle) > 32
                || ! is_array($price)
                || ! $this->validMoney($price['price'] ?? null)
                || ! $this->validMoney($price['setup_fee'] ?? '0')) {
                continue;
            }
            $prices[$cycle] = [
                'price' => $this->convertMoney($price['price'], $exchangeRate),
                'setup_fee' => $this->convertMoney($price['setup_fee'] ?? '0', $exchangeRate),
            ];
        }

        return $prices;
    }

    private function defaultCycle(SupplierCatalogProduct $catalogProduct, array $prices): string
    {
        $metadata = is_array($catalogProduct->metadata) ? $catalogProduct->metadata : [];
        foreach (['primary_billing_cycle', 'default_billing_cycle'] as $key) {
            $cycle = $metadata[$key] ?? null;
            if (is_string($cycle) && array_key_exists($cycle, $prices)) {
                return $cycle;
            }
        }

        return array_key_first($prices);
    }

    private function validMoney(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', (string) $value) === 1
            && (float) $value <= 999999999999.99;
    }

    private function normalizeMoney(mixed $value): string
    {
        [$whole, $decimal] = array_pad(explode('.', (string) $value, 2), 2, '');
        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole).'.'.str_pad($decimal, 2, '0');
    }

    private function convertMoney(mixed $value, string $exchangeRate): string
    {
        [$amountWhole, $amountDecimal] = array_pad(explode('.', (string) $value, 2), 2, '');
        [$rateWhole, $rateDecimal] = array_pad(explode('.', $exchangeRate, 2), 2, '');
        $amountMinor = ((int) $amountWhole * 100) + (int) str_pad($amountDecimal, 2, '0');
        $rateFraction = (int) str_pad($rateDecimal, 6, '0');
        $fractionQuotient = intdiv($amountMinor, 1_000_000);
        $fractionRemainder = $amountMinor % 1_000_000;
        $convertedMinor = ($amountMinor * (int) $rateWhole)
            + ($fractionQuotient * $rateFraction)
            + intdiv(($fractionRemainder * $rateFraction) + 500_000, 1_000_000);
        if ($convertedMinor > 999_999_999_999_999_999) {
            throw ValidationException::withMessages([
                'exchange_rate' => '汇率换算后的商品价格超过系统支持范围。',
            ]);
        }

        return intdiv($convertedMinor, 100).'.'.str_pad((string) ($convertedMinor % 100), 2, '0', STR_PAD_LEFT);
    }
}
