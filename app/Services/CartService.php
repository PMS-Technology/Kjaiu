<?php

namespace App\Services;

use App\Models\CartSession;
use App\Models\Client;
use App\Models\Product;
use App\Models\PromoCode;

/**
 * Shopping cart.
 *
 * The original platform keeps the cart in `shd_cart_session` keyed by session
 * id (guests) or client id (signed-in users), with the line items serialised
 * into `cart_data`. The same storage model is used here so the API payloads
 * stay compatible.
 */
class CartService
{
    public function __construct(
        protected PricingService $pricing = new PricingService(),
    ) {
    }

    /**
     * Load the active cart row, creating one when absent.
     */
    public function current(Client $client): CartSession
    {
        $sessionId = session()->getId();

        $cart = CartSession::query()
            ->where('uid', (string) $client->id)
            ->where('sessionid', $sessionId)
            ->where('status', 'active')
            ->first();

        if ($cart !== null) {
            return $cart;
        }

        return CartSession::create([
            'uid' => (string) $client->id,
            'sessionid' => $sessionId,
            'cart_data' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'create_time' => time(),
            'expire_time' => time() + 86400,
            'update_time' => time(),
        ]);
    }

    /**
     * Decoded cart line items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(CartSession $cart): array
    {
        $decoded = json_decode((string) $cart->cart_data, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Persist line items back onto the cart row.
     */
    public function store(CartSession $cart, array $items): CartSession
    {
        $cart->cart_data = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
        $cart->update_time = time();
        $cart->save();

        return $cart;
    }

    /**
     * Append a configured product to the cart.
     *
     * @param  array{cycle:string, configoptions?:array, qty?:int, host?:string, password?:string, custom?:array}  $configuration
     */
    public function addProduct(CartSession $cart, Product $product, array $configuration): array
    {
        $cycle = (string) ($configuration['cycle'] ?? 'monthly');
        $qty = max(1, (int) ($configuration['qty'] ?? 1));

        $unit = $this->pricing->cyclePrice($product, $cycle);

        if ($unit === null) {
            throw new \InvalidArgumentException('该商品不支持所选计费周期');
        }

        $options = (array) ($configuration['configoptions'] ?? []);
        $optionsTotal = $this->pricing->configOptionsTotal($product, $options, $cycle);
        $setupFee = $this->pricing->setupFee($product, $cycle);

        $items = $this->items($cart);

        $items[] = [
            'productid' => $product->id,
            'name' => $product->name,
            'gid' => $product->gid,
            'type' => $product->type,
            'cycle' => $cycle,
            'qty' => $qty,
            'unit_amount' => $unit,
            'configoptions' => $options,
            'configoptions_amount' => $optionsTotal,
            'setup_fee' => $setupFee,
            'host' => $configuration['host'] ?? '',
            'password' => $configuration['password'] ?? '',
            'custom' => $configuration['custom'] ?? [],
            'amount' => PricingService::money(($unit + $optionsTotal) * $qty + $setupFee),
            'add_time' => time(),
        ];

        $this->store($cart, $items);

        return $items;
    }

    /**
     * Remove a line item by its position (1-based, as the API addresses them).
     */
    public function removePosition(CartSession $cart, int $position): array
    {
        $items = $this->items($cart);
        $index = $position - 1;

        if (isset($items[$index])) {
            unset($items[$index]);
        }

        return $this->store($cart, array_values($items))->fresh() ? $this->items($cart->fresh()) : [];
    }

    /**
     * Update the quantity of a line item.
     */
    public function modifyQty(CartSession $cart, int $position, int $qty): array
    {
        $items = $this->items($cart);
        $index = $position - 1;

        if (isset($items[$index])) {
            $qty = max(1, $qty);
            $item = $items[$index];
            // Config option charges are per-unit; the setup fee is charged once.
            $unitTotal = ((float) $item['unit_amount'] + (float) ($item['configoptions_amount'] ?? 0)) * $qty;
            $items[$index]['qty'] = $qty;
            $items[$index]['amount'] = PricingService::money($unitTotal + (float) ($item['setup_fee'] ?? 0));
        }

        $this->store($cart, $items);

        return $this->items($cart->fresh());
    }

    public function clear(CartSession $cart): void
    {
        $this->store($cart, []);
    }

    /**
     * Cart subtotal before promotions and tax.
     */
    public function subtotal(CartSession $cart): float
    {
        $total = 0.0;

        foreach ($this->items($cart) as $item) {
            $total += (float) ($item['amount'] ?? 0);
        }

        return PricingService::money($total);
    }

    /**
     * Attach a promotional code to the cart.
     */
    public function applyPromo(CartSession $cart, PromoCode $promo): float
    {
        $discount = $this->pricing->applyPromo($this->subtotal($cart), $promo);

        $cart->cart_data = json_encode($this->items($cart), JSON_UNESCAPED_UNICODE);
        $cart->status = 'active';
        $cart->save();

        return PricingService::money($discount);
    }

    /**
     * Resolve a promo code string to a usable code row, or null.
     */
    public function findPromo(string $code): ?PromoCode
    {
        $promo = PromoCode::query()->where('code', $code)->first();

        if ($promo === null || ! $promo->isUsable()) {
            return null;
        }

        return $promo;
    }

    /**
     * Totals payload shared by the cart page, the checkout and the API.
     */
    public function totals(CartSession $cart, ?PromoCode $promo = null, float $taxRate = 0.0): array
    {
        $subtotal = $this->subtotal($cart);
        $discount = $promo !== null ? $this->pricing->applyPromo($subtotal, $promo) : 0.0;
        $net = PricingService::money(max(0, $subtotal - $discount));
        $tax = round($net * ($taxRate / 100), 2);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => PricingService::money($net + $tax),
        ];
    }
}
