<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\Client;
use App\Models\Host;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs the client-care rules (客户关怀).
 *
 * Rules live in `shd_client_care`; each is scoped to products through
 * `shd_client_care_product_links`, and the trigger vocabulary is fixed by
 * `shd_client_care_trigger`:
 *
 *   product_after_order        N days after buying one of the linked products
 *   product_before_order_due   N days before the linked product falls due
 *   register_no_order          registered N days ago and never ordered
 *   register_order_no_pay      registered N days ago, ordered, never paid
 *   register_surpass           registered N days ago and not logged in since
 *
 * Each firing is written to `shd_client_care` — the log table the admin's
 * care page reads. When `--dry-run` is passed nothing is written.
 */
class ClientCareCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:client-care
                            {--care= : 只执行指定关怀规则ID}
                            {--force : 忽略运行间隔限制}
                            {--dry-run : 只列出将触发的客户，不写入}';

    protected $description = '执行客户关怀规则';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->claimWindow('day')) {
            return self::SUCCESS;
        }

        if (! $dryRun && ! $this->dailyHourReached()) {
            $this->line('未到定时任务执行时间，跳过。');

            return self::SUCCESS;
        }

        $rules = $this->rules();

        if ($rules->isEmpty()) {
            $this->logRun('没有启用的客户关怀规则。', ['rules' => 0]);

            return self::SUCCESS;
        }

        $triggered = 0;
        $skipped = 0;
        $details = [];

        foreach ($rules as $rule) {
            $matches = $this->matchesFor($rule);

            if ($matches === []) {
                continue;
            }

            $productIds = $this->linkedProducts((int) $rule->id);

            foreach ($matches as $match) {
                $uid = (int) $match['uid'];

                if ($uid <= 0) {
                    continue;
                }

                // One delivery per client per rule per period.
                if ($this->alreadyDelivered((int) $rule->id, $uid, (string) $rule->trigger, (int) $rule->time)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] 规则「%s」触发客户 #%d（%s）',
                        (string) $rule->name,
                        $uid,
                        (string) $match['reason'],
                    ));

                    $triggered++;

                    continue;
                }

                try {
                    $this->deliver($rule, $uid, $productIds, (string) $match['reason']);

                    $triggered++;
                    $details[] = [
                        'care_id' => (int) $rule->id,
                        'uid' => $uid,
                        'trigger' => (string) $rule->trigger,
                    ];
                } catch (\Throwable $e) {
                    $this->error(sprintf(
                        '规则「%s」客户 #%d 执行失败：%s',
                        (string) $rule->name,
                        $uid,
                        $e->getMessage(),
                    ));
                }
            }
        }

        $this->logRun(
            sprintf(
                '%s：触发 %d 位客户，跳过 %d。',
                $dryRun ? '预演' : '完成',
                $triggered,
                $skipped,
            ),
            [
                'triggered' => $triggered,
                'skipped' => $skipped,
                'rules' => $rules->count(),
                'details' => $details,
            ],
        );

        if (! $dryRun) {
            $this->touchHeartbeat();
        }

        return self::SUCCESS;
    }

    /**
     * Enabled rules, ordered for stable output.
     */
    protected function rules()
    {
        $query = DB::table('client_care')->where('status', 1)->orderBy('id');

        $id = (int) ($this->option('care') ?? 0);

        if ($id > 0) {
            $query->where('id', $id);
        }

        return $query->get();
    }

    /**
     * Product ids a rule applies to. An empty list means "all products".
     *
     * @return array<int, int>
     */
    protected function linkedProducts(int $careId): array
    {
        return DB::table('client_care_product_links')
            ->where('care_id', $careId)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Clients matching one rule's condition.
     *
     * @return array<int, array{uid:int,reason:string}>
     */
    protected function matchesFor(object $rule): array
    {
        $days = max(0, (int) $rule->time);
        $cutoff = time() - ($days * 86400);
        $productIds = $this->linkedProducts((int) $rule->id);

        return match ((string) $rule->trigger) {
            'product_after_order' => $this->afterOrder($cutoff, $productIds),
            'product_before_order_due' => $this->beforeDue($days, $productIds),
            'register_no_order' => $this->registeredWithoutOrder($cutoff),
            'register_order_no_pay' => $this->registeredWithUnpaidOrder($cutoff),
            'register_surpass' => $this->inactiveRegistration($cutoff),
            default => [],
        };
    }

    /**
     * Bought a linked product N or more days ago.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{uid:int,reason:string}>
     */
    protected function afterOrder(int $cutoff, array $productIds): array
    {
        $query = Host::query()
            ->whereIn('domainstatus', [Host::STATUS_ACTIVE, Host::STATUS_SUSPENDED])
            ->where('regdate', '>', 0)
            ->where('regdate', '<=', $cutoff);

        if ($productIds !== []) {
            $query->whereIn('productid', $productIds);
        }

        return $query->limit(2000)->get()
            ->map(fn (Host $host) => [
                'uid' => (int) $host->uid,
                'reason' => sprintf('产品 #%d 购于 %s', (int) $host->id, date('Y-m-d', (int) $host->regdate)),
            ])
            ->all();
    }

    /**
     * Due within the next N days (and not already overdue).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{uid:int,reason:string}>
     */
    protected function beforeDue(int $days, array $productIds): array
    {
        $start = time();
        $end = $start + ($days * 86400);

        $query = Host::query()
            ->whereIn('domainstatus', [Host::STATUS_ACTIVE, Host::STATUS_SUSPENDED])
            ->where('nextduedate', '>=', $start)
            ->where('nextduedate', '<=', $end);

        if ($productIds !== []) {
            $query->whereIn('productid', $productIds);
        }

        return $query->limit(2000)->get()
            ->map(fn (Host $host) => [
                'uid' => (int) $host->uid,
                'reason' => sprintf('产品 #%d 将于 %s 到期', (int) $host->id, date('Y-m-d', (int) $host->nextduedate)),
            ])
            ->all();
    }

    /**
     * Registered N or more days ago and has no order at all.
     *
     * @return array<int, array{uid:int,reason:string}>
     */
    protected function registeredWithoutOrder(int $cutoff): array
    {
        return Client::query()
            ->where('status', Client::STATUS_ACTIVE)
            ->where('create_time', '>', 0)
            ->where('create_time', '<=', $cutoff)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.uid', 'clients.id');
            })
            ->limit(2000)
            ->get()
            ->map(fn (Client $client) => [
                'uid' => (int) $client->id,
                'reason' => '注册后未下单',
            ])
            ->all();
    }

    /**
     * Registered N or more days ago and has ordered but never paid.
     *
     * @return array<int, array{uid:int,reason:string}>
     */
    protected function registeredWithUnpaidOrder(int $cutoff): array
    {
        return Client::query()
            ->where('status', Client::STATUS_ACTIVE)
            ->where('create_time', '>', 0)
            ->where('create_time', '<=', $cutoff)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.uid', 'clients.id')
                    ->whereIn('orders.status', ['Unpaid', 'Cancelled']);
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.uid', 'clients.id')
                    ->where('orders.status', 'Paid');
            })
            ->limit(2000)
            ->get()
            ->map(fn (Client $client) => [
                'uid' => (int) $client->id,
                'reason' => '下单后未支付',
            ])
            ->all();
    }

    /**
     * Registered N or more days ago and has not logged in for that long.
     *
     * @return array<int, array{uid:int,reason:string}>
     */
    protected function inactiveRegistration(int $cutoff): array
    {
        return Client::query()
            ->where('status', Client::STATUS_ACTIVE)
            ->where('create_time', '>', 0)
            ->where('create_time', '<=', $cutoff)
            ->where(function ($query) use ($cutoff) {
                $query->where('lastlogin', '<=', $cutoff)
                    ->orWhere('lastlogin', 0);
            })
            ->limit(2000)
            ->get()
            ->map(fn (Client $client) => [
                'uid' => (int) $client->id,
                'reason' => '超过 ' . (int) round((time() - $cutoff) / 86400) . ' 天未登录',
            ])
            ->all();
    }

    /**
     * Has this rule already fired for this client in the current period?
     *
     * Deduplicated against the care history (or the in-site message trail when
     * the history table is absent), using the rule's own interval as the
     * window so a daily rule does not re-message the same client every run.
     */
    protected function alreadyDelivered(int $careId, int $uid, string $trigger, int $days): bool
    {
        $window = max(1, $days) * 86400;

        return $this->deliveredWithin($careId, $uid, $window);
    }

    /**
     * Delivery history lives in `shd_client_care_log` when the install has it.
     * Absent that table, the care log falls back to the in-site message trail.
     */
    protected function deliveredWithin(int $careId, int $uid, int $window): bool
    {
        $since = time() - $window;

        try {
            if ($this->tableExists('client_care_log')) {
                return DB::table('client_care_log')
                    ->where('care_id', $careId)
                    ->where('uid', $uid)
                    ->where('create_time', '>=', $since)
                    ->exists();
            }

            return DB::table('system_message')
                ->where('uid', $uid)
                ->where('obj', 'like', '%"care_id":' . $careId . '%')
                ->where('create_time', '>=', $since)
                ->exists();
        } catch (\Throwable) {
            // An unreadable history must not block a first delivery.
            return false;
        }
    }

    /** `shd_system_message`.`type`: 3 = 站内信. */
    protected const MESSAGE_TYPE_SITE = 3;

    /**
     * Carry out one delivery and record it.
     *
     * The `method` column selects the channel (email/message); the actual
     * sending is owned by the mail layer, so this command writes the in-site
     * message and the care log entry, which is what the admin page reads.
     *
     * @param  array<int, int>  $productIds
     */
    protected function deliver(object $rule, int $uid, array $productIds, string $reason): void
    {
        $now = time();
        $content = $this->content($rule, $reason);

        // In-site message: always recorded so the client has a visible trace.
        // The rule id rides in `obj` (the column for message metadata) because
        // `type` is a fixed enum, not a free-form tag.
        DB::table('system_message')->insert([
            'uid' => $uid,
            'title' => (string) $rule->name,
            'content' => $content,
            'obj' => json_encode(['care_id' => (int) $rule->id, 'trigger' => (string) $rule->trigger], JSON_UNESCAPED_UNICODE),
            'attachment' => '',
            'type' => self::MESSAGE_TYPE_SITE,
            'is_market' => 0,
            'delete_time' => 0,
            'create_time' => $now,
            'read_time' => 0,
        ]);

        if ($this->tableExists('client_care_log')) {
            try {
                DB::table('client_care_log')->insert([
                    'care_id' => (int) $rule->id,
                    'uid' => $uid,
                    'trigger' => (string) $rule->trigger,
                    'method' => (string) $rule->method,
                    'content' => $content,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable) {
                // Optional audit table.
            }
        }

        \Illuminate\Support\Facades\Log::info('cron.client_care', [
            'care_id' => (int) $rule->id,
            'uid' => $uid,
            'trigger' => (string) $rule->trigger,
            'method' => (string) $rule->method,
            'products' => $productIds,
            'reason' => $reason,
        ]);
    }

    /**
     * Render the rule's content, substituting the client and product context.
     */
    protected function content(object $rule, string $reason): string
    {
        $templateId = (int) ($rule->email_template_id ?: $rule->message_template_id);

        $body = '';

        if ($templateId > 0) {
            $body = (string) (DB::table('email_templates')->where('id', $templateId)->value('message') ?? '');
        }

        if ($body === '') {
            $body = (string) $rule->name;
        }

        return $body . "\n" . $reason;
    }
}
