<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Services\BillingService;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    public function index(Request $request): View
    {
        $items = $this->cartItems($request);
        $cartQuantities = $items->groupBy('product_id')->map->sum('quantity');
        $totalMinor = 0;
        $amountKnown = true;
        $lines = $items->map(function (CartItem $item) use ($cartQuantities, &$totalMinor, &$amountKnown) {
            $price = $this->displayPrice($item);
            $issue = $this->availabilityIssue($item, $price, (int) $cartQuantities->get($item->product_id, 0));
            $unitMinor = $price
                ? Money::toMinor($price->price) + Money::toMinor($price->setup_fee)
                : null;
            $lineMinor = $unitMinor === null ? null : $unitMinor * $item->quantity;
            if ($lineMinor === null) {
                $amountKnown = false;
            } else {
                $totalMinor += $lineMinor;
            }

            return [
                'item' => $item,
                'available' => $issue === null,
                'issue' => $issue,
                'unit_total' => $unitMinor === null ? null : Money::format($unitMinor),
                'line_total' => $lineMinor === null ? null : Money::format($lineMinor),
            ];
        });

        return view('portal.cart.index', [
            'lines' => $lines,
            'total' => $amountKnown ? Money::format($totalMinor) : null,
            'checkoutBlocked' => $lines->contains(fn (array $line) => ! $line['available']),
            'gateways' => PaymentGateway::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(name) != ?', ['credit'])
                ->orderBy('sort_order')
                ->get(['id', 'name', 'title', 'icon']),
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
        ]);
    }

    public function update(Request $request, int $cartItem): RedirectResponse
    {
        $item = $this->ownedItem($request, $cartItem);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $item->load(['product.group.parent', 'product.prices']);
        $cartQuantity = (int) CartItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $item->product_id)
            ->sum('quantity');
        if ($this->availabilityIssue($item, $this->displayPrice($item), $cartQuantity) !== null) {
            return back()->withErrors(['quantity' => '该商品或付款周期已不可用']);
        }

        if ($item->product->stock_control) {
            $otherQuantity = (int) CartItem::query()
                ->where('user_id', $request->user()->id)
                ->where('product_id', $item->product_id)
                ->where('id', '!=', $item->id)
                ->sum('quantity');
            if ($item->product->quantity === null || $otherQuantity + $data['quantity'] > $item->product->quantity) {
                return back()->withErrors(['quantity' => '商品库存不足']);
            }
        }

        $item->update(['quantity' => $data['quantity']]);

        return back()->with('success', '购物车数量已更新');
    }

    public function destroy(Request $request, int $cartItem): RedirectResponse
    {
        $this->ownedItem($request, $cartItem)->delete();

        return back()->with('success', '商品已移出购物车');
    }

    public function checkout(Request $request, BillingService $billing): RedirectResponse
    {
        $data = $request->validate([
            'payment' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'uuid', 'max:64'],
        ]);

        $gateway = $this->gateway($data['payment']);
        if ($gateway === null) {
            return back()->withErrors(['payment' => '支付方式不可用'])->withInput();
        }

        $existingCheckout = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->exists();
        if (! $existingCheckout) {
            $items = $this->cartItems($request);
            if ($items->isEmpty()) {
                return back()->withErrors(['items' => '购物车不能为空'])->withInput();
            }

            $cartQuantities = $items->groupBy('product_id')->map->sum('quantity');
            $unavailable = $items->contains(function (CartItem $item) use ($cartQuantities) {
                return $this->availabilityIssue(
                    $item,
                    $this->displayPrice($item),
                    (int) $cartQuantities->get($item->product_id, 0),
                ) !== null;
            });
            if ($unavailable) {
                return back()->withErrors([
                    'items' => '购物车包含不可结算项目，请先移除后再结算',
                ])->withInput();
            }
        }

        try {
            $invoice = $billing->checkout(
                $request->user(),
                null,
                $data['idempotency_key'],
                $gateway,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $redirect = redirect()->route('portal.invoices.show', $invoice);
        if ($invoice->status === 'Paid') {
            return $redirect->with('success', '订单已创建并完成结算');
        }
        if ($gateway === 'Credit') {
            return $redirect->with('success', '订单已创建，请确认使用账户余额支付账单');
        }

        try {
            $invoice = $billing->prepareGatewayPayment($request->user(), $invoice, $gateway);
        } catch (ValidationException $exception) {
            return redirect()->route('portal.invoices.show', $invoice)
                ->withErrors($exception->errors());
        }

        return redirect()->route('portal.invoices.show', $invoice)
            ->with('pending', "账单 {$invoice->number} 尚未完成付款。请联系 ".config('kjaiu.company_email')." 并提供账单号获取 {$gateway} 付款指引；到账确认前账单将保持待支付。");
    }

    private function ownedItem(Request $request, int $cartItem): CartItem
    {
        return CartItem::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($cartItem);
    }

    private function cartItems(Request $request)
    {
        return CartItem::query()
            ->where('user_id', $request->user()->id)
            ->with(['product.group.parent', 'product.prices'])
            ->orderBy('id')
            ->get();
    }

    private function displayPrice(CartItem $item)
    {
        $product = $item->product;
        if (! $product) {
            return null;
        }

        if ($item->billing_cycle === $product->billing_cycle) {
            return $product->priceFor($item->billing_cycle);
        }

        return $product->prices->firstWhere('billing_cycle', $item->billing_cycle);
    }

    private function availabilityIssue(CartItem $item, $price, int $cartQuantity): ?string
    {
        $product = $item->product;
        if (! $product?->is_active) {
            return '该商品已下架，请移除后再结算。';
        }
        if (! $product->group?->is_active) {
            return '该商品分组已停用，请移除后再结算。';
        }
        if (! $product->group?->parent?->is_active) {
            return '该商品的上级分组已停用，请移除后再结算。';
        }
        if (! $price?->is_active) {
            return '该付款周期已不可用，请移除后再结算。';
        }
        if ($product->stock_control
            && ($product->quantity === null || $cartQuantity > $product->quantity)) {
            return '当前库存不足，请移除后再结算。';
        }

        return null;
    }

    private function gateway(string $gateway): ?string
    {
        $gateway = trim($gateway);
        if (strcasecmp($gateway, 'Credit') === 0) {
            return 'Credit';
        }

        return PaymentGateway::query()
            ->where('is_active', true)
            ->where('name', $gateway)
            ->value('name');
    }
}
