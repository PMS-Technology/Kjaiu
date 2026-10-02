<?php

namespace App\Http\Controllers\Admin;

use App\Models\CartSession;
use App\Models\Client;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Services\CartService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 业务 → 产品订单 — the order list, order detail, order approval and the
 * "place an order on behalf of a client" flow.
 */
class OrderController extends AdminController
{
    /**
     * `GET orders` — the 产品订单 table.
     */
    public function index(Request $request)
    {
        return $this->search($request);
    }

    /**
     * `GET order/search` — the order list with its aggregates.
     *
     * The payload lives at the top level (`list`, `count`, `price_total`)
     * rather than under `data`, matching the original.
     */
    public function search(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, ['id', 'create_time', 'amount', 'status', 'pay_time'], 'id');

        $query = Order::query()->whereNull('delete_time');

        $this->applyFilters($query, $request);

        $total = (clone $query)->count();
        $priceTotal = (float) (clone $query)->sum('amount');

        $rows = $query->orderBy($orderBy, $sort)->forPage($page, $limit)->get();

        $list = $this->decorate($rows);

        $pageSum = array_sum(array_column($list, 'amount'));

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'count' => $total,
            'total' => $total,
            'price_total' => $this->money($priceTotal),
            'price_total_page' => $this->money($pageSum),
            'page' => $page,
            'limit' => $limit,
            'tabsSearch' => $this->tabs(),
        ]);
    }

    /**
     * `GET order/search_page` — the filter widgets and status tabs.
     */
    public function searchPage(Request $request)
    {
        return $this->ok([
            'status' => AdminMeta::ORDER_STATUS,
            'tabsSearch' => $this->tabs(),
            'payment' => AdminMeta::gateways(),
            'sale' => AdminMeta::admins(),
            'search' => [
                'id' => '订单ID',
                'username' => '客户',
                'sale_id' => '销售',
                'pay_status' => '付款状态',
                'time' => '时间',
                'status' => '状态',
                'amount' => '金额',
                'payment' => '付款方式',
            ],
        ]);
    }

    /**
     * `GET order/getclients {username}` — the customer autocomplete.
     */
    public function getClients(Request $request)
    {
        $username = trim((string) $request->input('username', $request->input('query', '')));

        $rows = Client::query()
            ->when($username !== '', function ($q) use ($username) {
                $q->where(function ($w) use ($username) {
                    $w->where('username', 'like', "%{$username}%")
                        ->orWhere('email', 'like', "%{$username}%")
                        ->orWhere('phonenumber', 'like', "%{$username}%")
                        ->orWhere('companyname', 'like', "%{$username}%");
                });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'username', 'email', 'companyname', 'credit']);

        return $this->ok($rows->map(fn (Client $c) => [
            'id' => (int) $c->id,
            'uid' => (int) $c->id,
            'value' => $c->username ?: $c->email,
            'username' => $c->username,
            'email' => $c->email,
            'companyname' => $c->companyname,
            'credit' => $this->money((float) $c->credit),
        ])->all());
    }

    /**
     * `GET orderdetail {id}` — one order with its services, invoice and items.
     */
    public function detail(Request $request)
    {
        $id = (int) $request->input('id', $request->route('id') ?? 0);

        $order = Order::query()->find($id);

        if ($order === null) {
            return $this->notFound('订单不存在');
        }

        $client = Client::query()->find($order->uid);

        $hosts = Host::query()->where('orderid', $order->id)->get()->map(fn (Host $h) => [
            'id' => (int) $h->id,
            'productname' => $h->product?->name,
            'domain' => $h->domain,
            'dedicatedip' => $h->dedicatedip,
            'billingcycle' => $h->billingcycle,
            'billingcycle_zh' => AdminMeta::BILLING_CYCLES[(string) $h->billingcycle] ?? (string) $h->billingcycle,
            'amount' => $this->money((float) $h->amount),
            'regdate' => (int) $h->regdate,
            'nextduedate' => (int) $h->nextduedate,
            'domainstatus' => (string) $h->domainstatus,
            'domainstatus_zh' => AdminMeta::domainStatusLabel((string) $h->domainstatus),
        ])->all();

        $invoice = $order->invoiceid ? Invoice::query()->find($order->invoiceid) : null;

        return $this->ok([
            'order' => $this->orderRow($order, $client),
            'client' => $client ? (array) $client->toArray() : null,
            'hosts' => $hosts,
            'invoice' => $invoice ? (array) $invoice->toArray() : null,
            'order_status' => AdminMeta::ORDER_STATUS,
            'payment' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `POST order/order_commission` — commission totals for the summary row.
     */
    public function commission(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        $query = Order::query()->whereNull('delete_time');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $this->applyFilters($query, $request);
        }

        $orders = $query->get(['id', 'uid', 'amount', 'create_time', 'status']);

        $sum = 0.0;
        $detail = [];

        foreach ($orders as $order) {
            $client = Client::query()->find($order->uid);
            $saleId = (int) ($client->sale_id ?? 0);
            $bates = $saleId > 0 ? $this->saleBates($saleId) : 0.0;
            $amount = $this->money((float) $order->amount * $bates / 100);

            $sum += $amount;

            $detail[] = [
                'id' => (int) $order->id,
                'sale_id' => $saleId,
                'amount' => $this->money((float) $order->amount),
                'commission' => $amount,
            ];
        }

        return $this->ok([
            'sum' => $this->money($sum),
            'total' => $this->money($sum),
            'list' => $detail,
        ], '请求成功');
    }

    /**
     * `GET order/check {id}` — 审核通过 / 接受订单.
     */
    public function check(Request $request)
    {
        $id = (int) $request->input('id', $request->route('id') ?? 0);

        $order = Order::query()->find($id);

        if ($order === null) {
            return $this->notFound('订单不存在');
        }

        if ((string) $order->status !== 'Pending') {
            return $this->fail('该订单状态不允许审核');
        }

        DB::transaction(function () use ($order) {
            $order->status = 'Active';
            $order->update_time = time();
            $order->save();

            // Accepting the order also settles its invoice and activates the
            // services it created.
            if ($order->invoiceid) {
                $invoice = Invoice::query()->find($order->invoiceid);

                if ($invoice !== null && (string) $invoice->status !== 'Paid') {
                    // Only auto-pay when the customer has the balance for it.
                    app(InvoiceService::class)->payWithCredit($invoice);
                }
            }

            $hosts = Host::query()->where('orderid', $order->id)->get();

            foreach ($hosts as $host) {
                if ((string) $host->domainstatus === 'Pending') {
                    $host->domainstatus = 'Active';
                    $host->update_time = time();
                    $host->save();
                }
            }
        });

        $this->log('审核订单 #'.$order->id, (int) $order->id);

        return $this->ok(['id' => (int) $order->id], '操作成功');
    }

    /**
     * `GET order/cancel {id}` — cancel an order and its pending services.
     */
    public function cancel(Request $request)
    {
        $id = (int) $request->input('id', $request->route('id') ?? 0);

        $order = Order::query()->find($id);

        if ($order === null) {
            return $this->notFound('订单不存在');
        }

        if ((string) $order->status === 'Cancelled') {
            return $this->fail('该订单已取消');
        }

        DB::transaction(function () use ($order) {
            $order->status = 'Cancelled';
            $order->update_time = time();
            $order->save();

            Host::query()->where('orderid', $order->id)->where('domainstatus', 'Pending')->update([
                'domainstatus' => 'Cancelled',
                'update_time' => time(),
            ]);

            if ($order->invoiceid) {
                $invoice = Invoice::query()->find($order->invoiceid);

                if ($invoice !== null && (string) $invoice->status === 'Unpaid') {
                    $invoice->status = 'Cancelled';
                    $invoice->update_time = time();
                    $invoice->save();
                }
            }
        });

        $this->log('取消订单 #'.$order->id, (int) $order->id);

        return $this->ok(['id' => (int) $order->id], '取消成功');
    }

    /**
     * `POST orders/change_status` — bulk status change.
     */
    public function changeStatus(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);
        $status = (string) $request->input('status', '');

        if ($ids === [] || $status === '') {
            return $this->validationFail('参数错误');
        }

        if (! isset(AdminMeta::ORDER_STATUS[$status])) {
            return $this->validationFail('状态值错误');
        }

        Order::query()->whereIn('id', $ids)->update([
            'status' => $status,
            'update_time' => time(),
        ]);

        $this->log('批量修改订单状态：'.implode(',', $ids).' → '.$status);

        return $this->ok(['count' => count($ids)], '操作成功');
    }

    /**
     * `POST orders/notes` — save the admin note on an order.
     */
    public function notes(Request $request)
    {
        $order = Order::query()->find((int) $request->input('id', 0));

        if ($order === null) {
            return $this->notFound('订单不存在');
        }

        $order->notes = (string) $request->input('notes', $request->input('order_notes', ''));
        $order->update_time = time();
        $order->save();

        $this->log('修改订单备注 #'.$order->id, (int) $order->id);

        return $this->ok(['id' => (int) $order->id], '保存成功');
    }

    /**
     * `DELETE orders/delete {id}` — soft delete via `delete_time`, as the
     * schema's `shd_orders.delete_time` intends.
     */
    public function delete(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('请选择要删除的订单');
        }

        // Orders that produced live services must not disappear.
        $blocked = Host::query()
            ->whereIn('orderid', $ids)
            ->whereIn('domainstatus', Host::LIVE_STATUSES)
            ->pluck('orderid')
            ->unique()
            ->all();

        $deletable = array_values(array_diff($ids, $blocked));

        if ($deletable === []) {
            return $this->fail('所选订单下还有产品/服务，不能删除');
        }

        Order::query()->whereIn('id', $deletable)->update(['delete_time' => time()]);

        $this->log('删除订单：'.implode(',', $deletable));

        $msg = $blocked === [] ? '删除成功' : '部分订单因存在产品/服务未能删除';

        return $this->ok(['deleted' => $deletable, 'blocked' => $blocked], $msg);
    }

    /**
     * `GET|POST order/getTotal` / `POST get_total` — price preview for the
     * 新建订单 form.
     */
    public function getTotal(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $items = $request->input('products', $request->input('product', []));

        if (! is_array($items)) {
            $items = [];
        }

        $pricing = app(PricingService::class);
        $total = 0.0;
        $lines = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = Product::query()->find((int) ($item['id'] ?? 0));

            if ($product === null) {
                continue;
            }

            $cycle = (string) ($item['cycle'] ?? 'monthly');
            $qty = max(1, (int) ($item['qty'] ?? 1));

            $base = (float) ($pricing->cyclePrice($product, $cycle) ?? 0);
            $setup = $pricing->setupFee($product, $cycle);

            $interior = isset($item['interior_price']) && $item['interior_price'] !== '' && $item['interior_price'] !== null
                ? (float) $item['interior_price']
                : null;

            $unit = $interior ?? $base;

            $configTotal = $pricing->configOptionsTotal($product, $this->normaliseConfigOptions($item['configoption'] ?? []), $cycle);

            $line = $this->money(($unit * $qty) + $setup + $configTotal);
            $total += $line;

            $lines[] = [
                'id' => (int) $product->id,
                'name' => $product->name,
                'cycle' => $cycle,
                'qty' => $qty,
                'amount' => $line,
            ];
        }

        $promoCode = trim((string) $request->input('promo_code', ''));
        $discount = 0.0;

        if ($promoCode !== '') {
            $promo = PromoCode::query()->where('code', $promoCode)->first();

            if ($promo !== null) {
                $discount = $pricing->applyPromo($total, $promo);
            }
        }

        $client = $uid > 0 ? Client::query()->find($uid) : null;
        $groupDiscount = $client ? $pricing->groupDiscount(0, null) : 0.0;
        unset($groupDiscount);

        $total = max(0, $total - $discount);

        if ($client !== null && $client->group !== null) {
            $total = $pricing->groupDiscount($total, $client->group->discount_percent);
        }

        return $this->ok([
            'total' => $this->money($total),
            'subtotal' => $this->money($total + $discount),
            'discount' => $this->money($discount),
            'lines' => $lines,
            'credit' => $client ? $this->money((float) $client->credit) : 0,
        ], '请求成功');
    }

    /**
     * `GET order/create_page` — 为客户下单 form metadata.
     */
    public function createPage(Request $request)
    {
        $products = Product::query()
            ->where('hidden', 0)
            ->where('retired', 0)
            ->orderBy('order')
            ->get(['id', 'name', 'gid', 'type', 'pay_type', 'allow_qty'])
            ->map(function (Product $product) {
                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'gid' => (int) $product->gid,
                    'type' => $product->type,
                    'type_zh' => AdminMeta::productTypeLabel($product->type),
                    'pay_type' => AdminMeta::productPayLabel($product),
                    'allow_qty' => (int) $product->allow_qty,
                    'pricing' => DB::table('pricing')
                        ->where('type', 'product')
                        ->where('relid', $product->id)
                        ->get()
                        ->map(fn ($p) => (array) $p)
                        ->all(),
                ];
            })
            ->all();

        return $this->ok([
            'products' => $products,
            'client_groups' => AdminMeta::clientGroups(),
            'gateway' => AdminMeta::gateways(),
            'currencies' => AdminMeta::currencies(),
            'order_status' => AdminMeta::ORDER_STATUS,
            'cycles' => AdminMeta::BILLING_CYCLES,
            'sale' => AdminMeta::admins(),
        ]);
    }

    /**
     * `POST order/create` — place an order on behalf of a client.
     */
    public function create(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $items = $request->input('products', []);

        if (! is_array($items) || $items === []) {
            return $this->validationFail('请选择产品');
        }

        $cartService = app(CartService::class);
        $cart = $cartService->current($client);

        $cartService->clear($cart);

        $added = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = Product::query()->find((int) ($item['id'] ?? 0));

            if ($product === null) {
                continue;
            }

            $configuration = [
                'cycle' => (string) ($item['cycle'] ?? 'monthly'),
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                // `configoption` carries the per-option selections; the cart
                // stores them under `configoptions` and PricingService expects
                // `{option, value}` pairs.
                'configoptions' => $this->normaliseConfigOptions($item['configoption'] ?? $item['configoptions'] ?? []),
                'custom' => (array) ($item['custom'] ?? []),
                'host' => (string) ($item['domain'] ?? $item['host'] ?? ''),
                'password' => (string) ($item['password'] ?? ''),
            ];

            try {
                $cartService->addProduct($cart, $product, $configuration);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($product->name.'：'.$e->getMessage());
            }

            $added++;
        }

        if ($added === 0) {
            return $this->validationFail('请选择产品');
        }

        $cart = $cartService->current($client);

        try {
            $result = app(OrderService::class)->place($client, $cart, [
                'promo' => (string) $request->input('promo_code', ''),
                'gateway' => (string) $request->input('payment', ''),
                'notes' => (string) $request->input('notes', ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $order = $result['order'];
        $invoice = $result['invoice'];

        // The order the admin places is trusted, so it skips the Pending gate
        // unless the product asks for manual approval.
        if ((string) $request->input('status', '') === 'Active') {
            $order->status = 'Active';
            $order->update_time = time();
            $order->save();
        }

        $this->log('为客户'.$client->username.'下单 #'.$order->id, (int) $order->id);

        return $this->ok([
            'id' => (int) $order->id,
            'orderid' => (int) $order->id,
            'invoiceid' => (int) $invoice->id,
            'amount' => $this->money((float) $invoice->total),
        ], '下单成功');
    }

    /**
     * `GET order/promo_code_page` — the promo-code picker dialog.
     */
    public function promoCodePage(Request $request)
    {
        $rows = PromoCode::query()
            ->where(function ($q) {
                $q->whereNull('expiration_time')->orWhere('expiration_time', 0)->orWhere('expiration_time', '>', time());
            })
            ->orderByDesc('id')
            ->get();

        return $this->ok([
            'promo_code' => $rows->toArray(),
            'type' => AdminMeta::PROMO_TYPES,
        ]);
    }

    /**
     * `POST order/save_promo_code` — attach a promo code to the order form.
     */
    public function savePromoCode(Request $request)
    {
        $code = trim((string) $request->input('promo_code', $request->input('code', '')));

        $promo = PromoCode::query()->where('code', $code)->first();

        if ($promo === null) {
            return $this->fail('优惠码无效');
        }

        if ((int) $promo->max_times > 0 && (int) $promo->used >= (int) $promo->max_times) {
            return $this->fail('优惠码已用完');
        }

        if ((int) $promo->expiration_time > 0 && (int) $promo->expiration_time < time()) {
            return $this->fail('优惠码已过期');
        }

        return $this->ok([
            'id' => (int) $promo->id,
            'code' => $promo->code,
            'type' => $promo->type,
            'value' => $this->money((float) $promo->value),
        ], '请求成功');
    }

    /**
     * `GET auto_promo_code` — generate a random, unused code.
     */
    public function autoPromoCode(Request $request)
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
        } while (PromoCode::query()->where('code', $code)->exists());

        return $this->ok(['code' => $code, 'rand' => $code], '请求成功');
    }

    /**
     * `GET orders/set_config {pid}` — the config options of a chosen product.
     */
    public function setConfig(Request $request)
    {
        $product = Product::query()->find((int) $request->input('pid', 0));

        if ($product === null) {
            return $this->notFound('商品不存在');
        }

        $groups = app(PricingService::class)->productConfigGroups($product);

        return $this->ok([
            'config' => $groups,
            'configoption' => $groups,
            'pricing' => DB::table('pricing')->where('type', 'product')->where('relid', $product->id)->get()->map(fn ($p) => (array) $p)->all(),
            'product' => (array) $product->toArray(),
            'cycles' => AdminMeta::BILLING_CYCLES,
        ]);
    }

    /**
     * `GET trafficorder` — 流量订单 (overage billing) tab.
     */
    public function trafficOrder(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $rows = DB::table('dcim_flow_packet')
            ->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return $this->paginated($rows, DB::table('dcim_flow_packet')->count(), $page, $limit);
    }

    // -----------------------------------------------------------------
    // 产品暂停请求
    // -----------------------------------------------------------------

    /**
     * `GET request_cancel_list` — 产品暂停/取消请求.
     */
    public function cancelRequestList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('cancel_requests')
            ->leftJoin('host as h', 'h.id', '=', 'cancel_requests.relid')
            ->leftJoin('clients as c', 'c.id', '=', 'h.uid')
            ->select('cancel_requests.id', 'cancel_requests.relid', 'cancel_requests.type', 'cancel_requests.reason', 'cancel_requests.create_time', 'cancel_requests.update_time', 'cancel_requests.delete_time', 'cancel_requests.status', 'h.domain', 'h.dedicatedip', 'h.domainstatus', 'h.nextduedate', 'c.username')
            ->whereNull('cancel_requests.delete_time');

        if (($reason = trim((string) $request->input('reason', ''))) !== '') {
            $query->where('cancel_requests.reason', 'like', "%{$reason}%");
        }

        if ($status = $request->input('status')) {
            $status === 'ALL' ?: $query->where('cancel_requests.status', (int) $status);
        }

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('cancel_requests.id')->forPage($page, $limit)->get()->map(function ($row) {
            $entry = (array) $row;
            $entry['type_zh'] = $row->type === 'Immediate' ? '立即' : '到期';
            $entry['domainstatus_zh'] = AdminMeta::domainStatusLabel($row->domainstatus);
            $entry['cancel_status'] = (int) $row->status;

            return $entry;
        })->all();

        return $this->paginated($rows, $total, $page, $limit, [
            'cancelReasonOptions' => DB::table('cancel_reason')->get()->toArray(),
        ]);
    }

    /**
     * `DELETE request_cancel_list/<id>` — dismiss a request.
     */
    public function cancelRequestDelete(Request $request, $id)
    {
        DB::table('cancel_requests')->where('id', (int) $id)->update(['delete_time' => time()]);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET request_cancel_reason` — the canned reason list.
     */
    public function cancelReasons(Request $request)
    {
        return $this->ok(DB::table('cancel_reason')->get()->toArray());
    }

    /**
     * `POST request_cancel_reason` — add a canned reason.
     */
    public function cancelReasonSave(Request $request)
    {
        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            return $this->validationFail('原因不能为空');
        }

        $id = (int) DB::table('cancel_reason')->insertGetId(['reason' => $reason]);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `POST request_cancel_reason_post` — accept/reject a cancel request.
     */
    public function cancelReasonUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $status = (int) $request->input('status', 0);

        $row = DB::table('cancel_requests')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('请求不存在');
        }

        DB::transaction(function () use ($row, $status) {
            DB::table('cancel_requests')->where('id', $row->id)->update([
                'status' => $status,
                'update_time' => time(),
            ]);

            if ($status === 1) {
                // Approved: suspend or cancel the service depending on the
                // request type (`Immediate` vs end-of-cycle).
                $host = Host::query()->find($row->relid);

                if ($host !== null && $row->type === 'Immediate') {
                    $host->domainstatus = 'Cancelled';
                    $host->termination_date = time();
                    $host->update_time = time();
                    $host->save();
                }
            }
        });

        $this->log('处理产品取消请求 #'.$row->id, (int) $row->relid);

        return $this->ok(['id' => $id], '操作成功');
    }

    /**
     * `DELETE request_cancel_reason/<id>`
     */
    public function cancelReasonDelete(Request $request, $id)
    {
        DB::table('cancel_reason')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST searchfornamelist` (order flavour) — advanced order search.
     */
    public function searchForNameList(Request $request)
    {
        return $this->search($request);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Normalise the config-option selections into the `{option, value}` pairs
     * PricingService expects, accepting both the flat and nested shapes the
     * SPA and the API post.
     *
     * @return array<int,array{option:int,value:mixed}>
     */
    private function normaliseConfigOptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $value) {
            if (is_array($value) && isset($value['option'])) {
                $out[] = [
                    'option' => (int) $value['option'],
                    'value' => $value['value'] ?? [],
                    'qty' => (int) ($value['qty'] ?? 1),
                ];

                continue;
            }

            // `{optionId: subId|subId[]}` — the shape the order form posts.
            if (is_numeric($key)) {
                $out[] = [
                    'option' => (int) $key,
                    'value' => $value,
                    'qty' => 1,
                ];
            }
        }

        return $out;
    }

    private function applyFilters($query, Request $request): void
    {
        if ($id = $request->input('id')) {
            $query->where('id', (int) $id);
        }

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        $username = trim((string) $request->input('username', ''));

        if ($username !== '') {
            $uids = Client::query()
                ->where(function ($q) use ($username) {
                    $q->where('username', 'like', "%{$username}%")
                        ->orWhere('email', 'like', "%{$username}%")
                        ->orWhere('companyname', 'like', "%{$username}%");
                })
                ->pluck('id')
                ->all();

            $query->whereIn('uid', $uids ?: [-1]);
        }

        if ($saleId = $request->input('sale_id')) {
            $uids = Client::query()->where('sale_id', (int) $saleId)->pluck('id')->all();
            $query->whereIn('uid', $uids ?: [-1]);
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        if (($payment = $request->input('payment')) !== null && $payment !== '' && $payment !== 'ALL') {
            $query->where('payment', $payment);
        }

        if (($amount = $request->input('amount')) !== null && $amount !== '') {
            $query->where('amount', (float) $amount);
        }

        if (($payStatus = $request->input('pay_status')) !== null && $payStatus !== '' && $payStatus !== 'ALL') {
            $query->whereIn('invoiceid', Invoice::query()
                ->when($payStatus === 'Paid', fn ($q) => $q->where('status', 'Paid'))
                ->when($payStatus === 'Unpaid', fn ($q) => $q->whereIn('status', ['Unpaid', 'Overdue']))
                ->pluck('id')
                ->all() ?: [-1]);
        }

        $time = $request->input('time', $request->input('searchTime'));

        if (is_array($time)) {
            [$start, $end] = $this->timeRange($time);

            if ($start) {
                $query->where('create_time', '>=', $start);
            }

            if ($end) {
                $query->where('create_time', '<=', $end);
            }
        }
    }

    /**
     * Decorate order rows with the joined columns the table renders.
     */
    private function decorate($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $uids = $rows->pluck('uid')->unique()->all();

        $clients = Client::query()->whereIn('id', $uids)->get()->keyBy('id');
        $staff = \App\Models\User::query()->pluck('user_nickname', 'id');

        $orderIds = $rows->pluck('id')->all();

        $hosts = DB::table('host')
            ->join('products', 'products.id', '=', 'host.productid')
            ->whereIn('host.orderid', $orderIds)
            ->get(['host.id', 'host.orderid', 'host.domain', 'host.dedicatedip', 'host.domainstatus', 'products.name as productname'])
            ->groupBy('orderid');

        $invoices = Invoice::query()->whereIn('id', $rows->pluck('invoiceid')->filter()->all())->get()->keyBy('id');

        return $rows->map(function (Order $order) use ($clients, $staff, $hosts, $invoices) {
            $client = $clients[$order->uid] ?? null;
            $invoice = $invoices[$order->invoiceid] ?? null;
            $orderHosts = $hosts[$order->id] ?? collect();

            return [
                'id' => (int) $order->id,
                'uid' => (int) $order->uid,
                'ordernum' => $order->ordernum,
                'username' => $client?->username,
                'companyname' => $client?->companyname,
                'create_time' => (int) $order->create_time,
                'pay_time' => (int) $order->pay_time,
                'amount' => $this->money((float) $order->amount),
                'payment' => $order->payment,
                'status' => (string) $order->status,
                'status_zh' => AdminMeta::orderStatusLabel((string) $order->status),
                'order_notes' => $order->notes,
                'invoiceid' => (int) $order->invoiceid,
                'pay_status' => $invoice ? (string) $invoice->status : '',
                'pay_status_zh' => $invoice ? AdminMeta::invoiceStatusLabel((string) $invoice->status) : '',
                'promo_code' => $order->promo_code,
                'sale_id' => (int) ($client->sale_id ?? 0),
                'user_nickname' => (string) ($staff[$client->sale_id ?? 0] ?? ''),
                'sum' => $this->money($this->saleCommission($client, (float) $order->amount)),
                'hosts' => $orderHosts->map(fn ($h) => [
                    'id' => (int) $h->id,
                    'productname' => $h->productname,
                    'domain' => $h->domain,
                    'dedicatedip' => $h->dedicatedip,
                    'domainstatus' => $h->domainstatus,
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * The `list` shape shared by the detail endpoint.
     */
    private function orderRow(Order $order, ?Client $client): array
    {
        return [
            'id' => (int) $order->id,
            'uid' => (int) $order->uid,
            'ordernum' => $order->ordernum,
            'username' => $client?->username,
            'create_time' => (int) $order->create_time,
            'pay_time' => (int) $order->pay_time,
            'amount' => $this->money((float) $order->amount),
            'payment' => $order->payment,
            'status' => (string) $order->status,
            'status_zh' => AdminMeta::orderStatusLabel((string) $order->status),
            'order_notes' => $order->notes,
            'invoiceid' => (int) $order->invoiceid,
            'promo_code' => $order->promo_code,
            'promo_value' => $this->money((float) $order->promo_value),
            'sale_id' => (int) ($client->sale_id ?? 0),
        ];
    }

    /**
     * The status tabs above the order table.
     */
    private function tabs(): array
    {
        $tabs = [['label' => '全部', 'value' => 'ALL']];

        foreach (AdminMeta::ORDER_STATUS as $value => $meta) {
            $tabs[] = ['label' => $meta['name'], 'value' => $value];
        }

        return $tabs;
    }

    /**
     * Commission percentage for a salesperson from `shd_sales_product_groups`.
     */
    private function saleBates(int $saleId): float
    {
        static $cache = [];

        if (isset($cache[$saleId])) {
            return $cache[$saleId];
        }

        $group = DB::table('user')->where('id', $saleId)->value('more');

        $bates = 0.0;

        if ($group) {
            $decoded = json_decode((string) $group, true);

            if (is_array($decoded) && isset($decoded['bates'])) {
                $bates = (float) $decoded['bates'];
            }
        }

        if ($bates === 0.0) {
            $bates = (float) (DB::table('sales_product_groups')->orderBy('id')->value('bates') ?? 0);
        }

        return $cache[$saleId] = $bates;
    }

    private function saleCommission(?Client $client, float $amount): float
    {
        if ($client === null || ! $client->sale_id) {
            return 0.0;
        }

        return $this->money($amount * $this->saleBates((int) $client->sale_id) / 100);
    }
}
