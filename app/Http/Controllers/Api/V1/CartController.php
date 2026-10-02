<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Http\Request;

/**
 * Cart endpoints.
 *
 * Line items are addressed by their 1-based `{position}` in the cart, exactly
 * as the documented API does.
 */
class CartController extends ApiController
{
    public function __construct(
        protected CartService $cart = new CartService(),
        protected OrderService $orders = new OrderService(),
    ) {
    }

    /**
     * GET /v1/cart — current cart contents and totals.
     */
    public function index(Request $request)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);
        $items = $this->cart->items($cart);

        return $this->ok([
            'list' => $this->itemsPayload($items),
            'count' => count($items),
            'total' => $this->totalsPayload($cart),
        ]);
    }

    /**
     * POST /v1/cart/products — add a configured product.
     */
    public function addProducts(Request $request)
    {
        $client = $this->requireClient($request);

        $product = Product::query()->find((int) $request->input('pid', $request->input('productid', 0)));

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        if (! $product->isPurchasable()) {
            return $this->fail('商品已下架或库存不足');
        }

        $cart = $this->cart->current($client);

        try {
            $items = $this->cart->addProduct($cart, $product, [
                'cycle' => (string) $request->input('cycle', 'monthly'),
                'qty' => (int) $request->input('qty', 1),
                'configoptions' => (array) $request->input('configoptions', []),
                'host' => (string) $request->input('host', $request->input('domain', '')),
                'password' => (string) $request->input('password', ''),
                'custom' => (array) $request->input('custom', []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok([
            'list' => $this->itemsPayload($items),
            'total' => $this->totalsPayload($cart->fresh()),
        ], '添加成功');
    }

    /**
     * DELETE /v1/cart/products/{position}
     */
    public function remove(Request $request, int $position)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);

        $this->cart->removePosition($cart, $position);

        return $this->ok([
            'list' => $this->itemsPayload($this->cart->items($cart->fresh())),
            'total' => $this->totalsPayload($cart->fresh()),
        ], '删除成功');
    }

    /**
     * GET /v1/cart/products/{position} — configuration of one line item.
     */
    public function editPage(Request $request, int $position)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);
        $items = $this->cart->items($cart);
        $item = $items[$position - 1] ?? null;

        if ($item === null) {
            return $this->fail('购物车商品不存在');
        }

        $product = Product::query()->find((int) $item['productid']);

        return $this->ok([
            'position' => $position,
            'item' => $this->itemPayload($item, $position),
            'pricing' => $product === null ? [] : [
                'cycle' => $item['cycle'],
                'unit_amount' => $this->money((float) $item['unit_amount']),
            ],
        ]);
    }

    /**
     * PUT /v1/cart/products/{position} — reconfigure a line item.
     */
    public function edit(Request $request, int $position)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);
        $items = $this->cart->items($cart);

        if (! isset($items[$position - 1])) {
            return $this->fail('购物车商品不存在');
        }

        $item = $items[$position - 1];
        $product = Product::query()->find((int) $item['productid']);

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        $cycle = (string) $request->input('cycle', $item['cycle']);
        $unit = $this->cartSvc()->cyclePrice($product, $cycle);

        if ($unit === null) {
            return $this->fail('该商品不支持所选计费周期');
        }

        $items[$position - 1]['cycle'] = $cycle;
        $items[$position - 1]['unit_amount'] = $unit;
        $items[$position - 1]['host'] = (string) $request->input('host', $item['host'] ?? '');
        $items[$position - 1]['configoptions'] = (array) $request->input('configoptions', $item['configoptions'] ?? []);
        $items[$position - 1]['configoptions_amount'] = $this->cartSvc()->configOptionsTotal(
            $product,
            $items[$position - 1]['configoptions'],
            $cycle
        );

        $qty = max(1, (int) ($item['qty'] ?? 1));
        $items[$position - 1]['amount'] = \App\Services\PricingService::money(
            ((float) $unit + (float) $items[$position - 1]['configoptions_amount']) * $qty
            + (float) ($item['setup_fee'] ?? 0)
        );

        $this->cart->store($cart, $items);

        return $this->ok([
            'list' => $this->itemsPayload($this->cart->items($cart->fresh())),
            'total' => $this->totalsPayload($cart->fresh()),
        ], '修改成功');
    }

    /**
     * PUT /v1/cart/products/{position}/qty
     */
    public function modifyQty(Request $request, int $position)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);

        $items = $this->cart->modifyQty($cart, $position, (int) $request->input('qty', 1));

        return $this->ok([
            'list' => $this->itemsPayload($items),
            'total' => $this->totalsPayload($cart->fresh()),
        ], '修改成功');
    }

    /**
     * POST /v1/cart/promo — attach a promotional code.
     */
    public function addPromo(Request $request)
    {
        $client = $this->requireClient($request);
        $code = trim((string) $request->input('promo', $request->input('code', '')));

        if ($code === '') {
            return $this->fail('优惠码不能为空');
        }

        $promo = $this->cart->findPromo($code);

        if ($promo === null) {
            return $this->fail('优惠码无效或已过期');
        }

        $cart = $this->cart->current($client);
        $discount = $this->cart->applyPromo($cart, $promo);

        return $this->ok([
            'promo' => $promo->code,
            'discount' => $this->money($discount),
            'total' => $this->totalsPayload($cart->fresh()),
        ], '优惠码已应用');
    }

    /**
     * DELETE /v1/cart/promo
     */
    public function removePromo(Request $request)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);

        return $this->ok([
            'total' => $this->totalsPayload($cart->fresh()),
        ], '优惠码已移除');
    }

    /**
     * DELETE /v1/cart/clear
     */
    public function clear(Request $request)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);
        $this->cart->clear($cart);

        return $this->ok(null, '购物车已清空');
    }

    /**
     * POST /v1/cart/checkout — place the order and return the payable invoice.
     */
    public function checkout(Request $request)
    {
        $client = $this->requireClient($request);
        $cart = $this->cart->current($client);

        if ($this->cart->items($cart) === []) {
            return $this->fail('购物车为空');
        }

        try {
            $result = $this->orders->place($client, $cart, [
                'promo' => (string) $request->input('promo', ''),
                'notes' => (string) $request->input('notes', ''),
                'gateway' => (string) $request->input('gateway', ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $invoice = $result['invoice'];
        $order = $result['order'];

        return $this->ok([
            'order_id' => $order->id,
            'order_num' => $order->ordernum,
            'invoice_id' => $invoice->id,
            'invoice_num' => $invoice->invoiceNumber(),
            'amount' => $this->money((float) $invoice->total),
            'gateway' => $this->gateways(),
        ], '下单成功');
    }

    /**
     * Payload for a list of cart items.
     */
    protected function itemsPayload(array $items): array
    {
        $out = [];

        foreach (array_values($items) as $index => $item) {
            $out[] = $this->itemPayload($item, $index + 1);
        }

        return $out;
    }

    protected function itemPayload(array $item, int $position): array
    {
        return [
            'position' => $position,
            'productid' => (int) ($item['productid'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'cycle' => (string) ($item['cycle'] ?? ''),
            'qty' => (int) ($item['qty'] ?? 1),
            'unit_amount' => $this->money((float) ($item['unit_amount'] ?? 0)),
            'configoptions_amount' => $this->money((float) ($item['configoptions_amount'] ?? 0)),
            'setup_fee' => $this->money((float) ($item['setup_fee'] ?? 0)),
            'amount' => $this->money((float) ($item['amount'] ?? 0)),
            'host' => (string) ($item['host'] ?? ''),
            'configoptions' => $item['configoptions'] ?? [],
        ];
    }

    protected function totalsPayload($cart): array
    {
        $totals = $this->cart->totals($cart);

        return [
            'subtotal' => $this->money($totals['subtotal']),
            'discount' => $this->money($totals['discount']),
            'tax' => $this->money($totals['tax']),
            'total' => $this->money($totals['total']),
        ];
    }

    /**
     * Enabled gateways as {name, title} pairs.
     */
    protected function gateways(): array
    {
        return \App\Models\PaymentGateway::query()
            ->orderBy('order')
            ->get()
            ->map(fn ($g) => ['name' => (string) $g->gateway, 'title' => $g->displayName()])
            ->values()
            ->all();
    }

    protected function cartSvc(): \App\Services\PricingService
    {
        return new \App\Services\PricingService();
    }
}
