<?php

namespace App\Http\Controllers\Web;

use App\Models\Product;
use App\Models\ProductFirstGroup;
use App\Models\ProductGroup;
use App\Services\PricingService;
use App\Support\Settings;
use App\Support\StatusMap;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public storefront landing page (`/`).
 *
 * Renders the featured products, the announcement strip and the category
 * navigation. The header/footer chrome is shared with the client area.
 */
class HomeController extends WebController
{
    public function index(): View
    {
        return view('web.home', array_merge($this->shared(), [
            'Title' => (string) (Settings::get('web_name') ?: config('kjaiu.name')),
            'TplName' => 'home',
            'featured' => $this->featuredProducts(),
            'categories' => $this->categories(),
            'announcements' => $this->announcements(),
        ]));
    }

    /**
     * GET /index
     */
    public function dashboardEntry(Request $request)
    {
        return $this->client() === null
            ? redirect()->to('/login')
            : redirect()->to('/clientarea');
    }

    /**
     * GET /config_general/header — site chrome for embedded pages.
     */
    public function header(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->ok([
            'setting' => $this->settingPayload(),
            'currency' => $this->currencyPayload(),
            'nav' => $this->navigation($this->client() !== null),
        ]);
    }

    /**
     * GET /common_list and /navindex — navigation for the user-defined
     * shortcuts menu.
     */
    public function commonList(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->ok($this->navigation($this->client() !== null));
    }

    /**
     * GET /sale_list — sales representatives offered at registration.
     */
    public function saleList(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->ok([]);
    }

    /**
     * Featured products for the landing page.
     */
    protected function featuredProducts(): array
    {
        $pricing = new PricingService();
        $currencyId = $pricing->currencyId();

        $products = Product::query()
            ->where('hidden', 0)
            ->where('retired', 0)
            ->orderByDesc('is_featured')
            ->orderBy('order')
            ->limit(8)
            ->get();

        return $products->map(function (Product $product) use ($pricing, $currencyId) {
            $cycles = $pricing->availableCycles($product, $currencyId);
            $cheapest = null;

            foreach ($cycles as $cycle) {
                if (in_array($cycle, ['free', 'ontrial'], true)) {
                    continue;
                }

                $price = (float) $pricing->cyclePrice($product, $cycle, $currencyId);

                if ($cheapest === null || $price < $cheapest['amount']) {
                    $cheapest = ['cycle' => $cycle, 'amount' => $price];
                }
            }

            return [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'description' => (string) $product->description,
                'type_zh' => StatusMap::productType((string) $product->type),
                'price' => $cheapest === null ? null : number_format($cheapest['amount'], 2, '.', ''),
                'cycle_zh' => $cheapest === null ? '' : StatusMap::cycle($cheapest['cycle']),
                'sold_out' => (int) $product->stock_control === 1 && (int) $product->qty <= 0,
            ];
        })->all();
    }

    /**
     * Category tree for the storefront navigation.
     */
    protected function categories(): array
    {
        return ProductFirstGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->with(['groups' => fn ($query) => $query->where('hidden', 0)->orderBy('order')])
            ->get()
            ->map(fn (ProductFirstGroup $first) => [
                'id' => (int) $first->id,
                'name' => (string) $first->name,
                'groups' => $first->groups->map(fn (ProductGroup $group) => [
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                ])->all(),
            ])
            ->all();
    }

    protected function announcements(): array
    {
        return \Illuminate\Support\Facades\DB::table('news_menu')
            ->where('hidden', '0')
            ->where('parent_id', 0)
            ->where(function ($query) {
                $query->where('push_time', 0)->orWhere('push_time', '<=', time());
            })
            ->orderByDesc('push_time')
            ->limit(5)
            ->get(['id', 'title', 'description', 'push_time'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'description' => (string) $row->description,
                'push_time' => (int) $row->push_time,
            ])
            ->all();
    }
}
