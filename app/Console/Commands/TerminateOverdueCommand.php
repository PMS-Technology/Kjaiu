<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\Host;
use Illuminate\Console\Command;

/**
 * Deletes services that have stayed suspended past the retention period.
 *
 * 产品/服务到期后自动删除 is `cron_host_terminate` with
 * `cron_host_terminate_time` days of retention. When 高级设置
 * (`cron_host_terminate_high`) is on, the per-product-type days in
 * `cron_host_terminate_time_{hostingaccount,server,cloud,dcimcloud,dcim,
 * software,cdn,other}` override the general value.
 */
class TerminateOverdueCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:terminate-overdue
                            {--days= : 保留天数（默认读取系统设置）}
                            {--force : 忽略运行间隔限制}
                            {--dry-run : 只列出将删除的产品，不写入}';

    protected $description = '删除长期逾期未续费的产品';

    /** Product-type buckets the advanced settings table exposes. */
    protected const TYPE_KEYS = [
        'hostingaccount',
        'server',
        'cloud',
        'dcimcloud',
        'dcim',
        'software',
        'cdn',
        'other',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            if (! $this->enabled('cron_host_terminate')) {
                $this->line('系统设置未开启自动删除，跳过。');

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

        $globalDays = $this->option('days');

        if ($globalDays === null || $globalDays === '') {
            $globalDays = $this->setting('cron_host_terminate_time', 0);
        }

        $globalDays = max(0, (int) $globalDays);

        // The broadest window selects candidates; the per-type rule is applied
        // per row afterwards.
        $candidates = Host::query()
            ->whereIn('domainstatus', [Host::STATUS_SUSPENDED, Host::STATUS_CANCELLED])
            ->where('domainstatus', '!=', Host::STATUS_TERMINATED)
            ->where('nextduedate', '>', 0)
            ->orderBy('nextduedate')
            ->limit(500)
            ->get();

        if ($candidates->isEmpty()) {
            $this->logRun('没有需要删除的产品。', ['candidates' => 0]);

            return self::SUCCESS;
        }

        $now = time();
        $deleted = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($candidates as $host) {
            $days = $this->daysFor($host, $globalDays);

            // Anchor on the later of the due date and the suspension, so a
            // service suspended late in the cycle is not deleted early.
            $anchor = max((int) $host->nextduedate, (int) $host->suspend_time);

            if ($anchor + ($days * 86400) > $now) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] 删除产品 #%d %s（保留 %d 天）',
                    (int) $host->id,
                    (string) $host->domain,
                    $days,
                ));

                $deleted++;

                continue;
            }

            try {
                // Do not mark a service terminated unless the module accepted
                // the call; otherwise the next run still sees it as pending.
                if (! $this->terminate($host)) {
                    $failed++;
                    $details[] = ['host_id' => (int) $host->id, 'error' => '模块调用失败'];

                    $this->error(sprintf('产品 #%d 模块删除调用失败。', (int) $host->id));

                    continue;
                }

                $host->domainstatus = Host::STATUS_TERMINATED;
                $host->termination_date = $now;
                $host->update_time = $now;
                $host->save();

                $this->mapAction(
                    (int) $host->id,
                    '逾期自动删除：' . (string) $host->domain,
                    'terminate',
                    (string) $host->username,
                    (int) $host->uid,
                );

                $deleted++;
                $details[] = ['host_id' => (int) $host->id, 'days' => $days];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['host_id' => (int) $host->id, 'error' => $e->getMessage()];

                $this->error(sprintf('产品 #%d 删除失败：%s', (int) $host->id, $e->getMessage()));
            }
        }

        $this->logRun(
            sprintf(
                '%s：删除 %d 个产品，跳过 %d，失败 %d。',
                $dryRun ? '预演' : '完成',
                $deleted,
                $skipped,
                $failed,
            ),
            [
                'deleted' => $deleted,
                'skipped' => $skipped,
                'failed' => $failed,
                'details' => $details,
            ],
        );

        if (! $dryRun) {
            $this->touchHeartbeat();
        }

        return self::SUCCESS;
    }

    /**
     * Retention days for one service, honouring the advanced per-type table.
     */
    protected function daysFor(Host $host, int $globalDays): int
    {
        if (! $this->enabled('cron_host_terminate_high')) {
            return $globalDays;
        }

        $key = $this->typeKey($host);
        $value = \App\Models\Configuration::value('cron_host_terminate_time_' . $key);

        if ($value === null || $value === '') {
            return $globalDays;
        }

        return max(0, (int) $value);
    }

    /**
     * Map a service onto one of the eight admin product types.
     *
     * `server_type` carries the module name (server, dcim, dcimcloud, …);
     * anything we do not recognise falls into `other`.
     */
    protected function typeKey(Host $host): string
    {
        $product = $host->product;
        $type = strtolower(trim((string) ($product?->server_type ?? '')));

        if ($type === '') {
            $type = strtolower(trim((string) ($product?->type ?? '')));
        }

        return match ($type) {
            'hostingaccount', 'hosting', 'cpanel', 'plesk' => 'hostingaccount',
            'server', 'physical', 'dedicated' => 'server',
            'cloud', 'cloudserver' => 'cloud',
            'dcimcloud', 'zjmfcloud' => 'dcimcloud',
            'dcim' => 'dcim',
            'software', 'license' => 'software',
            'cdn' => 'cdn',
            default => 'other',
        };
    }

    /**
     * Drive the module terminate, reporting whether it was accepted.
     *
     * Falls back to the queue when ModuleService is not present yet; a queued
     * action counts as accepted.
     */
    protected function terminate(Host $host): bool
    {
        $service = $this->moduleService();

        if ($service !== null) {
            try {
                $result = $service->dispatch($host, 'terminate', false);

                return is_array($result) ? (bool) ($result['status'] ?? true) : true;
            } catch (\Throwable $e) {
                $this->error(sprintf('产品 #%d 删除异常：%s', (int) $host->id, $e->getMessage()));

                return false;
            }
        }

        $product = $host->product;

        \App\Models\ModuleQueue::create([
            'service_type' => \App\Models\ModuleQueue::SERVICE_HOST,
            'service_id' => (int) $host->id,
            'module_name' => (string) ($product?->server_type ?? ''),
            'module_action' => 'terminate',
            'last_attempt' => 0,
            'last_attempt_error' => '',
            'num_retries' => 0,
            'completed' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return true;
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
}
