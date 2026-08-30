<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const CYCLES = [
        'free' => '免费',
        'hourly' => '小时',
        'daily' => '天',
        'monthly' => '月',
        'quarterly' => '季',
        'semiannually' => '半年',
        'annually' => '年',
        'biennially' => '两年',
        'triennially' => '三年',
        'onetime' => '一次性',
    ];

    public function __invoke(): View
    {
        $visibleProducts = Product::query()
            ->where('is_active', true)
            ->whereHas('group', fn ($group) => $group
                ->where('is_active', true)
                ->whereNotNull('parent_id')
                ->whereHas('parent', fn ($parent) => $parent->where('is_active', true)));
        $productCount = (clone $visibleProducts)->count();
        $products = $visibleProducts
            ->with([
                'group.parent:id,name',
                'prices' => fn ($prices) => $prices->where('is_active', true),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(6)
            ->get()
            ->each(function (Product $product): void {
                $lowest = collect([[
                    'billing_cycle' => $product->billing_cycle,
                    'price' => $product->price,
                ]])->merge($product->prices->map(fn ($price): array => [
                    'billing_cycle' => $price->billing_cycle,
                    'price' => $price->price,
                ]))->sortBy(fn (array $price): float => (float) $price['price'])->first();
                $product->setAttribute('homepage_price', $lowest['price']);
                $product->setAttribute('homepage_billing_cycle', $lowest['billing_cycle']);
            });

        return view('home', [
            'products' => $products,
            'cycles' => self::CYCLES,
            'productCount' => $productCount,
            'categoryCount' => ProductGroup::query()
                ->whereNotNull('parent_id')
                ->where('is_active', true)
                ->whereHas('parent', fn ($parent) => $parent->where('is_active', true))
                ->whereHas('products', fn ($products) => $products->where('is_active', true))
                ->count(),
        ]);
    }
}
