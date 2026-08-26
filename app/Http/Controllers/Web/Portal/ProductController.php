<?php

namespace App\Http\Controllers\Web\Portal;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const CYCLES = [
        'free' => '免费',
        'hourly' => '小时付',
        'daily' => '天付',
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
        'biennially' => '两年付',
        'triennially' => '三年付',
        'onetime' => '一次性',
    ];

    public function index(): View
    {
        $groups = ProductGroup::query()
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->whereHas('parent', fn ($query) => $query->where('is_active', true))
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->with([
                'parent:id,name',
                'products' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with(['prices' => fn ($prices) => $prices->where('is_active', true)])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('portal.products.index', [
            'groups' => $groups,
            'cycles' => self::CYCLES,
        ]);
    }

    public function show(int $product): View
    {
        $product = $this->activeProduct($product);

        return view('portal.products.show', [
            'product' => $product,
            'prices' => $this->prices($product),
            'cycles' => self::CYCLES,
        ]);
    }

    public function addToCart(Request $request, int $product): RedirectResponse
    {
        $product = $this->activeProduct($product);
        $availableCycles = $this->prices($product)->pluck('billing_cycle')->all();
        $data = $request->validate([
            'billing_cycle' => ['required', 'string', Rule::in($availableCycles)],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        if ($product->stock_control) {
            $quantityInCart = (int) CartItem::query()
                ->where('user_id', $request->user()->id)
                ->where('product_id', $product->id)
                ->sum('quantity');
            if ($product->quantity === null || $quantityInCart + $data['quantity'] > $product->quantity) {
                return back()->withErrors(['quantity' => '商品库存不足'])->withInput();
            }
        }

        CartItem::create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'billing_cycle' => $data['billing_cycle'],
            'quantity' => $data['quantity'],
            'configuration' => [],
        ]);

        return redirect()->route('portal.cart.index')->with('success', '商品已加入购物车');
    }

    private function activeProduct(int $product): Product
    {
        return Product::query()
            ->whereKey($product)
            ->where('is_active', true)
            ->whereHas('group', fn ($group) => $group
                ->where('is_active', true)
                ->whereHas('parent', fn ($parent) => $parent->where('is_active', true)))
            ->with([
                'group.parent:id,name',
                'prices' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
            ])
            ->firstOrFail();
    }

    private function prices(Product $product)
    {
        return collect([[
            'billing_cycle' => $product->billing_cycle,
            'price' => $product->price,
            'setup_fee' => $product->setup_fee,
        ]])->merge($product->prices->map(fn ($price) => [
            'billing_cycle' => $price->billing_cycle,
            'price' => $price->price,
            'setup_fee' => $price->setup_fee,
        ]))->unique('billing_cycle')->values();
    }
}
