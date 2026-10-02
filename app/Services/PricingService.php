<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductConfigGroup;
use App\Models\ProductConfigOption;
use App\Models\ProductConfigOptionSub;
use App\Models\PromoCode;
use App\Models\ShdModel;

/**
 * Pricing rules shared by the storefront, the cart and the public API.
 *
 * All monetary values are handled as floats rounded to two decimals, matching
 * the `decimal(10,2)` columns of the mirrored schema.
 */
class PricingService
{
    /**
     * The pricing row for a product in a given currency.
     */
    public function productPricing(Product $product, ?int $currencyId = null): ?Pricing
    {
        $currencyId = $currencyId ?? $this->currencyId();

        return Pricing::query()
            ->where('type', 'product')
            ->where('relid', $product->id)
            ->when($currencyId, fn ($q) => $q->where('currency', $currencyId))
            ->first();
    }

    /**
     * Pricing rows for a config option choice.
     */
    public function optionPricing(ProductConfigOptionSub $sub, ?int $currencyId = null): ?Pricing
    {
        $currencyId = $currencyId ?? $this->currencyId();

        return Pricing::query()
            ->where('type', 'configoptions')
            ->where('relid', $sub->id)
            ->when($currencyId, fn ($q) => $q->where('currency', $currencyId))
            ->first();
    }

    /**
     * Base price of a product for one billing cycle.
     */
    public function cyclePrice(Product $product, string $cycle, ?int $currencyId = null): ?float
    {
        $pricing = $this->productPricing($product, $currencyId);

        return $pricing?->priceFor($cycle);
    }

    /**
     * Setup fee of a product for one billing cycle.
     */
    public function setupFee(Product $product, string $cycle, ?int $currencyId = null): float
    {
        $pricing = $this->productPricing($product, $currencyId);

        return $pricing?->setupFeeFor($cycle) ?? 0.0;
    }

    /**
     * Billing cycles a product offers, in schema order.
     */
    public function availableCycles(Product $product, ?int $currencyId = null): array
    {
        $pricing = $this->productPricing($product, $currencyId);

        return $pricing?->availableCycles() ?? [];
    }

    /**
     * Number of days a billing cycle covers; null for onetime/ontrial.
     */
    public function cycleDays(string $cycle): ?int
    {
        return match ($cycle) {
            'hour' => 0,
            'day' => 1,
            'monthly' => 30,
            'quarterly' => 90,
            'semiannually' => 180,
            'annually' => 365,
            'biennially' => 730,
            'triennially' => 1095,
            'fourly' => 1460,
            'fively' => 1825,
            'sixly' => 2190,
            'sevenly' => 2555,
            'eightly' => 2920,
            'ninely' => 3285,
            'tenly' => 3650,
            default => null,
        };
    }

    /**
     * Next due date for a cycle starting from a given timestamp.
     */
    public function nextDueDate(string $cycle, ?int $from = null): ?int
    {
        $from = $from ?? time();
        $days = $this->cycleDays($cycle);

        if ($days === null) {
            return null;
        }

        if ($days === 0) {
            $hours = (int) ($this->hourCycleHours());

            return $from + ($hours * 3600);
        }

        return strtotime("+{$days} days", $from) ?: null;
    }

    /**
     * Hourly billing cycle length, configured per product group in the
     * original platform (`pay_hour_cycle`, default 720 hours).
     */
    public function hourCycleHours(): int
    {
        return 720;
    }

    /**
     * Price for a configurable option selection.
     *
     * @param  array<int, array{option:int, value:mixed, qty?:int}>  $selections
     */
    public function configOptionsTotal(Product $product, array $selections, string $cycle, ?int $currencyId = null): float
    {
        $currencyId = $currencyId ?? $this->currencyId();
        $total = 0.0;

        foreach ($selections as $selection) {
            $option = ProductConfigOption::query()->find($selection['option'] ?? null);

            if ($option === null) {
                continue;
            }

            foreach ((array) ($selection['value'] ?? []) as $subId) {
                if ($option->option_type === ProductConfigOption::TYPE_QUANTITY) {
                    $subId = $selection['value'];
                }

                $sub = ProductConfigOptionSub::query()
                    ->where('id', $subId)
                    ->where('config_id', $option->id)
                    ->first();

                if ($sub === null) {
                    continue;
                }

                $pricing = $this->optionPricing($sub, $currencyId);

                if ($pricing === null) {
                    continue;
                }

                $unit = $pricing->priceFor($cycle) ?? 0.0;
                $qty = $option->option_type === ProductConfigOption::TYPE_QUANTITY
                    ? max(1, (int) ($selection['qty'] ?? 1))
                    : 1;

                $total += $unit * $qty;
            }
        }

        return round($total, 2);
    }

    /**
     * Apply a promotional code to a subtotal.
     */
    public function applyPromo(float $subtotal, PromoCode $promo): float
    {
        return min($subtotal, $promo->discountFor($subtotal));
    }

    /**
     * Account for client-group discounts, as the original does at checkout.
     */
    public function groupDiscount(float $amount, ?int $discountPercent): float
    {
        if ($discountPercent === null || $discountPercent <= 0) {
            return $amount;
        }

        return round($amount * (1 - ($discountPercent / 100)), 2);
    }

    public function currencyId(): ?int
    {
        return Currency::default()?->id;
    }

    public function currency(): ?Currency
    {
        return Currency::default();
    }

    /**
     * Configurable option groups attached to a product, with their options.
     */
    public function productConfigGroups(Product $product): array
    {
        $groupIds = $product->configLinks()->pluck('gid')->all();

        if ($groupIds === []) {
            return [];
        }

        return ProductConfigGroup::query()
            ->whereIn('id', $groupIds)
            ->with(['options' => fn ($q) => $q->where('hidden', 0), 'options.subOptions' => fn ($q) => $q->where('hidden', 0)])
            ->get()
            ->all();
    }

    /**
     * Round to the schema's two-decimal money precision.
     */
    public static function money(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * Unused helper kept for symmetry with the admin panel's JSON columns.
     */
    public static function decodeJson(?string $value, mixed $default = []): mixed
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function model(): ShdModel
    {
        return new Pricing();
    }
}
