<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    public function common(): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'logo_url' => '/favicon.ico',
                'main_tenance_mode' => '0',
                'main_tenance_mode_message' => '维护模式已开启，请稍后再试！',
                'main_tenance_mode_url' => config('app.url'),
                'allow_user_language' => '1',
                'language' => 'chinese',
                'company_name' => config('kjaiu.company_name'),
                'domain' => config('app.url'),
                'system_url' => config('app.url'),
                'logo_url_home' => '/favicon.ico',
                'company_email' => config('kjaiu.company_email'),
                'certifi_open' => '0',
                'main_phone' => '',
                'main_address' => '',
                'record_no' => '',
                'company_profile' => '',
                'is_profession' => false,
                'order_page_style' => 0,
                'enable_file_download' => 0,
            ],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $firstGroupId = $request->integer('first_group_id');
        $groupId = $request->integer('group_id');
        $productId = $request->integer('product_id');
        $rootGroups = ProductGroup::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->when($firstGroupId, fn ($query) => $query->whereKey($firstGroupId))
            ->with(['children' => fn ($query) => $query
                ->where('is_active', true)
                ->when($groupId, fn ($groups) => $groups->whereKey($groupId))
                ->with(['products' => fn ($products) => $products
                    ->where('is_active', true)
                    ->when($productId, fn ($query) => $query->whereKey($productId))
                    ->with('prices')])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $keyword = trim((string) $request->input('keywords'));
        $firstGroups = $rootGroups->map(function (ProductGroup $root) use ($keyword) {
            $groups = $root->children->map(function (ProductGroup $group) use ($keyword) {
                $products = $group->products
                    ->when($keyword !== '', fn ($items) => $items->filter(
                        fn (Product $product) => str_contains(mb_strtolower($product->name), mb_strtolower($keyword))
                    ))
                    ->map(fn (Product $product) => $this->productSummary($product))
                    ->values();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'headline' => $group->headline,
                    'tagline' => $group->tagline,
                    'fields' => [],
                    'products' => $products,
                ];
            })->filter(fn (array $group) => $group['products']->isNotEmpty())->values();

            return [
                'id' => $root->id,
                'name' => $root->name,
                'fields' => [],
                'group' => $groups,
            ];
        })->filter(fn (array $group) => $group['group']->isNotEmpty())->values();

        return $this->success([
            'first_group' => $firstGroups,
            'currency' => config('kjaiu.currency'),
        ], 'Success message');
    }

    public function product(Product $product): JsonResponse
    {
        if (! $product->is_active) {
            return $this->error('商品不存在', 404);
        }

        $product->load(['group.parent', 'prices']);

        return $this->success([
            'product' => $this->productDetail($product),
            'currency' => config('kjaiu.currency'),
        ]);
    }

    public function productConfig(Request $request): JsonResponse
    {
        $product = Product::query()->find($request->integer('product_id'));
        if (! $product) {
            return $this->error('商品不存在', 404);
        }

        return $this->product($product);
    }

    public function legacyCart(Request $request): JsonResponse
    {
        $groupId = $request->integer('gid');
        $group = ProductGroup::query()
            ->where('is_active', true)
            ->when($groupId, fn ($query) => $query->whereKey($groupId))
            ->whereNotNull('parent_id')
            ->with(['products' => fn ($query) => $query->where('is_active', true)->with('prices'), 'parent'])
            ->orderBy('sort_order')
            ->first();

        $currency = config('kjaiu.currency');
        $products = $group?->products->map(fn (Product $product) => $this->productDetail($product))->values() ?? collect();
        $firstGroups = ProductGroup::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return response()->json([
            'status' => 200,
            'msg' => '请求成功',
            'product_groups' => $group ? [[
                'id' => $group->id,
                'name' => $group->name,
                'headline' => $group->headline,
                'tagline' => $group->tagline,
                'order' => $group->sort_order,
                'gid' => $group->parent_id,
                'order_frm_tpl' => '',
                'tpl_type' => 'default',
            ]] : [],
            'currencies' => [$currency],
            'default_currency' => $currency,
            'products' => $products,
            'first_groups' => $firstGroups,
            'order_page_style' => 0,
            'is_aff' => '0',
        ]);
    }

    private function productSummary(Product $product): array
    {
        $metadata = $product->metadata ?? [];

        return [
            'id' => $product->id,
            'type' => $product->type,
            'name' => $product->name,
            'description' => $product->description ?? '',
            'stock_control' => $product->stock_control ? 1 : 0,
            'qty' => $product->stock_control ? (int) $product->quantity : 999,
            'product_price' => $product->price,
            'setup_fee' => $product->setup_fee,
            'billingcycle' => $product->billing_cycle,
            'ontrial' => $metadata['ontrial'] ?? ['ontrial' => 0],
        ];
    }

    private function productDetail(Product $product): array
    {
        $summary = $this->productSummary($product);

        return array_merge($summary, [
            'gid' => $product->product_group_id,
            'pay_method' => $product->pay_method,
            'tax' => 0,
            'order' => $product->sort_order,
            'pay_type' => [
                'pay_type' => $product->billing_cycle === 'free' ? 'free' : 'recurring',
                'pay_hour_cycle' => '720',
                'pay_day_cycle' => '30',
                'pay_ontrial_status' => 0,
                'pay_ontrial_condition' => [],
            ],
            'api_type' => 'normal',
            'prices' => $product->prices->where('is_active', true)->map(fn ($price) => [
                'billingcycle' => $price->billing_cycle,
                'price' => $price->price,
                'setup_fee' => $price->setup_fee,
            ])->values(),
        ]);
    }
}
