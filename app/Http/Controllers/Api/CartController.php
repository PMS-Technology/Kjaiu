<?php

namespace App\Http\Controllers\Api;

use App\Models\CartItem;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\SupplierProductMapping;
use App\Services\BillingService;
use App\Services\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CartController extends ApiController
{
    public function total(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer'],
            'billingcycle' => ['required', 'string', 'max:32'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $product = Product::query()->where('is_active', true)->with('prices')->find($request->integer('product_id'));
        $price = $product?->priceFor($request->string('billingcycle')->toString());
        if (! $product || ! $price || ! $price->is_active) {
            return $this->error('商品或付款周期不存在');
        }

        $quantity = max(1, $request->integer('qty', 1));
        $unitMinor = Money::toMinor($price->price);
        $setupMinor = Money::toMinor($price->setup_fee);

        return $this->success([
            'product_price' => Money::format($unitMinor * $quantity),
            'setup_fee' => Money::format($setupMinor * $quantity),
            'total' => Money::format(($unitMinor + $setupMinor) * $quantity),
            'sale_total' => Money::format(($unitMinor + $setupMinor) * $quantity),
            'total_price' => Money::format(($unitMinor + $setupMinor) * $quantity),
            'billingcycle' => $price->billing_cycle,
            'qty' => $quantity,
            'currency' => config('kjaiu.currency'),
        ], 'Success message');
    }

    public function show(Request $request): JsonResponse
    {
        $items = CartItem::query()
            ->where('user_id', $request->user()->id)
            ->with(['product.prices'])
            ->orderBy('id')
            ->get();

        $totalMinor = 0;
        $products = $items->map(function (CartItem $item, int $position) use (&$totalMinor) {
            $price = $item->product->priceFor($item->billing_cycle);
            $unitMinor = $price ? Money::toMinor($price->price) : 0;
            $setupMinor = $price ? Money::toMinor($price->setup_fee) : 0;
            $lineMinor = ($unitMinor + $setupMinor) * $item->quantity;
            $totalMinor += $lineMinor;

            return [
                'position' => $position,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'description' => $item->product->description ?? '',
                'billingcycle' => $item->billing_cycle,
                'qty' => $item->quantity,
                'product_price' => Money::format($unitMinor),
                'setup_fee' => Money::format($setupMinor),
                'price' => Money::format($lineMinor),
                'configoptions' => $item->configuration ?? [],
            ];
        })->values();

        $gateways = PaymentGateway::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PaymentGateway $gateway) => $this->gateway($gateway));

        return $this->success([
            'currency' => config('kjaiu.currency'),
            'cart_products' => $products,
            'total_price' => Money::format($totalMinor),
            'gateway_list' => $gateways,
            'default_gateway' => $gateways->first()['name'] ?? '',
            'client' => [
                'id' => $request->user()->id,
                'credit' => $request->user()->credit,
            ],
        ], 'Success message');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer'],
            'billingcycle' => ['nullable', 'string', 'max:32'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:100'],
            'configoption' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $product = Product::query()->where('is_active', true)->with('prices')->find($request->integer('product_id'));
        $cycle = $request->string('billingcycle', $product?->billing_cycle ?? '')->toString();
        if (! $product || ! $product->priceFor($cycle)) {
            return $this->error('商品或付款周期不存在');
        }

        $quantity = $request->integer('qty', 1);
        if ($product->stock_control && ($product->quantity === null || $product->quantity < $quantity)) {
            return $this->error('商品库存不足');
        }

        $configuration = is_array($request->input('configoption'))
            ? $request->input('configoption')
            : [];
        if ($configuration !== [] && SupplierProductMapping::query()
            ->where('product_id', $product->id)
            ->where('local_billing_cycle', $cycle)
            ->where('is_active', true)
            ->whereHas('account', fn ($query) => $query->where('is_active', true))
            ->whereHas('catalogProduct', fn ($query) => $query->where('is_active', true))
            ->exists()) {
            return $this->error('当前上游映射商品暂不支持客户自定义配置');
        }

        $item = CartItem::create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'billing_cycle' => $cycle,
            'quantity' => $quantity,
            'configuration' => $configuration,
        ]);

        $position = CartItem::query()
            ->where('user_id', $request->user()->id)
            ->where('id', '<=', $item->id)
            ->count() - 1;

        return $this->success(['position' => $position], '添加购物车成功');
    }

    public function destroy(Request $request, int $position): JsonResponse
    {
        $cartItem = CartItem::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('id')
            ->skip($position)
            ->first();
        if (! $cartItem) {
            return $this->error('购物车商品不存在', 404);
        }

        $cartItem->delete();

        return $this->success([], '删除成功');
    }

    public function clear(Request $request): JsonResponse
    {
        CartItem::query()->where('user_id', $request->user()->id)->delete();

        return $this->success([], '清空成功');
    }

    public function checkout(Request $request, BillingService $billing): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'position' => ['nullable', 'array', 'min:1'],
            'position.*' => ['required', 'integer', 'min:0', 'distinct'],
            'payment' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $gateway = $request->string('payment')->toString();
        if (strcasecmp($gateway, 'Credit') === 0) {
            $gateway = 'Credit';
        } else {
            $gateway = PaymentGateway::query()
                ->where('name', $gateway)
                ->where('is_active', true)
                ->value('name');
        }
        if (! $gateway) {
            return $this->error('支付方式不可用');
        }

        try {
            $invoice = $billing->checkout(
                $request->user(),
                $request->has('position') ? $request->input('position') : null,
                $request->input('idempotency_key', $request->header('Idempotency-Key')),
                $gateway,
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception->validator->errors());
        }

        return $this->success([
            'order_id' => $invoice->order_id,
            'orderid' => $invoice->order_id,
            'invoice_id' => $invoice->id,
            'invoiceid' => $invoice->id,
            'total' => $invoice->total,
            'status' => $invoice->status,
        ], '结算成功');
    }

    private function gateway(PaymentGateway $gateway): array
    {
        return [
            'id' => $gateway->id,
            'name' => $gateway->name,
            'title' => $gateway->title,
            'url' => $gateway->icon,
            'author_url' => '',
        ];
    }
}
