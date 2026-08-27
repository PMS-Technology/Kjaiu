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
    ): array {
        return DB::transaction(function () use (
            $request,
            $account,
            $group,
            $administrator,
            $catalogProductIds,
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

            $existingImports = SupplierCatalogImport::query()
                ->whereIn('supplier_catalog_product_id', $catalogProductIds)
                ->lockForUpdate()
                ->pluck('supplier_catalog_product_id');
            if ($existingImports->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'catalog_products' => '选择中包含已经导入的商品，请刷新列表后重试。',
                ]);
            }

            $localCurrency = strtoupper((string) config('kjaiu.currency.code', 'CNY'));
            $imported = [];
            foreach ($catalogProducts as $catalogProduct) {
                if (! $catalogProduct->is_active) {
                    throw ValidationException::withMessages([
                        'catalog_products' => "上游商品 {$catalogProduct->name} 已停用。",
                    ]);
                }
                if (strtoupper((string) $catalogProduct->currency) !== $localCurrency) {
                    throw ValidationException::withMessages([
                        'catalog_products' => "上游商品 {$catalogProduct->name} 的币种与本地币种不一致。",
                    ]);
                }

                $prices = $this->prices($catalogProduct);
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

    private function prices(SupplierCatalogProduct $catalogProduct): array
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
                'price' => $this->normalizeMoney($price['price']),
                'setup_fee' => $this->normalizeMoney($price['setup_fee'] ?? '0'),
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
}
