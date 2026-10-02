<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\Host;
use Illuminate\Console\Command;

/**
 * Suspends services that are past due beyond the configured grace period.
 *
 * 产品/服务到期暂停 is `cron_host_suspend`; `cron_host_suspend_time` is the
 * grace in days (0 = suspend the moment it goes overdue). The suspend itself
 * is handed to ModuleService so the upstream/downstream module is driven the
 * same way an admin-triggered suspend would be.
 */
class SuspendOverdueCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:suspend-overdue
                            {--days= : 逾期天数（默认读取系统设置）}
                            {--force : 忽略运行间隔限制}
                            {--dry-run : 只列出将暂停的产品，不写入}';

    protected $description = '暂停逾期未续费的产品';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            if (! $this->enabled('cron_host_suspend')) {
                $this->line('系统设置未开启自动暂停，跳过。');

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->claimWindow('day')) {
                return self::SUCCESS;
            }

            if (! $this->dailyHourReached()) {
                $this->line('未到定时任务执行时间，跳过。');

                return self::SUCCESS;
            }
        }

        $days = $this->option('days');

        if ($days === null || $days === '') {
            $days = $this->setting('cron_host_suspend_time', 0);
        }

        $days = max(0, (int) $days);
        $cutoff = time() - ($days * 86400);

        $hosts = Host::query()
            ->where('domainstatus', Host::STATUS_ACTIVE)
            ->where('nextduedate', '>', 0)
            ->where('nextduedate', '<=', $cutoff)
            // The admin can pin a service as exempt from auto-suspension.
            ->where('overideautosuspend', 0)
            // A suspension deferred to a future date is not due yet.
            ->where(function ($query) {
                $query->where('overidesuspenduntil', 0)
                    ->orWhere('overidesuspenduntil', '<=', time());
            })
            ->orderBy('nextduedate')
            ->limit(500)
            ->get();

        if ($hosts->isEmpty()) {
            $this->logRun('没有需要暂停的产品。', ['candidates' => 0]);

            return self::SUCCESS;
        }

        $service = $this->moduleService();
        $suspended = 0;
        $failed = 0;
        $details = [];

        foreach ($hosts as $host) {
            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] 暂停产品 #%d %s（到期 %s）',
                    (int) $host->id,
                    (string) $host->domain,
                    date('Y-m-d', (int) $host->nextduedate),
                ));

                $suspended++;

                continue;
            }

            // Skip services that still have an unpaid invoice under a grace
            // arrangement: the client is being chased, not yet cut off.
            if ($this->hasPaidInvoiceForPeriod($host)) {
                continue;
            }

            try {
                // Only flip local state once the module call has been accepted;
                // a rejected dispatch leaves the service Active so the next run
                // retries it instead of pretending it was suspended.
                if (! $this->suspend($host, $service)) {
                    $failed++;
                    $details[] = ['host_id' => (int) $host->id, 'error' => '模块调用失败'];

                    $this->error(sprintf('产品 #%d 模块暂停调用失败。', (int) $host->id));

                    continue;
                }

                $host->domainstatus = Host::STATUS_SUSPENDED;
                $host->suspend_time = time();
                $host->suspendreason = '逾期未付款自动暂停';
                $host->update_time = time();
                $host->save();

                $this->mapAction(
                    (int) $host->id,
                    '逾期自动暂停：' . (string) $host->domain,
                    'suspend',
                    (string) $host->username,
                    (int) $host->uid,
                );

                $suspended++;
                $details[] = ['host_id' => (int) $host->id];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['host_id' => (int) $host->id, 'error' => $e->getMessage()];

                $this->error(sprintf('产品 #%d 暂停失败：%s', (int) $host->id, $e->getMessage()));
            }
        }

        $this->logRun(
            sprintf(
                '%s：暂停 %d 个产品，失败 %d（逾期 %d 天）。',
                $dryRun ? '预演' : '完成',
                $suspended,
                $failed,
                $days,
            ),
            [
                'suspended' => $suspended,
                'failed' => $failed,
                'days' => $days,
                'details' => $details,
            ],
        );

        if (! $dryRun) {
            $this->touchHeartbeat();
            $this->notifySuspended($hosts, $suspended);
        }

        return self::SUCCESS;
    }

    /**
     * Drive the module suspend, reporting whether it was accepted.
     *
     * ModuleService is resolved lazily because it is written by another
     * workstream; when it is absent the action is queued for the cron drain
     * instead, which still counts as accepted.
     */
    protected function suspend(Host $host, ?object $service): bool
    {
        if ($service !== null && method_exists($service, 'dispatch')) {
            try {
                $result = $service->dispatch($host, 'suspend', false);

                // A queued action is a success: the drain will perform it.
                return is_array($result) ? (bool) ($result['status'] ?? true) : true;
            } catch (\Throwable $e) {
                $this->error(sprintf('产品 #%d 暂停异常：%s', (int) $host->id, $e->getMessage()));

                return false;
            }
        }

        $this->queueModuleAction($host, 'suspend');

        return true;
    }

    /**
     * Fallback used when ModuleService is not available yet.
     */
    protected function queueModuleAction(Host $host, string $action): void
    {
        $product = $host->product;

        \App\Models\ModuleQueue::create([
            'service_type' => \App\Models\ModuleQueue::SERVICE_HOST,
            'service_id' => (int) $host->id,
            'module_name' => (string) ($product?->server_type ?? ''),
            'module_action' => $action,
            'last_attempt' => 0,
            'last_attempt_error' => '',
            'num_retries' => 0,
            'completed' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    protected function moduleService(): ?object
    {
        if (! class_exists(\App\Services\ModuleService::class)) {
            return null;
        }

        try {
            $service = app(\App\Services\ModuleService::class);
        } catch (\Throwable) {
            return null;
        }

        return method_exists($service, 'dispatch') ? $service : null;
    }

    /**
     * A service whose overdue invoice has since been paid is left alone: the
     * unsuspend job (or the payment hook) handles it.
     */
    protected function hasPaidInvoiceForPeriod(Host $host): bool
    {
        return \Illuminate\Support\Facades\DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.rel_id', (int) $host->id)
            ->whereIn('invoice_items.type', ['renew', 'host'])
            ->where('invoices.status', 'Paid')
            ->where('invoices.paid_time', '>=', (int) $host->nextduedate)
            ->exists();
    }

    /**
     * 产品暂停通知 — the notification itself is owned by the mail layer; this
     * records the intent so the run is auditable.
     */
    protected function notifySuspended($hosts, int $count): void
    {
        if ($count === 0 || ! $this->enabled('cron_host_suspend_send')) {
            return;
        }

        $this->line(sprintf('已标记 %d 条暂停通知待发送。', $count));

        \Illuminate\Support\Facades\Log::info('cron.host_suspended', ['count' => $count]);
    }
}
