<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Affiliate (推介计划) programme.
 *
 * The original keeps one `shd_affiliates` row per client holding the running
 * commission totals, a `shd_affiliates_user_setting` row with that client's
 * participation switches and a `shd_affiliates_withdraw` ledger for payout
 * requests. Commission itself is stored on the invoice
 * (`aff_commission` / `aff_commmission_bates`, typo preserved) and on each
 * line item, so the record list is a projection of paid invoices.
 */
class AffiliateService
{
    /** Withdraw statuses, matching `shd_affiliates_withdraw.status`. */
    public const WITHDRAW_PENDING = 1;
    public const WITHDRAW_APPROVED = 2;
    public const WITHDRAW_REJECTED = 3;

    /** Withdraw methods. */
    public const METHOD_BALANCE = 1;
    public const METHOD_RECORD_ONLY = 2;
    public const METHOD_TO_ACCOUNT = 3;

    /** Commission base types. */
    public const BATES_FIXED = 1;
    public const BATES_PERCENT = 2;

    public function __construct(
    ) {
    }

    /**
     * Whether the programme is enabled site-wide.
     */
    public function isOpen(): bool
    {
        return (int) Configuration::value('affiliate_enabled', 0) === 1
            || (int) Configuration::value('AffiliateEnabled', 0) === 1;
    }

    /**
     * Commission rate (percent) from the site settings.
     */
    public function percent(): float
    {
        $value = Configuration::value('affiliate_percent', Configuration::value('AffiliateEarningPercent', 0));

        return (float) $value;
    }

    /**
     * Minimum payout amount.
     */
    public function minimumWithdraw(): float
    {
        $value = Configuration::value('affiliate_payout', Configuration::value('AffiliatePayout', 0));

        return (float) $value;
    }

    /**
     * The affiliate row for a client, creating it on first access.
     */
    public function row(Client $client, bool $create = true): ?\App\Models\Affiliates
    {
        $row = \App\Models\Affiliates::query()->where('uid', $client->id)->first();

        if ($row !== null || ! $create) {
            return $row;
        }

        $row = \App\Models\Affiliates::create([
            'date' => time(),
            'uid' => (int) $client->id,
            'visitors' => 0,
            'registcount' => 0,
            'payamount' => 0,
            'onetime' => 0,
            'audited_balance' => 0,
            'balance' => 0,
            'withdrawn' => 0,
            'created_time' => time(),
            'updated_time' => time(),
            'withdraw_ing' => 0,
            'url_identy' => $this->generateIdent(),
            'sum' => 0,
        ]);

        return $row;
    }

    /**
     * Per-client affiliate switches.
     */
    public function setting(Client $client, bool $create = true): ?\App\Models\AffiliatesUserSetting
    {
        $row = \App\Models\AffiliatesUserSetting::query()->where('uid', $client->id)->first();

        if ($row !== null || ! $create) {
            return $row;
        }

        return \App\Models\AffiliatesUserSetting::create([
            'uid' => (int) $client->id,
            'create_time' => time(),
            'affiliate_enabled' => 1,
            'affiliate_is_reorder' => (int) Configuration::value('affiliate_is_reorder', 0),
            'affiliate_reorder' => 0,
            'affiliate_is_renew' => (int) Configuration::value('affiliate_is_renew', 0),
            'affiliate_renew' => 0,
            'affiliate_bates' => $this->percent(),
            'affiliate_type' => self::BATES_PERCENT,
            'affiliate_renew_type' => self::BATES_PERCENT,
            'affiliate_reorder_type' => self::BATES_PERCENT,
        ]);
    }

    /**
     * Whether the client participates in the programme.
     */
    public function isActive(Client $client): bool
    {
        $setting = $this->setting($client, false);

        if ($setting === null) {
            return false;
        }

        return (int) $setting->affiliate_enabled === 1;
    }

    /**
     * Activate participation, returning the referral link.
     */
    public function activate(Client $client): string
    {
        $setting = $this->setting($client);
        $setting->affiliate_enabled = 1;
        $setting->save();

        $row = $this->row($client);

        return $this->referralUrl($row);
    }

    /**
     * Deactivate participation.
     */
    public function deactivate(Client $client): void
    {
        $setting = $this->setting($client);
        $setting->affiliate_enabled = 0;
        $setting->save();
    }

    /**
     * Referral link for an affiliate row.
     */
    public function referralUrl(\App\Models\Affiliates $row): string
    {
        $ident = trim((string) $row->url_identy);

        if ($ident === '') {
            $ident = $this->generateIdent();
            $row->url_identy = $ident;
            $row->save();
        }

        return rtrim((string) Configuration::value('domain', config('app.url')), '/') . '/aff/' . $ident;
    }

    /**
     * The affiliate summary payload (`/v1/affiliates`).
     */
    public function summary(Client $client): array
    {
        $row = $this->row($client);
        $setting = $this->setting($client);
        $currency = Currency::default();

        return [
            'is_open' => $this->isOpen() ? 1 : 0,
            'aff' => $this->isActive($client) ? 1 : 0,
            'id' => (int) $row->id,
            'visitors' => (int) $row->visitors,
            'registcount' => (int) $row->registcount,
            'payamount' => (int) $row->payamount,
            'audited_balance' => $this->money((float) $row->audited_balance),
            'balance' => $this->money((float) $row->balance),
            'withdrawn' => $this->money((float) $row->withdrawn),
            'withdrawing' => $this->money((float) $row->withdraw_ing),
            'withdraw_ing' => $this->money((float) $row->withdraw_ing),
            'url_identy' => (string) $row->url_identy,
            'sum' => $this->money((float) $row->sum),
            'prefix' => (string) ($currency->prefix ?? ''),
            'suffix' => (string) ($currency->suffix ?? ''),
            'url' => $this->referralUrl($row),
            'affiliate_is_renew' => (int) $setting->affiliate_is_renew,
            'affiliate_is_reorder' => (int) $setting->affiliate_is_reorder,
            'affiliate_withdraw' => $this->money($this->minimumWithdraw()),
            'commission' => $this->commissionMethods($setting),
        ];
    }

    /**
     * Commission rule descriptions shown on the affiliate page.
     */
    protected function commissionMethods(\App\Models\AffiliatesUserSetting $setting): array
    {
        $methods = [
            [
                'name' => '推介收益',
                'description' => '推介的用户注册并购买产品后按订单金额返佣',
                'type' => (int) $setting->affiliate_type === self::BATES_FIXED ? '金额' : '百分比',
                'commission' => (int) $setting->affiliate_type === self::BATES_FIXED
                    ? $this->money((float) $setting->affiliate_bates)
                    : $this->money((float) $setting->affiliate_bates) . '%',
            ],
        ];

        if ((int) $setting->affiliate_is_renew === 1) {
            $methods[] = [
                'name' => '续费收益',
                'description' => '推介的用户续费时按续费金额返佣',
                'type' => (int) $setting->affiliate_renew_type === self::BATES_FIXED ? '金额' : '百分比',
                'commission' => (int) $setting->affiliate_renew_type === self::BATES_FIXED
                    ? $this->money((float) $setting->affiliate_renew)
                    : $this->money((float) $setting->affiliate_renew) . '%',
            ];
        }

        if ((int) $setting->affiliate_is_reorder === 1) {
            $methods[] = [
                'name' => '二次订单收益',
                'description' => '推介的用户再次下单时返佣',
                'type' => (int) $setting->affiliate_reorder_type === self::BATES_FIXED ? '金额' : '百分比',
                'commission' => (int) $setting->affiliate_reorder_type === self::BATES_FIXED
                    ? $this->money((float) $setting->affiliate_reorder)
                    : $this->money((float) $setting->affiliate_reorder) . '%',
            ];
        }

        return $methods;
    }

    /**
     * Commission records: paid invoices raised for users this client referred.
     */
    public function commissionRecords(Client $client, int $page, int $limit, array $filters = [])
    {
        $referred = $this->referredUserIds($client);

        $query = InvoiceItem::query()
            ->whereIn('uid', $referred === [] ? [-1] : $referred)
            ->where('is_aff', 1)
            ->with(['invoice']);

        if (! empty($filters['keywords'])) {
            $keywords = (string) $filters['keywords'];
            $query->where(function ($sub) use ($keywords) {
                $sub->where('description', 'like', '%' . $keywords . '%')
                    ->orWhere('invoice_id', $keywords);
            });
        }

        return $query->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * One commission record row.
     */
    public function recordPayload(InvoiceItem $item): array
    {
        $currency = Currency::default();
        $invoice = $item->invoice;
        $client = Client::query()->find((int) $item->uid);
        $confirmed = (string) ($item->is_aff ?? '') === '1' && (int) ($invoice->is_aff ?? 0) === 1;

        return [
            'id' => (int) $item->id,
            'status' => (string) ($invoice->status ?? Invoice::STATUS_UNPAID),
            'status_zh' => match ((string) ($invoice->status ?? '')) {
                Invoice::STATUS_PAID => '已支付',
                Invoice::STATUS_REFUNDED => '已退款',
                default => '未支付',
            },
            'create_time' => (int) ($invoice->create_time ?? $item->due_time),
            'type' => $this->itemTypeLabel((string) $item->type),
            'paid_time' => (int) ($invoice->paid_time ?? 0),
            'username' => (string) ($client->username ?: $client->email),
            'uid' => (int) $item->uid,
            'prefix' => (string) ($currency->prefix ?? ''),
            'suffix' => (string) ($currency->suffix ?? ''),
            'commmission' => $this->money((float) $item->aff_commission),
            'commission' => $this->money((float) $item->aff_commission),
            'commission_bates' => $this->money((float) $item->aff_commmission_bates),
            'commission_bates_type' => (int) $item->aff_commmission_bates_type,
            'invoice_id' => (int) $item->invoice_id,
            'confirm_status' => $confirmed ? 1 : 0,
            'confirm_time' => (int) ($invoice->aff_sure_time ?? 0),
            'amount' => $this->money((float) $item->amount),
        ];
    }

    /**
     * Users this client referred.
     *
     * @return array<int, int>
     */
    public function referredUserIds(Client $client): array
    {
        return DB::table('affiliates_user')
            ->where('affid', (int) $client->id)
            ->pluck('uid')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Referred users, paginated.
     */
    public function users(Client $client, int $page, int $limit, array $filters = [])
    {
        $ids = $this->referredUserIds($client);

        $query = Client::query()->whereIn('id', $ids === [] ? [-1] : $ids);

        if (! empty($filters['keywords'])) {
            $keywords = (string) $filters['keywords'];
            $query->where(function ($sub) use ($keywords) {
                $sub->where('username', 'like', '%' . $keywords . '%')
                    ->orWhere('email', 'like', '%' . $keywords . '%')
                    ->orWhere('phonenumber', 'like', '%' . $keywords . '%');
            });
        }

        return $query->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * One referred-user row.
     */
    public function userPayload(Client $client): array
    {
        return [
            'id' => (int) $client->id,
            'username' => (string) $client->username,
            'company_name' => (string) $client->companyname,
            'email' => (string) $client->email,
            'phonenumber' => (string) $client->phonenumber,
            'create_time' => (int) $client->create_time,
            'last_login_time' => (int) $client->lastlogin,
        ];
    }

    /**
     * Record a withdrawal request.
     */
    public function withdraw(Client $client, float $amount): \App\Models\AffiliatesWithdraw
    {
        $row = $this->row($client);
        $minimum = $this->minimumWithdraw();

        if (! $this->isActive($client)) {
            throw new \InvalidArgumentException('请先激活推介计划');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('提现金额必须大于 0');
        }

        if ($minimum > 0 && $amount < $minimum) {
            throw new \InvalidArgumentException('最低提现金额为 ' . $this->money($minimum));
        }

        if ($amount > (float) $row->balance) {
            throw new \InvalidArgumentException('可提现佣金不足');
        }

        return DB::transaction(function () use ($client, $row, $amount) {
            $withdraw = \App\Models\AffiliatesWithdraw::create([
                'uid' => (int) $client->id,
                'num' => PricingService::money($amount),
                'type' => self::METHOD_BALANCE,
                'admin_id' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'status' => self::WITHDRAW_PENDING,
                'reason' => '',
            ]);

            // The amount leaves the withdrawable balance and is held in
            // withdraw_ing until an administrator approves or rejects it.
            $row->balance = PricingService::money((float) $row->balance - $amount);
            $row->withdraw_ing = PricingService::money((float) $row->withdraw_ing + $amount);
            $row->updated_time = time();
            $row->save();

            return $withdraw;
        });
    }

    /**
     * Withdrawal records for a client.
     */
    public function withdrawRecords(Client $client, int $page, int $limit)
    {
        return \App\Models\AffiliatesWithdraw::query()
            ->where('uid', $client->id)
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * One withdrawal row.
     */
    public function withdrawPayload(\App\Models\AffiliatesWithdraw $withdraw): array
    {
        $currency = Currency::default();
        $admin = $withdraw->admin_id > 0
            ? DB::table('user')->where('id', $withdraw->admin_id)->value('user_login')
            : '';

        return [
            'id' => (int) $withdraw->id,
            'num' => $this->money((float) $withdraw->num),
            'type' => (int) $withdraw->type,
            'create_time' => (int) $withdraw->create_time,
            'status' => (int) $withdraw->status,
            'status_zh' => match ((int) $withdraw->status) {
                self::WITHDRAW_APPROVED => '审核通过',
                self::WITHDRAW_REJECTED => '已拒绝',
                default => '待审核',
            },
            'admin' => (string) $admin,
            'reason' => (string) $withdraw->reason,
            'suffix' => (string) ($currency->suffix ?? ''),
        ];
    }

    /**
     * Commission owed for a paid invoice item. Used by the payment callback.
     */
    public function commissionFor(Invoice $invoice, InvoiceItem $item): float
    {
        $client = Client::query()->find((int) $invoice->uid);

        if ($client === null) {
            return 0.0;
        }

        $referral = DB::table('affiliates_user_temp')
            ->where('uid', (int) $client->id)
            ->first();

        $affiliateId = (int) ($referral->affid_uid ?? 0);

        if ($affiliateId <= 0) {
            $affiliateId = (int) DB::table('affiliates_user')
                ->where('uid', (int) $client->id)
                ->value('affid');
        }

        if ($affiliateId <= 0) {
            return 0.0;
        }

        $setting = DB::table('affiliates_user_setting')->where('uid', $affiliateId)->first();
        $bates = $setting === null ? $this->percent() : (float) $setting->affiliate_bates;

        if ($bates <= 0) {
            return 0.0;
        }

        $isRenew = (string) $item->type === 'renew';

        if ($isRenew && $setting !== null && (int) $setting->affiliate_is_renew !== 1) {
            return 0.0;
        }

        $type = (int) ($setting->affiliate_type ?? self::BATES_PERCENT);

        if ($isRenew && $setting !== null) {
            $type = (int) $setting->affiliate_renew_type;
            $bates = (float) $setting->affiliate_renew;
        }

        return $type === self::BATES_FIXED
            ? PricingService::money($bates)
            : PricingService::money((float) $item->amount * ($bates / 100));
    }

    protected function itemTypeLabel(string $type): string
    {
        return match ($type) {
            'renew' => '续费',
            'upgrade' => '产品升降级',
            'configoptions' => '配置项升降级',
            'hosting' => '首次订购',
            default => $type,
        };
    }

    protected function generateIdent(): string
    {
        do {
            $ident = strtoupper(Str::random(8));
        } while (\App\Models\Affiliates::query()->where('url_identy', $ident)->exists());

        return $ident;
    }

    protected function money(float $amount): string
    {
        return number_format(PricingService::money($amount), 2, '.', '');
    }
}
