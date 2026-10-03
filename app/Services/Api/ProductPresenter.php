<?php

namespace App\Services\Api;

use App\Models\CustomField;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductConfigGroup;
use App\Models\ProductConfigOptionSub;
use App\Services\HostService;
use App\Services\PricingService;

/**
 * Shapes product payloads for the public `/v1` catalogue endpoints.
 *
 * The live ZJMF 3.7.6 platform flattens the price onto the product row
 * (`product_price` / `setup_fee` / `billingcycle`) instead of nesting a
 * `pricing` map, and adds an `ontrial` object. Downstream integrations read
 * those exact keys, so they are built here rather than in the controller.
 */
class ProductPresenter
{
    public function __construct(
        protected PricingService $pricing = new PricingService(),
    ) {
    }

    /**
     * List-level product row, matching the live `/v1/products` payload.
     *
     * @return array<string, mixed>
     */
    public function summary(Product $product, ?int $currencyId): array
    {
        $row = [
            'id' => (int) $product->id,
            'type' => (string) $product->type,
            'name' => (string) $product->name,
            'description' => (string) $product->description,
            'stock_control' => (int) $product->stock_control,
            'qty' => (int) $product->qty,
            'ontrial' => $this->ontrialPayload($product),
        ];

        $row += $this->priceFields($product, $currencyId);

        return $row;
    }

    /**
     * Detail-level product row, used by `/v1/productsconfig`.
     *
     * @return array<string, mixed>
     */
    public function detail(Product $product, ?int $currencyId): array
    {
        $row = [
            'id' => (int) $product->id,
            'gid' => (int) $product->gid,
            'type' => (string) $product->type,
            'name' => (string) $product->name,
            'description' => (string) $product->description,
            'stock_control' => (int) $product->stock_control,
            'qty' => (int) $product->qty,
            'allow_qty' => (int) $product->allow_qty,
            'pay_type' => $product->payType(),
            'is_featured' => (int) $product->is_featured,
            'ontrial' => $this->ontrialPayload($product),
            'product_shopping_url' => (string) $product->product_shopping_url,
        ];

        $row += $this->priceFields($product, $currencyId);

        return $row;
    }

    /**
     * The flattened price triple the live platform puts on every product row.
     *
     * `billingcycle` is the first cycle, in schema order, whose price is not
     * -1.00; `product_price` and `setup_fee` are formatted as strings.
     *
     * @return array{billingcycle:string, product_price:string, setup_fee:string}
     */
    public function priceFields(Product $product, ?int $currencyId): array
    {
        $pricing = $this->pricing->productPricing($product, $currencyId);
        $cycle = $this->defaultCycle($product, $pricing);

        return [
            'billingcycle' => $cycle,
            'product_price' => $this->formatAmount($pricing?->priceFor($cycle) ?? 0.0),
            'setup_fee' => $this->formatAmount($pricing?->setupFeeFor($cycle) ?? 0.0),
        ];
    }

    /**
     * Trial settings. The live platform always emits the `ontrial` key and
     * returns the sibling fields only when trials are switched on.
     *
     * @return array<string, mixed>
     */
    public function ontrialPayload(Product $product): array
    {
        $payType = $product->payType();
        $enabled = (int) ($payType['pay_ontrial_status'] ?? 0) === 1;

        if (! $enabled) {
            return ['ontrial' => 0];
        }

        $cycleType = (string) ($payType['pay_ontrial_cycle_type'] ?? 'day');
        $cycleLabel = $cycleType === 'hour' ? 'hour' : 'day';
        $pricing = $this->pricing->productPricing($product, null);

        return [
            'ontrial' => 1,
            'ontrial_cycle' => (int) ($payType['pay_ontrial_cycle'] ?? 0),
            'ontrial_cycle_type' => $cycleType,
            'ontrial_price' => $this->formatAmount($pricing?->priceFor('ontrial') ?? 0.0),
            'ontrial_setup_fee' => $this->formatAmount($pricing?->setupFeeFor('ontrial') ?? 0.0),
            'ontrial_cycle_zh' => in_array($cycleLabel, ['hour', 'day'], true)
                ? HostService::cycleLabel($cycleLabel)
                : '',
        ];
    }

    /**
     * Cycle used for the flattened price: first offered cycle in schema order,
     * falling back to the group's recurring list then to monthly.
     */
    protected function defaultCycle(Product $product, ?Pricing $pricing): string
    {
        $available = $pricing?->availableCycles() ?? [];

        if ($available !== []) {
            return $available[0];
        }

        $payType = $product->payType();
        $recurring = (array) ($payType['pay_type_recurring'] ?? []);

        foreach ($recurring as $cycle) {
            if (in_array((string) $cycle, Pricing::CYCLES, true)) {
                return (string) $cycle;
            }
        }

        return 'monthly';
    }

    /**
     * Per-cycle price rows used by the detail endpoint and the cart.
     *
     * @return array<int, array<string, string>>
     */
    public function cycleRows(Product $product, ?int $currencyId): array
    {
        $pricing = $this->pricing->productPricing($product, $currencyId);

        if ($pricing === null) {
            return [];
        }

        $rows = [];

        foreach ($pricing->availableCycles() as $cycle) {
            $rows[] = [
                'product_price' => $this->formatAmount($pricing->priceFor($cycle) ?? 0.0),
                'setup_fee' => $this->formatAmount($pricing->setupFeeFor($cycle)),
                'billingcycle' => $cycle,
                'billingcycle_zh' => HostService::cycleLabel($cycle),
            ];
        }

        return $rows;
    }

    /**
     * Configurable option groups for a product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function configOptions(Product $product, ?int $currencyId): array
    {
        $groups = $this->pricing->productConfigGroups($product);

        return array_map(function (ProductConfigGroup $group) use ($currencyId) {
            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'description' => (string) $group->description,
                'option' => $group->options->map(function ($option) use ($currencyId) {
                    return [
                        'id' => (int) $option->id,
                        'option_name' => (string) $option->option_name,
                        'option_type' => (int) $option->option_type,
                        'qty_minimum' => (int) $option->qty_minimum,
                        'qty_maximum' => (int) $option->qty_maximum,
                        'notes' => (string) $option->notes,
                        'sub' => $option->subOptions->map(function (ProductConfigOptionSub $sub) use ($currencyId) {
                            return [
                                'id' => (int) $sub->id,
                                'option_name' => (string) $sub->option_name,
                                'qty_minimum' => (int) $sub->qty_minimum,
                                'qty_maximum' => (int) $sub->qty_maximum,
                                'pricing' => $this->subPricing($sub, $currencyId),
                            ];
                        })->values()->all(),
                    ];
                })->values()->all(),
            ];
        }, $groups);
    }

    /**
     * Custom fields attached to a product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function customFields(Product $product): array
    {
        return CustomField::query()
            ->where('type', 'product')
            ->where('relid', $product->id)
            ->orderBy('sortorder')
            ->get()
            ->map(fn (CustomField $field) => [
                'id' => (int) $field->id,
                'fieldname' => (string) $field->fieldname,
                'fieldtype' => (string) $field->fieldtype,
                'description' => (string) $field->description,
                'fieldoptions' => (string) ($field->fieldoptions ?? ''),
                'regexpr' => (string) ($field->regexpr ?? ''),
                'required' => (int) $field->required,
            ])
            ->values()
            ->all();
    }

    /**
     * Free-form `name`/`value` rows attached to a first-level group or a group.
     *
     * @return array<int, array{id:int, name:string, value:string}>
     */
    public static function namedFields(?object $rows): array
    {
        if ($rows === null) {
            return [];
        }

        return collect($rows)
            ->map(fn ($row) => [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'value' => (string) ($row->value ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Price map for one config option choice, keyed by cycle.
     *
     * @return array<string, string>
     */
    protected function subPricing(ProductConfigOptionSub $sub, ?int $currencyId): array
    {
        $pricing = $this->pricing->optionPricing($sub, $currencyId);

        if ($pricing === null) {
            return [];
        }

        $prices = [];

        foreach ($pricing->availableCycles() as $cycle) {
            $prices[$cycle] = $this->formatAmount($pricing->priceFor($cycle) ?? 0.0);
        }

        return $prices;
    }

    /**
     * The live platform serialises money as a two-decimal string.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format(PricingService::money($amount), 2, '.', '');
    }
}
