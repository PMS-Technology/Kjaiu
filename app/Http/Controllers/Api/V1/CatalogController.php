<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductConfigGroup;
use App\Models\ProductFirstGroup;
use App\Models\ProductGroup;
use App\Services\PricingService;
use Illuminate\Http\Request;

/**
 * Storefront catalogue: product groups, product listings, product detail with
 * configurable options, and price totals.
 *
 * Field names follow the original API (`pricing`, `configoptions`,
 * `customfields`, `pay_type`) so downstream clients can render the same data
 * without translation.
 */
class CatalogController extends ApiController
{
    public function __construct(
        protected PricingService $pricing = new PricingService(),
    ) {
    }

    /**
     * GET /v1/products — first-level groups with their product groups.
     */
    public function products()
    {
        $currencyId = $this->pricing->currencyId();

        $groups = ProductFirstGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->with(['groups' => fn ($q) => $q->where('hidden', 0)->orderBy('order')])
            ->get();

        $data = $groups->map(function (ProductFirstGroup $first) use ($currencyId) {
            return [
                'id' => $first->id,
                'name' => $first->name,
                'group' => $first->groups->map(function (ProductGroup $group) use ($currencyId) {
                    $products = Product::query()
                        ->where('gid', $group->id)
                        ->where('hidden', 0)
                        ->where('retired', 0)
                        ->orderBy('order')
                        ->get();

                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'headline' => $group->headline,
                        'tagline' => $group->tagline,
                        'products' => $products->map(fn (Product $p) => $this->productSummary($p, $currencyId))->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return $this->ok($data);
    }

    /**
     * GET /v1/products/cates — flat category list used by the service filters.
     */
    public function cates()
    {
        $cates = ProductFirstGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->get()
            ->map(fn (ProductFirstGroup $g) => ['id' => $g->id, 'name' => $g->name])
            ->values()
            ->all();

        return $this->ok($cates);
    }

    /**
     * GET /v1/products/cates/{id} — products inside one category.
     */
    public function productsByCate(int $id)
    {
        $currencyId = $this->pricing->currencyId();

        $groups = ProductGroup::query()
            ->where('gid', $id)
            ->where('hidden', 0)
            ->orderBy('order')
            ->get();

        $data = $groups->map(function (ProductGroup $group) use ($currencyId) {
            $products = Product::query()
                ->where('gid', $group->id)
                ->where('hidden', 0)
                ->where('retired', 0)
                ->orderBy('order')
                ->get();

            return [
                'id' => $group->id,
                'name' => $group->name,
                'products' => $products->map(fn (Product $p) => $this->productSummary($p, $currencyId))->values()->all(),
            ];
        })->values()->all();

        return $this->ok($data);
    }

    /**
     * GET /v1/products/config — configuration form for a product.
     *
     * Query: pid (product id) or gid+pid for the legacy shape.
     */
    public function productsConfig(Request $request)
    {
        return $this->goodsConfig($request);
    }

    /**
     * GET /v1/goodsconfig — configuration form for a product.
     */
    public function goodsConfig(Request $request)
    {
        $productId = (int) $request->input('pid', $request->input('id', 0));
        $product = Product::query()->find($productId);

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        if (! $product->isPurchasable()) {
            return $this->fail('商品已下架');
        }

        $currencyId = $this->pricing->currencyId();
        $currency = $this->currency();

        return $this->ok([
            'product' => $this->productDetailPayload($product, $currencyId),
            'pricing' => $this->pricingPayload($product, $currencyId),
            'configoptions' => $this->configOptionsPayload($product, $currencyId),
            'customfields' => $this->customFieldsPayload($product),
            'currency' => $currency === null ? null : [
                'id' => $currency->id,
                'code' => $currency->code,
                'prefix' => $currency->prefix,
                'suffix' => $currency->suffix,
            ],
        ]);
    }

    /**
     * GET /v1/products/{id} — product detail.
     */
    public function productDetail(int $id)
    {
        $product = Product::query()->find($id);

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        $currencyId = $this->pricing->currencyId();

        return $this->ok([
            'product' => $this->productDetailPayload($product, $currencyId),
            'pricing' => $this->pricingPayload($product, $currencyId),
            'configoptions' => $this->configOptionsPayload($product, $currencyId),
            'customfields' => $this->customFieldsPayload($product),
        ]);
    }

    /**
     * GET /v1/goods/{fgid?}/{gid?}/{pid?} — legacy storefront route.
     */
    public function goods(?int $fgid = null, ?int $gid = null, ?int $pid = null)
    {
        if ($pid !== null) {
            return $this->productDetail($pid);
        }

        if ($gid !== null) {
            return $this->productsByCate($fgid ?? $gid);
        }

        return $this->products();
    }

    /**
     * POST /v1/products/total — price for a configuration without adding it.
     */
    public function productsTotal(Request $request)
    {
        return $this->goodsTotal($request);
    }

    /**
     * POST /v1/goods/total — price for a configuration without adding it.
     */
    public function goodsTotal(Request $request)
    {
        $product = Product::query()->find((int) $request->input('pid', $request->input('id', 0)));

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        $cycle = (string) $request->input('cycle', 'monthly');
        $qty = max(1, (int) $request->input('qty', 1));
        $currencyId = $this->pricing->currencyId();

        $unit = $this->pricing->cyclePrice($product, $cycle, $currencyId);

        if ($unit === null) {
            return $this->fail('该商品不支持所选计费周期');
        }

        $options = (array) $request->input('configoptions', []);
        $optionsTotal = $this->pricing->configOptionsTotal($product, $options, $cycle, $currencyId);
        $setup = $this->pricing->setupFee($product, $cycle, $currencyId);
        $total = PricingService::money(($unit + $optionsTotal) * $qty + $setup);

        return $this->ok([
            'unit_amount' => $this->money($unit),
            'configoptions_amount' => $this->money($optionsTotal),
            'setup_fee' => $this->money($setup),
            'qty' => $qty,
            'subtotal' => $this->money($total),
            'total' => $this->money($total),
        ]);
    }

    /**
     * Compact product row for list payloads.
     */
    protected function productSummary(Product $product, ?int $currencyId): array
    {
        $pricing = $this->pricing->productPricing($product, $currencyId);
        $cycles = $pricing?->availableCycles() ?? [];

        $prices = [];
        foreach ($cycles as $cycle) {
            $prices[$cycle] = $this->money((float) $pricing->priceFor($cycle));
        }

        return [
            'id' => $product->id,
            'gid' => $product->gid,
            'name' => $product->name,
            'type' => $product->type,
            'description' => $product->description,
            'stock_control' => (int) $product->stock_control,
            'qty' => (int) $product->qty,
            'allow_qty' => (int) $product->allow_qty,
            'pay_type' => $product->payType(),
            'is_featured' => (int) $product->is_featured,
            'pricing' => $prices,
            'product_shopping_url' => (string) $product->product_shopping_url,
        ];
    }

    protected function productDetailPayload(Product $product, ?int $currencyId): array
    {
        return array_merge($this->productSummary($product, $currencyId), [
            'is_domain' => (int) $product->is_domain,
            'is_truename' => (int) $product->is_truename,
            'clientscount' => (int) $product->clientscount,
            'cancel_control' => (int) $product->cancel_control,
            'config_options_upgrade' => (int) $product->config_options_upgrade,
            'billing_cycle_upgrade' => (string) $product->billing_cycle_upgrade,
        ]);
    }

    /**
     * Pricing rows keyed by cycle, matching the original's response shape.
     */
    protected function pricingPayload(Product $product, ?int $currencyId): array
    {
        $pricing = $this->pricing->productPricing($product, $currencyId);

        if ($pricing === null) {
            return [];
        }

        $out = [];

        foreach (\App\Models\Pricing::CYCLES as $cycle) {
            $price = $pricing->priceFor($cycle);

            if ($price === null) {
                continue;
            }

            $out[$cycle] = [
                'price' => $this->money($price),
                'setup_fee' => $this->money($pricing->setupFeeFor($cycle)),
            ];
        }

        return $out;
    }

    /**
     * Configurable option groups for a product.
     */
    protected function configOptionsPayload(Product $product, ?int $currencyId): array
    {
        $groups = $this->pricing->productConfigGroups($product);

        return array_map(function (ProductConfigGroup $group) use ($currencyId) {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'option' => $group->options->map(function ($option) use ($currencyId) {
                    return [
                        'id' => $option->id,
                        'option_name' => $option->option_name,
                        'option_type' => (int) $option->option_type,
                        'qty_minimum' => (int) $option->qty_minimum,
                        'qty_maximum' => (int) $option->qty_maximum,
                        'notes' => $option->notes,
                        'sub' => $option->subOptions->map(function ($sub) use ($currencyId) {
                            $pricing = $this->pricing->optionPricing($sub, $currencyId);
                            $prices = [];

                            if ($pricing !== null) {
                                foreach (\App\Models\Pricing::CYCLES as $cycle) {
                                    $price = $pricing->priceFor($cycle);

                                    if ($price !== null) {
                                        $prices[$cycle] = $this->money($price);
                                    }
                                }
                            }

                            return [
                                'id' => $sub->id,
                                'option_name' => $sub->option_name,
                                'qty_minimum' => (int) $sub->qty_minimum,
                                'qty_maximum' => (int) $sub->qty_maximum,
                                'pricing' => $prices,
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
            ];
        }, $groups);
    }

    /**
     * Custom fields attached to a product.
     */
    protected function customFieldsPayload(Product $product): array
    {
        return \App\Models\CustomField::query()
            ->where('type', 'product')
            ->where('relid', $product->id)
            ->orderBy('sortorder')
            ->get()
            ->map(fn (\App\Models\CustomField $field) => [
                'id' => $field->id,
                'fieldname' => $field->fieldname,
                'fieldtype' => $field->fieldtype,
                'description' => $field->description,
                'required' => (int) $field->required,
                'options' => $field->options(),
            ])
            ->values()
            ->all();
    }
}
