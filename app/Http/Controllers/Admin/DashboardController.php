<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Ticket;
use App\Services\Admin\AdminMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 首页 / 统计 — the dashboard cards and the statistics pages (年度统计, 新增客户,
 * 产品收入, 收入排行, 销售业绩).
 */
class DashboardController extends AdminController
{
    /**
     * `GET ad_index` / `GET index` — the dashboard summary.
     */
    public function index(Request $request)
    {
        $today = strtotime('today');
        $month = strtotime('first day of this month midnight');
        $year = strtotime('first day of january this year midnight');

        $revenue = fn (?int $from = null) => (float) DB::table('accounts')
            ->whereNull('delete_time')
            ->when($from !== null, fn ($q) => $q->where('create_time', '>=', $from))
            ->sum('amount_in');

        $orders = fn (?int $from = null) => Order::query()
            ->whereNull('delete_time')
            ->when($from !== null, fn ($q) => $q->where('create_time', '>=', $from))
            ->count();

        return $this->ok([
            'revenue' => [
                'today' => $this->money($revenue($today)),
                'month' => $this->money($revenue($month)),
                'year' => $this->money($revenue($year)),
                'total' => $this->money($revenue()),
            ],
            'orders' => [
                'today' => $orders($today),
                'month' => $orders($month),
                'year' => $orders($year),
                'total' => $orders(),
                'pending' => Order::query()->where('status', 'Pending')->count(),
            ],
            'clients' => [
                'today' => Client::query()->where('create_time', '>=', $today)->count(),
                'month' => Client::query()->where('create_time', '>=', $month)->count(),
                'year' => Client::query()->where('create_time', '>=', $year)->count(),
                'total' => Client::query()->count(),
                'active' => Client::query()->where('status', 1)->count(),
            ],
            'hosts' => [
                'total' => Host::query()->count(),
                'active' => Host::query()->where('domainstatus', 'Active')->count(),
                'pending' => Host::query()->where('domainstatus', 'Pending')->count(),
                'suspended' => Host::query()->where('domainstatus', 'Suspended')->count(),
                'expiring' => Host::query()
                    ->whereIn('domainstatus', Host::LIVE_STATUSES)
                    ->whereBetween('nextduedate', [time(), time() + 7 * 86400])
                    ->count(),
            ],
            'tickets' => [
                'total' => Ticket::query()->count(),
                'open' => Ticket::query()->whereIn('status', $this->openStatusIds())->count(),
                'today' => Ticket::query()->where('create_time', '>=', $today)->count(),
            ],
            'finance' => [
                'unpaid_invoices' => Invoice::query()->whereIn('status', ['Unpaid', 'Overdue'])->count(),
                'unpaid_amount' => $this->money((float) Invoice::query()->whereIn('status', ['Unpaid', 'Overdue'])->sum('total')),
                'paid_invoices' => Invoice::query()->where('status', 'Paid')->count(),
                'refunded' => $this->money((float) DB::table('accounts')->where('refund', 1)->sum('amount_out')),
            ],
            'recent_orders' => $this->recentOrders(),
            'recent_tickets' => $this->recentTickets(),
            'sale' => AdminMeta::admins(),
        ], '请求成功');
    }

    /**
     * `GET sale/sale_statistics` — 我的业绩 / 销售统计.
     */
    public function saleStatistics(Request $request)
    {
        [$start, $end] = $this->range($request);

        $saleId = (int) $request->input('sale_id', 0);

        $query = Order::query()
            ->whereNull('delete_time')
            ->whereBetween('create_time', [$start, $end]);

        if ($saleId > 0) {
            $uids = Client::query()->where('sale_id', $saleId)->pluck('id')->all();
            $query->whereIn('uid', $uids ?: [-1]);
        }

        $orders = $query->get(['id', 'uid', 'amount', 'create_time', 'status', 'payment']);

        $uids = $orders->pluck('uid')->unique()->all();
        $saleByClient = Client::query()->whereIn('id', $uids)->pluck('sale_id', 'id');

        $bySale = [];

        foreach ($orders as $order) {
            $sid = (int) ($saleByClient[$order->uid] ?? 0);
            $bySale[$sid] ??= ['sale_id' => $sid, 'order_count' => 0, 'amount' => 0.0];
            $bySale[$sid]['order_count']++;
            $bySale[$sid]['amount'] += (float) $order->amount;
        }

        $staff = \App\Models\User::query()->pluck('user_nickname', 'id');

        $rows = array_map(function (array $row) use ($staff) {
            $row['amount'] = $this->money($row['amount']);
            $row['user_nickname'] = (string) ($staff[$row['sale_id']] ?? '未分配');
            $row['bates'] = $this->saleBates($row['sale_id']);
            $row['commission'] = $this->money($row['amount'] * $row['bates'] / 100);

            return $row;
        }, array_values($bySale));

        return $this->ok([
            'list' => $rows,
            'total' => count($rows),
            'order_count' => $orders->count(),
            'amount' => $this->money((float) $orders->sum('amount')),
            'commission' => $this->money(array_sum(array_column($rows, 'commission'))),
            'range' => ['start' => $start, 'end' => $end],
        ], '请求成功');
    }

    /**
     * `GET sale/sale_users` — the salespeople and their take.
     */
    public function saleUsers(Request $request)
    {
        $staff = \App\Models\User::query()
            ->where('user_status', 1)
            ->get(['id', 'user_login', 'user_nickname', 'is_sale']);

        $rows = $staff->map(function ($user) {
            $uids = Client::query()->where('sale_id', $user->id)->pluck('id')->all();

            return [
                'id' => (int) $user->id,
                'user_login' => $user->user_login,
                'user_nickname' => $user->user_nickname,
                'is_sale' => (int) $user->is_sale,
                'client_count' => count($uids),
                'order_count' => $uids === [] ? 0 : Order::query()->whereIn('uid', $uids)->whereNull('delete_time')->count(),
                'amount' => $uids === [] ? 0 : $this->money((float) Order::query()->whereIn('uid', $uids)->whereNull('delete_time')->sum('amount')),
            ];
        })->all();

        return $this->ok($rows);
    }

    /**
     * `GET annualstatistics` / `GET year_reports` — 年度统计.
     */
    public function annualStatistics(Request $request)
    {
        $year = (int) $request->input('year', (int) date('Y'));

        $start = mktime(0, 0, 0, 1, 1, $year);
        $end = mktime(23, 59, 59, 12, 31, $year);

        $revenue = DB::table('accounts')
            ->selectRaw('FROM_UNIXTIME(create_time, "%m") as month, SUM(amount_in) as amount_in, SUM(amount_out) as amount_out')
            ->whereNull('delete_time')
            ->whereBetween('create_time', [$start, $end])
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $orders = Order::query()
            ->selectRaw('FROM_UNIXTIME(create_time, "%m") as month, COUNT(*) as total, SUM(amount) as amount')
            ->whereNull('delete_time')
            ->whereBetween('create_time', [$start, $end])
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $clients = Client::query()
            ->selectRaw('FROM_UNIXTIME(create_time, "%m") as month, COUNT(*) as total')
            ->whereBetween('create_time', [$start, $end])
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $key = str_pad((string) $m, 2, '0', STR_PAD_LEFT);

            $months[] = [
                'month' => (int) $m,
                'label' => $m.'月',
                'amount_in' => $this->money((float) ($revenue[$key]->amount_in ?? 0)),
                'amount_out' => $this->money((float) ($revenue[$key]->amount_out ?? 0)),
                'order_count' => (int) ($orders[$key]->total ?? 0),
                'order_amount' => $this->money((float) ($orders[$key]->amount ?? 0)),
                'client_count' => (int) ($clients[$key]->total ?? 0),
            ];
        }

        return $this->ok([
            'year' => $year,
            'months' => $months,
            'total_amount_in' => $this->money((float) array_sum(array_column($months, 'amount_in'))),
            'total_amount_out' => $this->money((float) array_sum(array_column($months, 'amount_out'))),
            'total_orders' => (int) array_sum(array_column($months, 'order_count')),
            'total_clients' => (int) array_sum(array_column($months, 'client_count')),
        ], '请求成功');
    }

    /**
     * `GET year_reports_chart` — the same data in chart series form.
     */
    public function annualChart(Request $request)
    {
        $stats = $this->annualStatistics($request)->getData(true);

        return $this->ok([
            'x' => array_column($stats['data']['months'], 'label'),
            'series' => [
                ['name' => '收入', 'data' => array_column($stats['data']['months'], 'amount_in')],
                ['name' => '支出', 'data' => array_column($stats['data']['months'], 'amount_out')],
                ['name' => '订单数', 'data' => array_column($stats['data']['months'], 'order_count')],
                ['name' => '新增客户', 'data' => array_column($stats['data']['months'], 'client_count')],
            ],
        ], '请求成功');
    }

    /**
     * `GET newcustomer` — 新增客户统计.
     */
    public function newCustomer(Request $request)
    {
        [$start, $end] = $this->range($request, 30);

        $rows = Client::query()
            ->selectRaw('FROM_UNIXTIME(create_time, "%Y-%m-%d") as date, COUNT(*) as total')
            ->whereBetween('create_time', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $this->ok([
            'list' => $rows->map(fn ($r) => [
                'date' => $r->date,
                'total' => (int) $r->total,
            ])->all(),
            'total' => (int) $rows->sum('total'),
            'range' => ['start' => $start, 'end' => $end],
        ], '请求成功');
    }

    /**
     * `GET productrevenue` — 产品收入统计.
     */
    public function productRevenue(Request $request)
    {
        [$start, $end] = $this->range($request);

        $prefix = (string) config('database.connections.'.config('database.default').'.prefix', '');

        // A raw statement: the query builder rewrites identifiers inside
        // `selectRaw` with the table prefix, which breaks the join aliases.
        $rows = collect(DB::select(
            'SELECT COALESCE(pd.name, ii.description) AS productname,'
            .' COUNT(*) AS total, SUM(ii.amount) AS amount'
            .' FROM '.$prefix.'invoice_items ii'
            .' INNER JOIN '.$prefix.'invoices iv ON iv.id = ii.invoice_id'
            .' INNER JOIN '.$prefix.'host h ON h.id = ii.rel_id'
            .' LEFT JOIN '.$prefix.'products pd ON pd.id = h.productid'
            .' WHERE iv.status = ? AND ii.delete_time IS NULL AND iv.create_time BETWEEN ? AND ?'
            .' GROUP BY productname ORDER BY amount DESC',
            ['Paid', $start, $end],
        ));

        return $this->ok([
            'list' => $rows->map(fn ($r) => [
                'productname' => $r->productname,
                'total' => (int) $r->total,
                'amount' => $this->money((float) $r->amount),
            ])->all(),
            'range' => ['start' => $start, 'end' => $end],
        ], '请求成功');
    }

    /**
     * `GET revenueranking` — 收入排行 (top clients by paid amount).
     */
    public function revenueRanking(Request $request)
    {
        [$start, $end] = $this->range($request);

        $rows = DB::table('accounts')
            ->selectRaw('uid, SUM(amount_in) as amount_in, COUNT(*) as total')
            ->whereNull('delete_time')
            ->whereBetween('create_time', [$start, $end])
            ->groupBy('uid')
            ->orderByDesc('amount_in')
            ->limit((int) ($request->input('limit') ?: 50))
            ->get();

        $clients = Client::query()->whereIn('id', $rows->pluck('uid')->all())->get(['id', 'username', 'companyname'])->keyBy('id');

        return $this->ok([
            'list' => $rows->map(fn ($r) => [
                'uid' => (int) $r->uid,
                'username' => $clients[$r->uid]->username ?? '',
                'companyname' => $clients[$r->uid]->companyname ?? '',
                'amount_in' => $this->money((float) $r->amount_in),
                'total' => (int) $r->total,
            ])->all(),
            'range' => ['start' => $start, 'end' => $end],
        ], '请求成功');
    }

    /**
     * `GET product/income` — per-product profit (售价 - 成本).
     */
    public function productIncome(Request $request)
    {
        [$start, $end] = $this->range($request);

        $prefix = (string) config('database.connections.'.config('database.default').'.prefix', '');

        $rows = collect(DB::select(
            'SELECT h.productid, COALESCE(p.name, \'\') AS productname, COUNT(*) AS total, SUM(h.amount) AS amount'
            .' FROM '.$prefix.'host h'
            .' LEFT JOIN '.$prefix.'products p ON p.id = h.productid'
            .' WHERE h.create_time BETWEEN ? AND ?'
            .' GROUP BY h.productid, productname ORDER BY amount DESC',
            [$start, $end],
        ));

        $costRows = DB::select(
            'SELECT productid, SUM(CAST(COALESCE(NULLIF(upstream_cost, \'\'), 0) AS DECIMAL(10,2))) AS cost'
            .' FROM '.$prefix.'host WHERE create_time BETWEEN ? AND ? GROUP BY productid',
            [$start, $end],
        );

        $costs = collect($costRows)->pluck('cost', 'productid');

        return $this->ok([
            'list' => $rows->map(function ($r) use ($costs) {
                $amount = $this->money((float) $r->amount);
                $cost = $this->money((float) ($costs[$r->productid] ?? 0));

                return [
                    'productid' => (int) $r->productid,
                    'productname' => $r->productname,
                    'total' => (int) $r->total,
                    'amount' => $amount,
                    'cost' => $cost,
                    'profit' => $this->money($amount - $cost),
                ];
            })->all(),
            'range' => ['start' => $start, 'end' => $end],
        ], '请求成功');
    }

    /**
     * `GET order/saleorder` — orders attributable to the signed-in salesperson.
     */
    public function saleOrders(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $saleId = (int) $request->input('sale_id', $this->admin()->is_sale ? $this->adminId() : 0);

        $query = Order::query()->whereNull('delete_time');

        if ($saleId > 0) {
            $uids = Client::query()->where('sale_id', $saleId)->pluck('id')->all();
            $query->whereIn('uid', $uids ?: [-1]);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $clients = Client::query()->whereIn('id', $rows->pluck('uid')->all())->get()->keyBy('id');

        $list = $rows->map(fn (Order $o) => [
            'id' => (int) $o->id,
            'uid' => (int) $o->uid,
            'username' => $clients[$o->uid]->username ?? '',
            'amount' => $this->money((float) $o->amount),
            'create_time' => (int) $o->create_time,
            'status' => (string) $o->status,
            'status_zh' => AdminMeta::orderStatusLabel((string) $o->status),
            'payment' => $o->payment,
        ])->all();

        return $this->paginated($list, $total, $page, $limit);
    }

    /**
     * `GET report/base_info` — the platform's own health summary.
     */
    public function reportBaseInfo(Request $request)
    {
        return $this->ok([
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_time' => time(),
            'server_time_zh' => date('Y-m-d H:i:s'),
            'disk_free' => @disk_free_space(base_path()) ?: 0,
            'disk_total' => @disk_total_space(base_path()) ?: 0,
            'counts' => [
                'clients' => Client::query()->count(),
                'hosts' => Host::query()->count(),
                'orders' => Order::query()->count(),
                'invoices' => Invoice::query()->count(),
                'tickets' => Ticket::query()->count(),
                'products' => Product::query()->count(),
            ],
        ], '请求成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Resolve the `start_time`/`end_time` filter, defaulting to the last
     * `$defaultDays` days.
     *
     * @return array{0:int,1:int}
     */
    private function range(Request $request, int $defaultDays = 365): array
    {
        $start = $this->timestamp($request->input('start_time'))
            ?? $this->timestamp($request->input('start_date'))
            ?? strtotime('-'.$defaultDays.' days');

        $end = $this->timestamp($request->input('end_time'))
            ?? $this->timestamp($request->input('end_date'))
            ?? time();

        return [(int) $start, (int) $end];
    }

    /**
     * Status ids the panel treats as "open" — derived from the editable
     * status table rather than hard-coded.
     */
    private function openStatusIds(): array
    {
        $ids = DB::table('ticket_status')->where('show_await', 1)->pluck('id')->all();

        return $ids === [] ? [1, 3, 5] : array_map('intval', $ids);
    }

    private function recentOrders(): array
    {
        $rows = Order::query()->whereNull('delete_time')->orderByDesc('id')->limit(8)->get();
        $clients = Client::query()->whereIn('id', $rows->pluck('uid')->all())->pluck('username', 'id');

        return $rows->map(fn (Order $o) => [
            'id' => (int) $o->id,
            'username' => (string) ($clients[$o->uid] ?? ''),
            'amount' => $this->money((float) $o->amount),
            'status' => (string) $o->status,
            'status_zh' => AdminMeta::orderStatusLabel((string) $o->status),
            'create_time' => (int) $o->create_time,
        ])->all();
    }

    private function recentTickets(): array
    {
        $rows = Ticket::query()->orderByDesc('id')->limit(8)->get();
        $clients = Client::query()->whereIn('id', $rows->pluck('uid')->all())->pluck('username', 'id');
        $statuses = DB::table('ticket_status')->pluck('title', 'id');

        return $rows->map(fn (Ticket $t) => [
            'id' => (int) $t->id,
            'title' => $t->title,
            'username' => (string) ($clients[$t->uid] ?? ''),
            'status_title' => (string) ($statuses[$t->status] ?? ''),
            'create_time' => (int) $t->create_time,
        ])->all();
    }

    /**
     * Commission percentage for a salesperson.
     */
    private function saleBates(int $saleId): float
    {
        if ($saleId <= 0) {
            return 0.0;
        }

        $more = DB::table('user')->where('id', $saleId)->value('more');

        if ($more) {
            $decoded = json_decode((string) $more, true);

            if (is_array($decoded) && isset($decoded['bates'])) {
                return (float) $decoded['bates'];
            }
        }

        return (float) (DB::table('sales_product_groups')->orderBy('id')->value('bates') ?? 0);
    }
}
