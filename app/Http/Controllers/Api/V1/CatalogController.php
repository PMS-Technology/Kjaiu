<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductFirstGroup;
use App\Models\ProductGroup;
use App\Services\Api\ProductPresenter;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Storefront catalogue: product groups, product listings, product detail with
 * configurable options, and price totals.
 *
 * Payload shapes follow the live ZJMF 3.7.6 responses — prices are flattened
 * onto each product row (`product_price` / `setup_fee` / `billingcycle`) and
 * the tree is wrapped under `first_group` next to `currency`, because
 * downstream integrations read those exact keys.
 */
class CatalogController extends ApiController
{
    public function __construct(
        protected PricingService $pricing = new PricingService(),
        protected ProductPresenter $presenter = new ProductPresenter(),
    ) {
    }

    /**
     * `name`/`value` rows attached to a first-level group or a product group.
     *
     * @return array<int, array{id:int, name:string, value:string}>
     */
    protected function groupFields(int $relid, string $level): array
    {
        $table = $level === 'first'
            ? 'product_first_groups_customfields'
            : 'product_groups_customfields';

        return ProductPresenter::namedFields(
            DB::table($table)->where('relid', $relid)->orderBy('id')->get()
        );
    }

    /**
     * The active currency, in the shape the live platform emits.
     */
    protected function currencyPayload(mixed $currency): ?array
    {
        if ($currency === null) {
            return null;
        }

        return [
            'id' => (int) $currency->id,
            'code' => (string) $currency->code,
            'prefix' => (string) $currency->prefix,
            'suffix' => (string) $currency->suffix,
        ];
    }

    /**
     * GET /v1/products — first-level groups with their product groups.
     *
     * The live platform wraps the tree under `first_group` and puts the active
     * currency beside it, so both keys are reproduced here. The optional
     * `first_group_id` / `group_id` / `product_id` filters are accepted for
     * signature compatibility; ZJMF 3.7.6 ignores them and always returns the
     * whole catalogue, and matching that behaviour keeps downstreams working.
     */
    public function products()
    {
        $currencyId = $this->pricing->currencyId();
        $currency = $this->currency();

        $groups = ProductFirstGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->with(['groups' => fn ($q) => $q->where('hidden', 0)->orderBy('order')])
            ->get();

        $data = $groups->map(function (ProductFirstGroup $first) use ($currencyId) {
            return [
                'id' => (int) $first->id,
                'name' => (string) $first->name,
                'fields' => $this->groupFields($first->id, 'first'),
                'group' => $first->groups->map(function (ProductGroup $group) use ($currencyId) {
                    $products = Product::query()
                        ->where('gid', $group->id)
                        ->where('hidden', 0)
                        ->where('retired', 0)
                        ->orderBy('order')
                        ->get();

                    return [
                        'id' => (int) $group->id,
                        'name' => (string) $group->name,
                        'headline' => (string) $group->headline,
                        'tagline' => (string) $group->tagline,
                        'fields' => $this->groupFields($group->id, 'group'),
                        'products' => $products->map(fn (Product $p) => $this->presenter->summary($p, $currencyId))->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return $this->ok([
            'first_group' => $data,
            'currency' => $this->currencyPayload($currency),
        ]);
    }

    /**
     * GET /v1/products/cates — flat category list used by the service filters.
     *
     * The live platform wraps the rows under `cates`.
     */
    public function cates()
    {
        $cates = ProductFirstGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->get()
            ->map(fn (ProductFirstGroup $g) => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
            ])
            ->values()
            ->all();

        return $this->ok(['cates' => $cates]);
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
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'headline' => (string) $group->headline,
                'tagline' => (string) $group->tagline,
                'fields' => $this->groupFields($group->id, 'group'),
                'products' => $products->map(fn (Product $p) => $this->presenter->summary($p, $currencyId))->values()->all(),
            ];
        })->values()->all();

        return $this->ok(['group' => $data]);
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
     *
     * The live payload nests the product under the same `first_group` tree as
     * `/v1/products`, with `cycle`, `configoptions` and `custom_fields` hanging
     * off the product row, and returns `currency` as a one-element array.
     */
    public function goodsConfig(Request $request)
    {
        $productId = (int) $request->input('product_id', $request->input('pid', $request->input('id', 0)));
        $product = Product::query()->find($productId);

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        if (! $product->isPurchasable()) {
            return $this->fail('商品已下架');
        }

        return $this->ok($this->configPayload($product));
    }

    /**
     * Product tree used by the configuration-form endpoints.
     */
    protected function configPayload(Product $product): array
    {
        $currencyId = $this->pricing->currencyId();
        $currency = $this->currency();
        $group = ProductGroup::query()->find($product->gid);
        $first = $group === null ? null : ProductFirstGroup::query()->find($group->gid);

        $row = [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'description' => (string) $product->description,
            'host' => ['host' => (string) $this->firstHostRule($product)],
            'password' => ['password' => ''],
            'allow_qty' => (int) $product->allow_qty,
            'stock_control' => (int) $product->stock_control,
            'qty' => (int) $product->qty,
            'cycle' => $this->presenter->cycleRows($product, $currencyId),
            'configoptions' => $this->presenter->configOptions($product, $currencyId),
            'custom_fields' => $this->presenter->customFields($product),
        ];

        return [
            'currency' => $currency === null ? [] : [$this->currencyPayload($currency)],
            'first_group' => [[
                'id' => (int) ($first->id ?? 0),
                'name' => (string) ($first->name ?? ''),
                'fields' => $first === null ? [] : $this->groupFields($first->id, 'first'),
                'group' => [[
                    'id' => (int) ($group->id ?? 0),
                    'name' => (string) ($group->name ?? ''),
                    'headline' => (string) ($group->headline ?? ''),
                    'tagline' => (string) ($group->tagline ?? ''),
                    'fields' => $group === null ? [] : $this->groupFields($group->id, 'group'),
                    'products' => [$row],
                ]],
            ]],
        ];
    }

    /**
     * Sample hostname generated from the product's `host` rule, shown as a
     * preview on the configuration form.
     */
    protected function firstHostRule(Product $product): string
    {
        $raw = (string) $product->host;

        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $prefix = (string) ($decoded['prefix'] ?? $decoded['host'] ?? '');

            return $prefix;
        }

        return $raw;
    }

    /**
     * GET /v1/products/{id} — product detail.
     *
     * The live platform routes this path to the service detail controller, so
     * the richer catalogue shape is kept here for existing downstreams that
     * rely on it.
     */
    public function productDetail(int $id)
    {
        $product = Product::query()->find($id);

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        $currencyId = $this->pricing->currencyId();

        return $this->ok([
            'product' => $this->presenter->detail($product, $currencyId),
            'pricing' => $this->pricingPayload($product, $currencyId),
            'configoptions' => $this->presenter->configOptions($product, $currencyId),
            'customfields' => $this->presenter->customFields($product),
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
     * Pricing rows keyed by cycle, used by the legacy detail payload.
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
}
