<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AffiliatesWithdraw;
use App\Models\Client;
use App\Models\InvoiceItem;
use App\Services\AffiliateService;
use Illuminate\Http\Request;

/**
 * 推介计划 (affiliate programme).
 *
 * The programme's participation switch lives on
 * `shd_affiliates_user_setting`; running totals live on `shd_affiliates` and
 * commission lines on the invoice items of referred customers.
 */
class AffiliateController extends ApiController
{
    public function __construct(
        protected AffiliateService $affiliates = new AffiliateService(),
    ) {
    }

    /**
     * GET /v1/affiliates — affiliate summary.
     */
    public function show(Request $request)
    {
        $client = $this->requireClient($request);

        if (! $this->affiliates->isOpen()) {
            return $this->fail('推介计划未开启');
        }

        return $this->ok($this->affiliates->summary($client));
    }

    /**
     * PUT /v1/affiliates — activate or deactivate participation.
     */
    public function activate(Request $request)
    {
        $client = $this->requireClient($request);

        if (! $this->affiliates->isOpen()) {
            return $this->fail('推介计划未开启');
        }

        // Explicit `status` toggles; its absence means "activate".
        $status = $request->has('status') ? (int) $request->input('status') : 1;

        if ($status === 0) {
            $this->affiliates->deactivate($client);
            $this->refreshCounters($client);

            return $this->ok([
                'aff' => 0,
                'url' => '',
            ], '已退出推介计划');
        }

        $url = $this->affiliates->activate($client);
        $this->refreshCounters($client);

        return $this->ok([
            'aff' => 1,
            'url' => $url,
        ], '推介计划已激活');
    }

    /**
     * GET /v1/affiliates/record — commission records.
     */
    public function record(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $paginator = $this->affiliates->commissionRecords($client, $page, $limit, [
            'keywords' => $request->input('keywords'),
        ]);

        return $this->paginated(
            $paginator,
            fn (InvoiceItem $item) => $this->affiliates->recordPayload($item),
            ['currency' => $this->currencyPayload()]
        );
    }

    /**
     * GET /v1/affiliates/user — referred users.
     */
    public function users(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $paginator = $this->affiliates->users($client, $page, $limit, [
            'keywords' => $request->input('keywords'),
        ]);

        return $this->paginated(
            $paginator,
            fn (Client $referred) => $this->affiliates->userPayload($referred),
            ['currency' => $this->currencyPayload()]
        );
    }

    /**
     * POST /v1/affiliates/withdraw — request a payout.
     */
    public function withdraw(Request $request)
    {
        $client = $this->requireClient($request);

        $amount = round((float) $request->input('num', $request->input('amount', 0)), 2);

        try {
            $withdraw = $this->affiliates->withdraw($client, $amount);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $summary = $this->affiliates->summary($client->fresh());

        return $this->ok([
            'id' => (int) $withdraw->id,
            'num' => $this->money((float) $withdraw->num),
            'status' => (int) $withdraw->status,
            'balance' => $summary['balance'],
            'withdrawing' => $summary['withdrawing'],
        ], '提现申请已提交，请等待审核');
    }

    /**
     * GET /v1/affiliates/withdraw_record — payout history.
     */
    public function withdrawRecord(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $paginator = $this->affiliates->withdrawRecords($client, $page, $limit);

        return $this->paginated(
            $paginator,
            fn (AffiliatesWithdraw $withdraw) => $this->affiliates->withdrawPayload($withdraw),
            ['currency' => $this->currencyPayload()]
        );
    }

    /**
     * Keep the affiliate row's visitor / registration counters current.
     */
    protected function refreshCounters(Client $client): void
    {
        $row = $this->affiliates->row($client);

        if ($row === null) {
            return;
        }

        $row->registcount = count($this->affiliates->referredUserIds($client));
        $row->payamount = (int) InvoiceItem::query()
            ->whereIn('uid', $this->affiliates->referredUserIds($client) ?: [-1])
            ->where('is_aff', 1)
            ->count();
        $row->updated_time = time();
        $row->save();
    }

    protected function currencyPayload(): array
    {
        $currency = $this->currency();

        if ($currency === null) {
            return ['id' => 0, 'code' => '', 'prefix' => '', 'suffix' => '', 'default' => 1];
        }

        return [
            'id' => (int) $currency->id,
            'code' => (string) $currency->code,
            'prefix' => (string) $currency->prefix,
            'suffix' => (string) $currency->suffix,
            'default' => (int) $currency->default,
        ];
    }
}
