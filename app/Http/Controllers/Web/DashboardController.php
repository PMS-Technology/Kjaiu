<?php

namespace App\Http\Controllers\Web;

use App\Models\Host;
use App\Models\Invoice;
use App\Models\ProductGroup;
use App\Models\Ticket;
use App\Services\PricingService;
use App\Support\Settings;
use App\Support\StatusMap;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client-area dashboard (`/clientarea`).
 *
 * The page renders its own identity / finance / announcement cards and pulls
 * the resource list in through `GET /clientarea?action=list`, which returns an
 * HTML fragment rather than JSON — the original injects it with `.html(data)`.
 */
class DashboardController extends WebController
{
    /**
     * 待处理工单 / 未支付订单 / 产品数量 / 本月消费 / 余额.
     */
    public function index(Request $request): View
    {
        $client = $this->requireClient();

        return view('web.clientarea.index', array_merge($this->shared(), [
            'Title' => '用户中心',
            'TplName' => 'clientarea',
            'ClientArea' => [
                'index' => $this->summary($client),
            ],
            'unreadMessages' => $this->unreadMessageCount(),
        ]));
    }

    /**
     * GET /clientarea?action=list — paginated resource list fragment.
     */
    public function listAction(Request $request): string
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 10);

        $query = Host::query()
            ->where('uid', $client->id)
            ->whereIn('domainstatus', Host::LIVE_STATUSES)
            ->when($request->filled('groupid'), fn ($q) => $q->whereIn(
                'productid',
                \App\Models\Product::query()->where('gid', (int) $request->input('groupid'))->pluck('id')
            ));

        // The dashboard sorts by due date by default; `orderby` is whitelisted.
        $orderby = (string) $request->input('orderby', 'nextduedate');
        if (! in_array($orderby, ['nextduedate', 'id', 'regdate', 'domain'], true)) {
            $orderby = 'nextduedate';
        }

        $sort = strtoupper((string) $request->input('sort', 'ASC')) === 'DESC' ? 'desc' : 'asc';

        $hosts = $query->orderBy($orderby, $sort)->paginate($limit, ['*'], 'page', $page);
        $rows = $this->hostRows($hosts->items());

        return $this->fragment('web.clientarea._list', [
            'rows' => $rows,
            'ClientArea' => [
                'Total' => $hosts->total(),
                'Limit' => $hosts->perPage(),
                'Page' => $hosts->currentPage(),
                'Pages' => $hosts->lastPage(),
            ],
        ]);
    }

    /**
     * GET /user_info — the account payload the client-area scripts read.
     */
    public function userInfo(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->client();

        if ($client === null) {
            return $this->unauthorized();
        }

        return $this->ok($this->userInfoPayload($client));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Card counters and finance figures shown on the dashboard.
     */
    protected function summary($client): array
    {
        $hostCount = Host::query()->where('uid', $client->id)->count();

        $ticketCount = Ticket::query()
            ->where('uid', $client->id)
            ->whereIn('status', $this->openTicketStatusIds())
            ->count();

        $orderCount = Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->where('status', Invoice::STATUS_UNPAID)
            ->count();

        $unpaidTotal = (float) Invoice::query()
            ->where('uid', $client->id)
            ->where('is_delete', 0)
            ->where('status', Invoice::STATUS_UNPAID)
            ->sum('total');

        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        $intotal = (float) \App\Models\Account::query()
            ->where('uid', $client->id)
            ->where('pay_time', '>=', $monthStart ?: 0)
            ->sum('amount_in');

        return [
            'ticket_count' => $ticketCount,
            'order_count' => $orderCount,
            'host' => $hostCount,
            'invoice_unpaid' => PricingService::money($unpaidTotal),
            'intotal' => PricingService::money($intotal),
            'allow_recharge' => Settings::on('addfunds_enabled', true) ? '1' : '0',
            'client' => [
                'credit' => PricingService::money((float) $client->credit),
                'credit_limit_balance' => PricingService::money((float) $client->credit_limit_balance),
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
            ],
            'host_nav' => $this->hostNav($client),
            'news' => $this->announcements(),
            'currency' => $this->currencyPayload(),
        ];
    }

    /**
     * Product groups the client owns services in, with their counts.
     */
    protected function hostNav($client): array
    {
        // Grouped in PHP rather than SQL: the mirrored schema prefixes table
        // names, which makes a raw joined alias fragile, and the row counts are
        // small enough that the grouping costs nothing.
        $productIds = Host::query()
            ->where('uid', $client->id)
            ->whereIn('domainstatus', Host::LIVE_STATUSES)
            ->pluck('productid')
            ->all();

        if ($productIds === []) {
            return [];
        }

        $counts = \App\Models\Product::query()
            ->whereIn('id', $productIds)
            ->pluck('gid')
            ->filter()
            ->countBy();

        if ($counts->isEmpty()) {
            return [];
        }

        return ProductGroup::query()
            ->whereIn('id', $counts->keys())
            ->orderBy('order')
            ->get()
            ->map(fn (ProductGroup $group) => [
                'id' => (int) $group->id,
                'groupname' => (string) $group->name,
                'count' => (int) ($counts[$group->id] ?? 0),
            ])
            ->all();
    }

    /**
     * Latest announcements for the dashboard sidebar.
     */
    protected function announcements(): array
    {
        return \Illuminate\Support\Facades\DB::table('news_menu')
            ->where('hidden', '0')
            ->where(function ($query) {
                $query->where('push_time', 0)->orWhere('push_time', '<=', time());
            })
            ->orderByDesc('push_time')
            ->limit(5)
            ->get(['id', 'title', 'push_time'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'push_time' => (int) $row->push_time,
            ])
            ->all();
    }

    /**
     * Ticket status ids that still count as "open" (administrator editable).
     *
     * @return array<int, int>
     */
    protected function openTicketStatusIds(): array
    {
        return \App\Models\TicketStatus::query()
            ->where('auto_close', 0)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Decorate host rows for the list fragment.
     *
     * @param  array<int, Host>  $hosts
     */
    protected function hostRows(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }

        $products = \App\Models\Product::query()
            ->whereIn('id', array_filter(array_map(fn (Host $h) => $h->productid, $hosts)))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($hosts as $host) {
            $product = $products[$host->productid] ?? null;
            $cycle = (string) $host->billingcycle;

            $rows[] = [
                'id' => (int) $host->id,
                'productname' => (string) ($product?->name ?? '未知产品'),
                'domain' => (string) $host->domain,
                'type' => (string) ($product?->type ?? 'other'),
                'domainstatus' => (string) $host->domainstatus,
                'domainstatus_desc' => StatusMap::hostStatus((string) $host->domainstatus, (string) ($product?->type ?? '')),
                'nextduedate' => (int) $host->nextduedate,
                'billingcycle' => $cycle,
                'cycle_desc' => StatusMap::cycle($cycle),
                'price_desc' => number_format((float) $host->amount, 2, '.', ''),
                'dedicatedip' => trim((string) $host->dedicatedip) !== '' ? (string) $host->dedicatedip : (string) $host->domain,
            ];
        }

        return $rows;
    }
}
