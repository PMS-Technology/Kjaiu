<?php

namespace App\Http\Controllers\Web;

use App\Models\CartSession;
use App\Models\Client;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\ProductConfigGroup;
use App\Models\ProductConfigLink;
use App\Models\ProductConfigOption;
use App\Models\ProductConfigOptionSub;
use App\Models\ProductFirstGroup;
use App\Models\ProductGroup;
use App\Models\PromoCode;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Support\ApiResponse;
use App\Support\CartStore;
use App\Support\Settings;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Order flow: product listing, configure-product, cart, checkout.
 *
 * URLs keep the original's `?action=` multiplexing
 * (`/cart`, `/cart?action=product`, `/cart?action=configureproduct&pid=`,
 * `/cart?action=viewcart`), and the JSON helpers under `/cart/*` keep their
 * original names because the cart theme's JavaScript calls them directly.
 */
class CartController extends WebController
{
    public function __construct(
        protected CartStore $store = new CartStore(),
        protected PricingService $pricing = new PricingService(),
        protected CartService $cart = new CartService(),
        protected OrderService $orders = new OrderService(),
    ) {
    }

    // -----------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------

    /**
     * GET|POST /cart[?action=product|configureproduct|viewcart|complete]
     */
    public function index(Request $request): View|RedirectResponse|string
    {
        $action = (string) $request->input('action', 'product');

        return match ($action) {
            'configureproduct' => $this->configurePage($request),
            'viewcart' => $this->viewCart($request),
            'complete' => $this->complete($request),
            'checkout' => $this->checkoutPage($request),
            default => $this->productList($request),
        };
    }

    /**
     * Product listing, filtered by first-level group (`fid`) and group (`gid`).
     */
    public function productList(Request $request): View
    {
        $fid = (int) $request->input('fid', 0);
        $gid = (int) $request->input('gid', 0);
        $keywords = trim((string) $request->input('keywords', ''));

        $firstGroups = ProductFirstGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->with(['groups' => fn ($query) => $query->where('hidden', 0)->orderBy('order')])
            ->get();

        // Default to the first category so the sidebar always has a selection.
        if ($fid === 0 && $firstGroups->isNotEmpty()) {
            $fid = (int) $firstGroups->first()->id;
        }

        $groups = ProductGroup::query()
            ->where('gid', $fid)
            ->where('hidden', 0)
            ->orderBy('order')
            ->get();

        if ($gid === 0 && $groups->isNotEmpty()) {
            $gid = (int) $groups->first()->id;
        }

        $products = Product::query()
            ->where('hidden', 0)
            ->where('retired', 0)
            ->when($keywords !== '', fn ($query) => $query->where('name', 'like', '%' . $keywords . '%'))
            ->when($gid > 0, fn ($query) => $query->where('gid', $gid))
            ->orderBy('order')
            ->get();

        $currency = Currency::default();

        return view('web.cart.product', array_merge($this->shared(), [
            'Title' => '订购产品',
            'TplName' => 'cart',
            'Cart' => [
                'product_groups' => $firstGroups->map(fn (ProductFirstGroup $first) => [
                    'id' => (int) $first->id,
                    'name' => (string) $first->name,
                    'second' => $first->groups->map(fn (ProductGroup $group) => [
                        'id' => (int) $group->id,
                        'gid' => (int) $first->id,
                        'name' => (string) $group->name,
                    ])->all(),
                ])->all(),
                'product_groups_checked' => $this->checkedGroup($gid),
                'products' => $products->map(fn (Product $product) => $this->productCard($product, $currency))->all(),
                'currency' => $this->currencyPayload(),
                'fid' => $fid,
                'gid' => $gid,
                'keywords' => $keywords,
            ],
        ]));
    }

    /**
     * Product configuration form.
     */
    public function configurePage(Request $request): View|RedirectResponse
    {
        $productId = (int) $request->input('pid', 0);
        $position = (int) $request->input('i', 0);
        $client = $this->client();

        $product = Product::query()->find($productId);

        if ($product === null || ! $product->isPurchasable()) {
            return redirect()->to('/cart')->with('error', '商品不存在或已下架');
        }

        // Editing an existing line pre-fills the form from the cart.
        $existing = null;

        if ($position > 0) {
            $cart = $this->store->resolve($client);
            $items = $this->store->items($cart);
            $existing = $items[$position - 1] ?? null;
        }

        $currency = Currency::default();

        return view('web.cart.configureproduct', array_merge($this->shared(), [
            'Title' => '配置产品',
            'TplName' => 'configureproduct',
            'CartConfig' => [
                'product' => $product,
                'dafault_currencyid' => $currency?->id,
                'option' => $this->configOptions($product, $existing),
                'links' => $this->configLinks($product),
                'custom_fields' => $this->productCustomFields($product),
                'host' => (string) ($existing['host'] ?? ''),
                'password' => (string) ($existing['password'] ?? ''),
                'cycle' => $this->cycleOptions($product, $currency),
                'billingcycle' => (string) ($existing['cycle'] ?? $this->defaultCycle($product)),
                'qty' => (int) ($existing['qty'] ?? 1),
                'allow_qty' => (int) $product->allow_qty === 1,
            ],
            'addParam' => [
                'promocode' => (string) $request->input('promocode', ''),
                'aff' => (string) $request->input('aff', ''),
                'sale' => (string) $request->input('sale', ''),
            ],
            'position' => $position,
            'currency' => $this->currencyPayload(),
            'CartCount' => $this->store->count($this->store->resolve($client)),
        ]));
    }

    /**
     * Cart view with promotion code, payment selection and terms.
     */
    public function viewCart(Request $request): View
    {
        $client = $this->client();
        $cart = $this->store->resolve($client);
        $payload = $this->cartPayload($cart, $client);

        return view('web.cart.viewcart', array_merge($this->shared(), [
            'Title' => '购物车',
            'TplName' => 'cart',
            'ShopData' => $payload,
            'Register' => [
                'allow_register_email' => Settings::on('allow_register_email', true),
                'allow_register_phone' => Settings::on('allow_register_phone', true),
                'allow_register_email_captcha' => Settings::on('allow_register_email_captcha'),
                'allow_register_phone_captcha' => Settings::on('allow_register_phone_captcha'),
                'fields' => CustomField::query()
                    ->where('type', 'client')
                    ->orderBy('sortorder')
                    ->get()
                    ->map(fn (CustomField $field) => [
                        'id' => (int) $field->id,
                        'fieldname' => (string) $field->fieldname,
                        'fieldtype' => (string) $field->fieldtype,
                        'required' => (int) $field->required,
                        'dropdown_option' => $field->options(),
                    ])
                    ->all(),
            ],
            'SmsCountry' => $this->phoneCodesForCart(),
            'saleList' => [],
            'logined' => $client !== null,
        ]));
    }

    /**
     * Checkout summary page (`/cart/check_page`).
     */
    public function checkoutPage(Request $request): View
    {
        $client = $this->requireClient();
        $cart = $this->store->resolve($client);
        $payload = $this->cartPayload($cart, $client);

        return view('web.cart.check', array_merge($this->shared(), [
            'Title' => '结算',
            'TplName' => 'cart',
            'ShopData' => $payload,
            'logined' => true,
        ]));
    }

    /**
     * Post-order landing page.
     */
    public function complete(Request $request): View
    {
        return view('web.cart.complete', array_merge($this->shared(), [
            'Title' => '下单成功',
            'TplName' => 'cart',
            'invoiceid' => (int) $request->input('invoiceid', 0),
            'ordernum' => (string) $request->input('ordernum', ''),
        ]));
    }

    // -----------------------------------------------------------------
    // Cart mutations
    // -----------------------------------------------------------------

    /**
     * POST /cart?action=configureproduct&pid=N — add or update a line item.
     */
    public function addToCart(Request $request): RedirectResponse|JsonResponse
    {
        $product = Product::query()->find((int) $request->input('pid', 0));

        if ($product === null || ! $product->isPurchasable()) {
            return $this->back($request, false, '商品不存在或已下架', '/cart');
        }

        if ((int) $product->stock_control === 1 && (int) $product->qty <= 0) {
            return $this->back($request, false, '商品库存不足', '/cart?action=configureproduct&pid=' . $product->id);
        }

        $cycle = (string) $request->input('billingcycle', '');
        $unit = $this->pricing->cyclePrice($product, $cycle);

        if ($unit === null) {
            return $this->back($request, false, '请选择有效的计费周期', '/cart?action=configureproduct&pid=' . $product->id);
        }

        $selections = $this->normaliseSelections((array) $request->input('configoption', []));
        $optionsTotal = $this->pricing->configOptionsTotal($product, $selections, $cycle);
        $setupFee = $this->pricing->setupFee($product, $cycle);
        $qty = max(1, (int) $request->input('qty', 1));

        if ((int) $product->allow_qty !== 1) {
            $qty = 1;
        }

        $item = [
            'productid' => (int) $product->id,
            'name' => (string) $product->name,
            'gid' => (int) $product->gid,
            'type' => (string) $product->type,
            'cycle' => $cycle,
            'qty' => $qty,
            'unit_amount' => $this->pricing->money((float) $unit),
            'configoptions' => $selections,
            'configoptions_amount' => $this->pricing->money($optionsTotal),
            'setup_fee' => $this->pricing->money($setupFee),
            'host' => mb_substr((string) $request->input('host', ''), 0, 255),
            'password' => (string) $request->input('password', ''),
            'custom' => (array) $request->input('customfield', []),
            'amount' => PricingService::money(((float) $unit + $optionsTotal) * $qty + $setupFee),
            'add_time' => time(),
        ];

        $client = $this->client();
        $cart = $this->store->resolve($client);
        $position = (int) $request->input('i', 0);

        if ($position > 0) {
            $this->store->replace($cart, $position, $item);
            $message = '配置已更新';
        } else {
            $this->store->push($cart, $item);
            $message = '已加入购物车';
        }

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok([
                'count' => $this->store->count($cart),
                'total' => $this->store->totals($cart)['total'],
            ], $message);
        }

        return redirect()->to('/cart?action=viewcart')->with('success', $message);
    }

    /**
     * POST /cart/add_to_shop — the JSON add endpoint the cart theme uses.
     */
    public function addToShop(Request $request): JsonResponse
    {
        $response = $this->addToCart($request);

        if ($response instanceof JsonResponse) {
            return $response;
        }

        return $this->ok(null, '已加入购物车');
    }

    /**
     * POST /cart/edit_to_shop — reconfigure a cart line.
     */
    public function editToShop(Request $request): JsonResponse
    {
        $position = (int) $request->input('i', $request->input('position', 0));

        if ($position <= 0) {
            return $this->fail('参数错误');
        }

        return $this->addToShop($request);
    }

    /**
     * POST /cart?action=viewcart&statuscart=change — quantity update.
     */
    public function changeQty(Request $request): RedirectResponse|JsonResponse
    {
        $positions = (array) $request->input('i', []);
        $qty = (int) $request->input('qty', 1);

        $client = $this->client();
        $cart = $this->store->resolve($client);

        foreach ($positions as $position) {
            $this->store->setQty($cart, (int) $position, $qty);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok($this->cartPayload($cart->refresh(), $client), '修改成功');
        }

        return redirect()->to('/cart?action=viewcart');
    }

    /**
     * POST /cart?action=viewcart&statuscart=remove
     */
    public function removeItem(Request $request): RedirectResponse|JsonResponse
    {
        $positions = (array) $request->input('i', []);

        $client = $this->client();
        $cart = $this->store->resolve($client);
        $this->store->remove($cart, array_map('intval', $positions));

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok($this->cartPayload($cart->refresh(), $client), '删除成功');
        }

        return redirect()->to('/cart?action=viewcart')->with('success', '删除成功');
    }

    /**
     * POST /cart/clear
     */
    public function clearCart(Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->client();
        $cart = $this->store->resolve($client);
        $this->store->clear($cart);

        return $this->back($request, true, '购物车已清空', '/cart?action=viewcart');
    }

    /**
     * POST cart/add_promo
     */
    public function addPromo(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('promo', $request->input('code', '')));

        if ($code === '') {
            return $this->fail('请输入优惠码');
        }

        $promo = $this->cart->findPromo($code);

        if ($promo === null) {
            return $this->fail('优惠码无效或已过期');
        }

        $client = $this->client();
        $cart = $this->store->resolve($client);

        if ($this->store->items($cart) === []) {
            return $this->fail('购物车为空');
        }

        if (! $this->promoApplies($promo, $cart)) {
            return $this->fail('该优惠码不适用于购物车中的商品');
        }

        $this->store->setPromoCode((string) $promo->code);
        $totals = $this->store->totals($cart);

        return $this->ok([
            'promo' => (string) $promo->code,
            'promo_desc_str' => $this->promoDescription($promo),
            'discount' => number_format($totals['discount'], 2, '.', ''),
            'total' => number_format($totals['total'], 2, '.', ''),
        ], '优惠码已应用');
    }

    /**
     * POST cart/remove_promo
     */
    public function removePromo(Request $request): JsonResponse
    {
        $client = $this->client();
        $cart = $this->store->resolve($client);
        $this->store->setPromoCode('');

        $totals = $this->store->totals($cart);

        return $this->ok([
            'total' => number_format($totals['total'], 2, '.', ''),
        ], '优惠码已移除');
    }

    /**
     * GET cart/check_promo_code
     */
    public function checkPromo(Request $request): JsonResponse
    {
        $promo = $this->cart->findPromo((string) $request->input('promo', $request->input('code', '')));

        if ($promo === null) {
            return $this->fail('优惠码无效或已过期');
        }

        return $this->ok([
            'code' => (string) $promo->code,
            'desc' => $this->promoDescription($promo),
            'discount' => number_format($promo->discountFor($this->store->subtotal($this->store->resolve($this->client()))), 2, '.', ''),
        ]);
    }

    /**
     * GET cart/summary — price summary without mutating anything.
     */
    public function summary(Request $request): JsonResponse
    {
        $client = $this->client();
        $cart = $this->store->resolve($client);

        return $this->ok($this->cartPayload($cart, $client));
    }

    /**
     * GET cart/credit — the payment options the cart offers.
     */
    public function getCredit(Request $request): JsonResponse
    {
        $client = $this->client();

        return $this->ok([
            'credit' => number_format((float) ($client?->credit ?? 0), 2, '.', ''),
            'is_open_credit_limit' => (int) ($client?->is_open_credit_limit ?? 0),
            'credit_limit_balance' => number_format((float) ($client?->credit_limit_balance ?? 0), 2, '.', ''),
            'gateways' => $this->gatewayOptions(),
        ]);
    }

    /**
     * GET cart/stock_control — remaining stock for a product.
     */
    public function stockControl(Request $request): JsonResponse
    {
        $products = Product::query()
            ->whereIn('id', array_map('intval', (array) $request->input('pid', $request->input('ids', []))))
            ->get()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'stock_control' => (int) $product->stock_control,
                'qty' => (int) $product->qty,
                'sold_out' => (int) $product->stock_control === 1 && (int) $product->qty <= 0,
            ])
            ->all();

        return $this->ok($products);
    }

    /**
     * GET cart/all — every purchasable product, grouped.
     */
    public function allProducts(Request $request): JsonResponse
    {
        $currency = Currency::default();

        $groups = ProductGroup::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->get()
            ->map(function (ProductGroup $group) use ($currency) {
                $products = Product::query()
                    ->where('gid', $group->id)
                    ->where('hidden', 0)
                    ->where('retired', 0)
                    ->orderBy('order')
                    ->get();

                return [
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                    'products' => $products->map(fn (Product $product) => $this->productCard($product, $currency))->all(),
                ];
            })
            ->all();

        return $this->ok($groups);
    }

    /**
     * GET cart/get_product_config — option groups for one product.
     */
    public function getProductConfig(Request $request): JsonResponse
    {
        $product = Product::query()->find((int) $request->input('pid', 0));

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        return $this->ok([
            'option' => $this->configOptions($product, null),
            'links' => $this->configLinks($product),
            'custom_fields' => $this->productCustomFields($product),
            'cycle' => $this->cycleOptions($product, Currency::default()),
        ]);
    }

    /**
     * GET cartgateway — enabled payment gateways.
     */
    public function gatewayList(Request $request): JsonResponse
    {
        return $this->ok($this->gatewayOptions());
    }

    /**
     * GET /getLinkAgeList — option linkage recompute.
     */
    public function linkAgeList(Request $request): JsonResponse
    {
        $product = Product::query()->find((int) $request->input('pid', 0));

        if ($product === null) {
            return $this->fail('商品不存在');
        }

        return $this->ok([
            'option' => $this->configOptions($product, null),
            'links' => $this->configLinks($product),
        ]);
    }

    /**
     * POST ?action=ordersummary — order summary fragment for the config page.
     */
    public function orderSummary(Request $request): string
    {
        $product = Product::query()->find((int) $request->input('pid', 0));

        if ($product === null) {
            return '<div class="text-sm text-slate-500">商品不存在</div>';
        }

        $cycle = (string) $request->input('billingcycle', $this->defaultCycle($product));
        $selections = $this->normaliseSelections((array) $request->input('configoption', []));
        $unit = (float) ($this->pricing->cyclePrice($product, $cycle) ?? 0);
        $optionsTotal = $this->pricing->configOptionsTotal($product, $selections, $cycle);
        $setupFee = $this->pricing->setupFee($product, $cycle);
        $qty = max(1, (int) $request->input('qty', 1));

        return $this->fragment('web.cart._ordersummary', [
            'ConfigureTotal' => [
                'product_name' => (string) $product->name,
                'product_price' => number_format($unit, 2, '.', ''),
                'product_setup_fee' => number_format($setupFee, 2, '.', ''),
                'child' => $this->summaryChildren($product, $selections, $cycle),
                'total' => number_format(($unit + $optionsTotal) * $qty + $setupFee, 2, '.', ''),
                'currency' => $this->currencyPayload(),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Checkout
    // -----------------------------------------------------------------

    /**
     * POST /cart?action=viewcart&statuscart=checkout — place the order.
     */
    public function checkout(Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->client();

        // Guest checkout: the cart page posts `register_or_login` so the
        // account can be created before the order is placed.
        if ($client === null) {
            return $this->fail('请先登录', ApiResponse::UNAUTHORIZED);
        }

        $cart = $this->store->resolve($client);

        if ($this->store->items($cart) === []) {
            return $this->back($request, false, '购物车为空', '/cart?action=viewcart');
        }

        if ((string) $request->input('terms', '') === '' && ! $request->expectsJson()) {
            return $this->back($request, false, '请先阅读并同意服务条款', '/cart?action=viewcart');
        }

        $this->store->setNotes((string) $request->input('notes', ''));

        try {
            $result = $this->orders->place($client, $cart, [
                'promo' => $this->store->promoCode(),
                'notes' => $this->store->notes($cart),
                'gateway' => (string) $request->input('payment', ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->back($request, false, $e->getMessage(), '/cart?action=viewcart');
        }

        $invoice = $result['invoice'];
        $order = $result['order'];
        $this->store->setPromoCode('');

        $target = '/viewbilling?id=' . $invoice->id . '&wakeup=1';

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok([
                'order_id' => (int) $order->id,
                'ordernum' => (string) $order->ordernum,
                'invoiceid' => (int) $invoice->id,
                'amount' => number_format((float) $invoice->total, 2, '.', ''),
                'url' => $target,
            ], '下单成功');
        }

        return redirect()->to($target);
    }

    /**
     * POST cart/settle — alias kept for the original's route name.
     */
    public function settle(Request $request): RedirectResponse|JsonResponse
    {
        return $this->checkout($request);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The `$ShopData` payload the cart view renders.
     */
    protected function cartPayload(CartSession $cart, ?Client $client): array
    {
        $totals = $this->store->totals($cart);
        $promo = $totals['promo'];

        return [
            'cart_products' => $this->lineRows($cart),
            'count' => $this->store->count($cart),
            'total_price' => number_format($totals['total'], 2, '.', ''),
            'subtotal' => number_format($totals['subtotal'], 2, '.', ''),
            'discount' => number_format($totals['discount'], 2, '.', ''),
            'currency' => $this->currencyPayload(),
            'promo' => $promo === null ? null : [
                'code' => (string) $promo->code,
                'promo_desc_str' => $this->promoDescription($promo),
                'discount' => number_format($totals['discount'], 2, '.', ''),
            ],
            'gateways' => $this->gatewayOptions(),
            'paymt' => [
                'credit' => number_format((float) ($client?->credit ?? 0), 2, '.', ''),
                'is_open_credit_limit' => (int) ($client?->is_open_credit_limit ?? 0),
                'credit_limit_balance' => number_format((float) ($client?->credit_limit_balance ?? 0), 2, '.', ''),
            ],
            'notes' => $this->store->notes($cart),
        ];
    }

    /**
     * Cart rows in the shape the cart theme iterates.
     */
    protected function lineRows(CartSession $cart): array
    {
        $items = $this->store->items($cart);
        $products = Product::query()
            ->whereIn('id', array_filter(array_map(fn ($item) => (int) ($item['productid'] ?? 0), $items)))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($items as $index => $item) {
            $product = $products[$item['productid'] ?? 0] ?? null;
            $cycle = (string) ($item['cycle'] ?? '');

            $rows[] = [
                'position' => $index + 1,
                'productid' => (int) ($item['productid'] ?? 0),
                'productsname' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? 'other'),
                'qty' => (int) ($item['qty'] ?? 1),
                'allow_qty' => (int) ($product?->allow_qty ?? 0) === 1,
                'billingcycle' => $cycle,
                'cycle_desc' => StatusMap::cycle($cycle),
                'conf' => ['host' => (string) ($item['host'] ?? '')],
                'conf_child' => $this->confChildren((array) ($item['configoptions'] ?? [])),
                'product_pricing' => number_format((float) ($item['unit_amount'] ?? 0), 2, '.', ''),
                'setup_fee' => number_format((float) ($item['setup_fee'] ?? 0), 2, '.', ''),
                'sale_price' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
            ];
        }

        return $rows;
    }

    /**
     * Human-readable chosen options for one cart line.
     */
    protected function confChildren(array $selections): array
    {
        if ($selections === []) {
            return [];
        }

        $rows = [];

        foreach ($selections as $selection) {
            $option = ProductConfigOption::query()->find((int) ($selection['option'] ?? 0));

            if ($option === null) {
                continue;
            }

            $value = $selection['value'] ?? null;
            $values = is_array($value) ? $value : [$value];
            $names = [];

            foreach ($values as $subId) {
                $sub = ProductConfigOptionSub::query()->find((int) $subId);

                if ($sub !== null) {
                    $names[] = (string) $sub->option_name;
                }
            }

            $qty = (int) ($selection['qty'] ?? 0);
            $suffix = $qty > 1 ? " x{$qty} {$option->unit}" : '';

            $rows[] = [
                'name' => (string) $option->option_name,
                'sub_name' => implode('、', $names) . $suffix,
            ];
        }

        return $rows;
    }

    /**
     * Option groups attached to a product, with their choices.
     */
    protected function configOptions(Product $product, ?array $existing): array
    {
        $groupIds = ProductConfigLink::query()->where('pid', $product->id)->pluck('gid')->all();

        if ($groupIds === []) {
            return [];
        }

        $cycle = (string) ($existing['cycle'] ?? $this->defaultCycle($product));
        $selected = (array) ($existing['configoptions'] ?? []);
        $selectedByOption = [];

        foreach ($selected as $selection) {
            $selectedByOption[(int) ($selection['option'] ?? 0)] = $selection;
        }

        $options = ProductConfigOption::query()
            ->whereIn('gid', $groupIds)
            ->where('hidden', 0)
            ->orderBy('order')
            ->with(['subOptions' => fn ($query) => $query->where('hidden', 0)])
            ->get();

        $rows = [];

        foreach ($options as $option) {
            $chosen = $selectedByOption[(int) $option->id] ?? null;
            $chosenValues = (array) ($chosen['value'] ?? []);
            $chosenValues = array_map('strval', $chosenValues);

            $rows[] = [
                'id' => (int) $option->id,
                'option_name' => (string) $option->option_name,
                'option_type' => (int) $option->option_type,
                'qty_minimum' => (int) $option->qty_minimum,
                'qty_maximum' => (int) $option->qty_maximum,
                'qty_stage' => (int) $option->qty_stage,
                'unit' => (string) $option->unit,
                'notes' => (string) $option->notes,
                'required' => (int) $option->auto === 1,
                'selected' => $chosen,
                'selected_value' => $chosenValues,
                'qty' => (int) ($chosen['qty'] ?? $option->qty_minimum),
                'sub' => $option->subOptions->map(fn (ProductConfigOptionSub $sub) => [
                    'id' => (int) $sub->id,
                    'option_name' => (string) $sub->option_name,
                    'price' => number_format($this->subPrice($sub, $cycle), 2, '.', ''),
                    'selected' => in_array((string) $sub->id, $chosenValues, true),
                ])->all(),
            ];
        }

        return $rows;
    }

    protected function subPrice(ProductConfigOptionSub $sub, string $cycle): float
    {
        $pricing = $this->pricing->optionPricing($sub, Currency::default()?->id);

        return (float) ($pricing?->priceFor($cycle) ?? 0);
    }

    /**
     * Linkage rules between options, as the configure page's JS expects.
     */
    protected function configLinks(Product $product): array
    {
        $groupIds = ProductConfigLink::query()->where('pid', $product->id)->pluck('gid')->all();

        if ($groupIds === []) {
            return [];
        }

        $links = ProductConfigGroup::query()->whereIn('id', $groupIds)->get();

        return $links->map(function (ProductConfigGroup $group) {
            $optionIds = ProductConfigOption::query()
                ->where('gid', $group->id)
                ->where('hidden', 0)
                ->pluck('id')
                ->all();

            $rules = [];

            foreach (ProductConfigOption::query()->whereIn('id', $optionIds)->get() as $option) {
                if ((int) $option->linkage_pid === 0) {
                    continue;
                }

                $rules[] = [
                    'id' => (int) $option->id,
                    'pid' => (int) $option->linkage_pid,
                    'level' => (string) $option->linkage_level,
                ];
            }

            return [
                'gid' => (int) $group->id,
                'name' => (string) $group->name,
                'options' => $optionIds,
                'rules' => $rules,
            ];
        })->values()->all();
    }

    /**
     * Custom fields configured on the product group.
     */
    protected function productCustomFields(Product $product): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('product_groups_customfields')
            ->where('relid', $product->gid)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'fieldname' => (string) $row->name,
            'fieldtype' => 'text',
            'value' => (string) $row->value,
            'required' => 0,
            'dropdown_option' => [],
        ])->all();
    }

    /**
     * Billing cycle radios for a product.
     */
    protected function cycleOptions(Product $product, ?Currency $currency): array
    {
        $rows = [];

        foreach ($this->pricing->availableCycles($product, $currency?->id) as $cycle) {
            $price = (float) $this->pricing->cyclePrice($product, $cycle, $currency?->id);
            $setup = $this->pricing->setupFee($product, $cycle, $currency?->id);

            $rows[] = [
                'billingcycle' => $cycle,
                'billingcycle_zh' => StatusMap::cycle($cycle),
                'billingcycle_short' => StatusMap::cycleShort($cycle),
                'amount' => number_format($price, 2, '.', ''),
                'setup_fee' => number_format($setup, 2, '.', ''),
                'total' => number_format($price + $setup, 2, '.', ''),
                'cycle_discount' => '',
            ];
        }

        return $rows;
    }

    /**
     * Cheapest offered cycle, used when the form posts none.
     */
    protected function defaultCycle(Product $product): string
    {
        $cycles = $this->pricing->availableCycles($product);

        foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'onetime'] as $preferred) {
            if (in_array($preferred, $cycles, true)) {
                return $preferred;
            }
        }

        return $cycles[0] ?? 'monthly';
    }

    /**
     * Card payload for the product listing.
     */
    protected function productCard(Product $product, ?Currency $currency): array
    {
        $cycles = $this->cycleOptions($product, $currency);
        $cheapest = $cycles[0] ?? null;

        foreach ($cycles as $cycle) {
            if ((float) $cycle['amount'] < (float) ($cheapest['amount'] ?? PHP_FLOAT_MAX)) {
                $cheapest = $cycle;
            }
        }

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'description' => (string) $product->description,
            'type' => (string) $product->type,
            'type_zh' => StatusMap::productType((string) $product->type),
            'stock_control' => (int) $product->stock_control,
            'qty' => (int) $product->qty,
            'has_bates' => false,
            'sale_price' => $cheapest['total'] ?? '0.00',
            'product_price' => $cheapest['amount'] ?? '0.00',
            'billingcycle_zh' => $cheapest['billingcycle_zh'] ?? '',
            'cycle' => $cheapest['billingcycle'] ?? '',
            'allow_qty' => (int) $product->allow_qty === 1,
        ];
    }

    /**
     * Selected first/second-level group caption.
     */
    protected function checkedGroup(int $gid): array
    {
        $group = ProductGroup::query()->find($gid);

        return [
            'id' => (int) ($group?->id ?? 0),
            'name' => (string) ($group?->name ?? ''),
            'headline' => (string) ($group?->headline ?? ''),
            'tagline' => (string) ($group?->tagline ?? ''),
        ];
    }

    /**
     * International dialling codes for the inline register form on the cart.
     */
    protected function phoneCodesForCart(): array
    {
        return \Illuminate\Support\Facades\DB::table('sms_country')
            ->orderBy('num_code')
            ->get(['iso', 'name', 'name_zh', 'phone_code'])
            ->map(fn ($row) => [
                'phone_code' => (string) $row->phone_code,
                'link' => '+' . $row->phone_code,
                'iso' => (string) $row->iso,
                'name' => (string) ($row->name_zh ?: $row->name),
            ])
            ->all();
    }

    /**
     * `configoption[<id>]` input -> PricingService selection shape.
     */
    protected function normaliseSelections(array $selections): array
    {
        $normalised = [];

        foreach ($selections as $optionId => $value) {
            $option = ProductConfigOption::query()->find((int) $optionId);

            if ($option === null) {
                continue;
            }

            $qty = 1;

            // Quantity options post `configoption[<id>][value]` + `[qty]`.
            if (is_array($value) && array_key_exists('value', $value)) {
                $qty = max(1, (int) ($value['qty'] ?? 1));
                $value = $value['value'];
            }

            $normalised[] = [
                'option' => (int) $optionId,
                'value' => $option->option_type === ProductConfigOption::TYPE_QUANTITY ? (int) $value : $value,
                'qty' => $qty,
            ];
        }

        return $normalised;
    }

    /**
     * Breakdown rows for the order summary fragment.
     */
    protected function summaryChildren(Product $product, array $selections, string $cycle): array
    {
        $rows = [];

        foreach ($selections as $selection) {
            $option = ProductConfigOption::query()->find((int) ($selection['option'] ?? 0));

            if ($option === null) {
                continue;
            }

            $values = is_array($selection['value']) ? $selection['value'] : [$selection['value']];

            foreach ($values as $subId) {
                $sub = ProductConfigOptionSub::query()->find((int) $subId);

                if ($sub === null) {
                    continue;
                }

                $rows[] = [
                    'option_name' => (string) $option->option_name,
                    'option_type' => (int) $option->option_type,
                    'sub_name' => (string) $sub->option_name,
                    'qty' => (int) ($selection['qty'] ?? 1),
                    'suboption_price' => number_format($this->subPrice($sub, $cycle), 2, '.', ''),
                    'suboption_setup_fee' => '0.00',
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether a promo code's product restriction covers this cart.
     */
    protected function promoApplies(PromoCode $promo, CartSession $cart): bool
    {
        $appliesto = trim((string) $promo->appliesto);

        if ($appliesto === '' || $appliesto === '0') {
            return true;
        }

        $allowed = array_filter(array_map('intval', preg_split('/[\s,]+/', $appliesto) ?: []));
        $productIds = array_map(fn ($item) => (int) ($item['productid'] ?? 0), $this->store->items($cart));

        return array_intersect($allowed, $productIds) !== [];
    }

    protected function promoDescription(PromoCode $promo): string
    {
        $type = (string) $promo->type === PromoCode::TYPE_PERCENTAGE ? '折扣' : '立减';

        return sprintf(
            '优惠码 %s（%s %.2f）',
            (string) $promo->code,
            $type,
            (float) $promo->value
        );
    }

}
