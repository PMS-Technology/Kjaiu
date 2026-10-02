<?php

namespace App\Support;

use App\Models\CartSession;
use App\Models\Client;
use Illuminate\Support\Facades\Session;

/**
 * Client-area cart access.
 *
 * `App\Services\CartService` is written for the API, where every caller is
 * authenticated and the cart is always keyed by client id. The storefront also
 * serves guests, so this adapter resolves the cart row itself: signed-in
 * clients are keyed by uid, visitors by session id alone.
 *
 * `cart_data` always holds a bare JSON list of line items — the shape
 * `OrderService` and `CartService` read — while the applied promo code and the
 * order notes live in the session. That keeps a guest cart handable to
 * `OrderService::place()` unchanged once the visitor registers.
 */
class CartStore
{
    protected const PROMO_KEY = 'cart.promo';

    protected const NOTES_KEY = 'cart.notes';

    /**
     * The active cart row for the current visitor, created when absent.
     */
    public function resolve(?Client $client): CartSession
    {
        $sessionId = (string) Session::getId();

        if ($client !== null) {
            $cart = CartSession::query()
                ->where('uid', (string) $client->id)
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

        $cart = CartSession::query()
            ->where('sessionid', $sessionId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('uid')->orWhere('uid', '');
            })
            ->first();

        if ($cart !== null) {
            return $cart;
        }

        return CartSession::create([
            'uid' => null,
            'sessionid' => $sessionId,
            'cart_data' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'create_time' => time(),
            'expire_time' => time() + 86400,
            'update_time' => time(),
        ]);
    }

    /**
     * Hand a guest cart to a client, so an inline registration during checkout
     * keeps the basket. Any earlier cart of theirs is retired first.
     */
    public function claim(CartSession $cart, Client $client): CartSession
    {
        $existing = CartSession::query()
            ->where('uid', (string) $client->id)
            ->where('status', 'active')
            ->where('id', '!=', $cart->id)
            ->first();

        if ($existing !== null) {
            $existing->status = 'expired';
            $existing->save();
        }

        $cart->uid = (string) $client->id;
        $cart->sessionid = (string) Session::getId();
        $cart->update_time = time();
        $cart->save();

        return $cart;
    }

    /**
     * Line items stored on the cart row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(CartSession $cart): array
    {
        $decoded = json_decode((string) $cart->cart_data, true);

        if (! is_array($decoded)) {
            return [];
        }

        // Tolerate rows written by an older envelope-shaped writer.
        if (! array_is_list($decoded)) {
            $decoded = (array) ($decoded['items'] ?? []);
        }

        return array_values($decoded);
    }

    public function promoCode(): string
    {
        return (string) Session::get(self::PROMO_KEY, '');
    }

    public function setPromoCode(string $code): void
    {
        if ($code === '') {
            Session::forget(self::PROMO_KEY);

            return;
        }

        Session::put(self::PROMO_KEY, $code);
    }

    public function notes(CartSession $cart): string
    {
        return (string) Session::get(self::NOTES_KEY, '');
    }

    public function setNotes(string $notes): void
    {
        Session::put(self::NOTES_KEY, mb_substr($notes, 0, 200));
    }

    /**
     * Persist line items back onto the cart row.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function save(CartSession $cart, array $items): CartSession
    {
        $cart->cart_data = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
        $cart->update_time = time();
        $cart->save();

        return $cart;
    }

    /**
     * Append a configured product and return its 1-based position.
     */
    public function push(CartSession $cart, array $item): int
    {
        $items = $this->items($cart);
        $items[] = $item;
        $this->save($cart, $items);

        return count($items);
    }

    /**
     * Replace one line item by its 1-based position.
     */
    public function replace(CartSession $cart, int $position, array $item): bool
    {
        $items = $this->items($cart);
        $index = $position - 1;

        if (! isset($items[$index])) {
            return false;
        }

        $items[$index] = $item;
        $this->save($cart, $items);

        return true;
    }

    /**
     * Update the quantity of one line item, recalculating its amount.
     */
    public function setQty(CartSession $cart, int $position, int $qty): bool
    {
        $items = $this->items($cart);
        $index = $position - 1;

        if (! isset($items[$index])) {
            return false;
        }

        $qty = max(1, $qty);
        $unit = (float) ($items[$index]['unit_amount'] ?? 0) + (float) ($items[$index]['configoptions_amount'] ?? 0);

        $items[$index]['qty'] = $qty;
        $items[$index]['amount'] = round($unit * $qty + (float) ($items[$index]['setup_fee'] ?? 0), 2);
        $this->save($cart, $items);

        return true;
    }

    /**
     * Remove the given 1-based positions.
     *
     * @param  array<int, int>  $positions
     */
    public function remove(CartSession $cart, array $positions): void
    {
        $items = $this->items($cart);
        $drop = array_map(fn ($position) => (int) $position - 1, $positions);

        foreach (array_keys($items) as $index) {
            if (in_array($index, $drop, true)) {
                unset($items[$index]);
            }
        }

        $this->save($cart, array_values($items));
    }

    public function clear(CartSession $cart): void
    {
        $this->save($cart, []);
        $this->setPromoCode('');
    }

    public function count(CartSession $cart): int
    {
        return count($this->items($cart));
    }

    /**
     * Numeric subtotal before promotions.
     */
    public function subtotal(CartSession $cart): float
    {
        $total = 0.0;

        foreach ($this->items($cart) as $item) {
            $total += (float) ($item['amount'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Totals for the cart view and the checkout, including the applied promo.
     */
    public function totals(CartSession $cart): array
    {
        $pricing = new \App\Services\PricingService();
        $subtotal = $this->subtotal($cart);
        $promo = null;

        if ($this->promoCode() !== '') {
            $promo = (new \App\Services\CartService())->findPromo($this->promoCode());

            if ($promo === null) {
                $this->setPromoCode('');
            }
        }

        $discount = $promo !== null ? $pricing->applyPromo($subtotal, $promo) : 0.0;
        $total = $pricing->money(max(0, $subtotal - $discount));

        return [
            'subtotal' => $pricing->money($subtotal),
            'discount' => $pricing->money($discount),
            'total' => $total,
            'promo' => $promo,
        ];
    }
}
