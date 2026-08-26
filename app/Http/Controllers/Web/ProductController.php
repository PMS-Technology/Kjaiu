<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->input('q'));
        $products = Product::query()
            ->with(['group.parent', 'prices'])
            ->when($keyword !== '', fn ($query) => $query->where('name', 'like', "%$keyword%"))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $rootGroups = ProductGroup::query()
            ->whereNull('parent_id')
            ->withCount('children')
            ->orderBy('sort_order')
            ->get();
        $groups = ProductGroup::query()
            ->whereNotNull('parent_id')
            ->with('parent')
            ->orderBy('sort_order')
            ->get();
        $editing = $request->integer('edit')
            ? Product::query()->with('prices')->findOrFail($request->integer('edit'))
            : null;

        return view('admin.products.index', [
            'products' => $products,
            'rootGroups' => $rootGroups,
            'groups' => $groups,
            'editing' => $editing,
            'keyword' => $keyword,
            'cycles' => self::CYCLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProduct($request);

        $product = DB::transaction(function () use ($data) {
            $product = Product::create($data['product']);
            $this->syncPrices($product, $data['prices']);

            return $product;
        });

        AuditLog::record($request, 'product.created', $product, null, $product->only(['name', 'price', 'billing_cycle']));

        return redirect()->route('admin.products.index')->with('success', '商品已创建');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $before = $product->only(['name', 'price', 'billing_cycle', 'quantity', 'is_active']);
        $data = $this->validateProduct($request);

        DB::transaction(function () use ($product, $data) {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            if (! $data['product']['stock_control']) {
                $data['product']['quantity'] = $lockedProduct->quantity;
            }

            $quantity = $data['product']['quantity'];
            $reservedQuantity = (int) OrderItem::query()
                ->where('product_id', $lockedProduct->id)
                ->where('reserved_quantity', '>', 0)
                ->whereHas('order', fn ($query) => $query->where('status', 'Pending'))
                ->sum('reserved_quantity');
            if ($quantity !== null && $reservedQuantity > Product::MAX_STOCK - $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => '可售库存与待释放预占之和超出系统支持范围',
                ]);
            }

            $lockedProduct->update($data['product']);
            $this->syncPrices($lockedProduct, $data['prices']);
        }, 3);

        AuditLog::record(
            $request,
            'product.updated',
            $product,
            $before,
            $product->fresh()->only(['name', 'price', 'billing_cycle', 'quantity', 'is_active']),
        );

        return redirect()->route('admin.products.index')->with('success', '商品已更新');
    }

    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $before = ['is_active' => $product->is_active];
        $product->update(['is_active' => ! $product->is_active]);
        AuditLog::record($request, 'product.toggled', $product, $before, ['is_active' => $product->is_active]);

        return back()->with('success', $product->is_active ? '商品已上架' : '商品已下架');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_groups', 'id')->whereNull('parent_id')],
            'headline' => ['nullable', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
        ]);

        $data['headline'] = $data['headline'] ?? '';
        $data['tagline'] = $data['tagline'] ?? '';
        $data['is_active'] = true;
        $group = ProductGroup::create($data);
        AuditLog::record($request, 'product_group.created', $group, null, $group->only(['name', 'parent_id']));

        return back()->with('success', '商品分组已创建');
    }

    private function validateProduct(Request $request): array
    {
        $prices = $request->input('prices', []);
        if (is_array($prices)) {
            $request->merge([
                'prices' => collect($prices)
                    ->filter(fn ($price) => ! is_array($price) || filled($price['billing_cycle'] ?? null))
                    ->values()
                    ->all(),
            ]);
        }

        $validated = $request->validate([
            'product_group_id' => [
                'required',
                'integer',
                Rule::exists('product_groups', 'id')->whereNotNull('parent_id'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:10000'],
            'billing_cycle' => ['required', Rule::in(array_keys(self::CYCLES))],
            'price' => ['required', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'],
            'setup_fee' => ['required', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'],
            'stock_control' => ['nullable', 'boolean'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:'.Product::MAX_STOCK],
            'auto_setup' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['array:billing_cycle,price,setup_fee'],
            'prices.*.billing_cycle' => ['required', 'distinct', Rule::in(array_keys(self::CYCLES))],
            'prices.*.price' => ['required', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'],
            'prices.*.setup_fee' => ['nullable', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'],
        ]);

        if (collect($validated['prices'] ?? [])->contains('billing_cycle', $validated['billing_cycle'])) {
            throw ValidationException::withMessages([
                'prices' => '附加周期不能与默认周期重复',
            ]);
        }

        $stockControl = $request->boolean('stock_control');
        if ($stockControl && ($validated['quantity'] ?? null) === null) {
            throw ValidationException::withMessages(['quantity' => '启用库存控制时必须填写库存']);
        }

        return [
            'product' => [
                'product_group_id' => $validated['product_group_id'],
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
                'billing_cycle' => $validated['billing_cycle'],
                'price' => $validated['price'],
                'setup_fee' => $validated['setup_fee'],
                'stock_control' => $stockControl,
                'quantity' => $stockControl ? ($validated['quantity'] ?? null) : null,
                'auto_setup' => $request->boolean('auto_setup'),
                'is_active' => $request->boolean('is_active'),
            ],
            'prices' => $validated['prices'] ?? [],
        ];
    }

    private function syncPrices(Product $product, array $prices): void
    {
        $cycles = collect($prices)
            ->reject(fn (array $price) => $price['billing_cycle'] === $product->billing_cycle)
            ->keyBy('billing_cycle');

        $product->prices()->whereNotIn('billing_cycle', $cycles->keys())->delete();
        foreach ($cycles as $cycle => $price) {
            $product->prices()->updateOrCreate(
                ['billing_cycle' => $cycle],
                ['price' => $price['price'], 'setup_fee' => $price['setup_fee'] ?? 0, 'is_active' => true],
            );
        }
    }
}
