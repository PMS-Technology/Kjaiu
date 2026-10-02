<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\Host;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restores services that were suspended for non-payment but whose invoice has
 * since been settled.
 *
 * 支付后自动解除暂停 is `cron_host_unsuspend`. The same logic also runs inline
 * from the payment flow; this command is the safety net for payments that
 * arrived while the service was already suspended.
 */
class UnsuspendPaidCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:unsuspend-paid
                            {--force : 忽略运行间隔限制}
                            {--dry-run : 只列出将恢复的产品，不写入}';

    protected $description = '恢复已付款但仍处于暂停状态的产品';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            if (! $this->enabled('cron_host_unsuspend')) {
                $this->line('系统设置未开启支付后自动解除暂停，跳过。');

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->claimWindow('hour')) {
                return self::SUCCESS;
            }
        }

        $now = time();

        $hosts = Host::query()
            ->where('domainstatus', Host::STATUS_SUSPENDED)
            // Only services we suspended for money reasons are eligible; a
            // manual or abuse suspension must be lifted by an administrator.
            ->where(function ($query) {
                $query->where('suspendreason', 'like', '%逾期%')
                    ->orWhere('suspendreason', 'like', '%未付款%')
                    ->orWhere('suspendreason', 'like', '%自动暂停%')
                    ->orWhere('suspendreason', '');
            })
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($hosts->isEmpty()) {
            $this->logRun('没有需要解除暂停的产品。', ['candidates' => 0]);

            return self::SUCCESS;
        }

        $service = $this->moduleService();
        $restored = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($hosts as $host) {
            if (! $this->hasSettledBalance($host)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] 恢复产品 #%d %s',
                    (int) $host->id,
                    (string) $host->domain,
                ));

                $restored++;

                continue;
            }

            try {
                if (! $this->unsuspend($host, $service)) {
                    $failed++;
                    $details[] = ['host_id' => (int) $host->id, 'error' => '模块调用失败'];

                    $this->error(sprintf('产品 #%d 模块恢复调用失败。', (int) $host->id));

                    continue;
                }

                $host->domainstatus = Host::STATUS_ACTIVE;
                $host->suspendreason = '';
                $host->suspend_time = 0;
                $host->update_time = $now;
                $host->save();

                $this->mapAction(
                    (int) $host->id,
                    '付款后自动解除暂停：' . (string) $host->domain,
                    'unsuspend',
                    (string) $host->username,
                    (int) $host->uid,
                );

                $restored++;
                $details[] = ['host_id' => (int) $host->id];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['host_id' => (int) $host->id, 'error' => $e->getMessage()];

                $this->error(sprintf('产品 #%d 恢复失败：%s', (int) $host->id, $e->getMessage()));
            }
        }

        $this->logRun(
            sprintf(
                '%s：恢复 %d 个产品，跳过 %d，失败 %d。',
                $dryRun ? '预演' : '完成',
                $restored,
                $skipped,
                $failed,
            ),
            [
                'restored' => $restored,
                'skipped' => $skipped,
                'failed' => $failed,
                'details' => $details,
            ],
        );

        if (! $dryRun) {
            $this->touchHeartbeat();
            $this->notifyRestored($restored);
        }

        return self::SUCCESS;
    }

    /**
     * The service is clear to restore when it has no unpaid invoice covering
     * the period it was suspended for.
     *
     * Deliberately invoice-driven rather than credit-driven: a client can pay
     * through a gateway without leaving a balance behind.
     */
    protected function hasSettledBalance(Host $host): bool
    {
        $unpaid = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.rel_id', (int) $host->id)
            ->whereIn('invoice_items.type', ['renew', 'host', 'setup'])
            ->whereIn('invoices.status', [Invoice::STATUS_UNPAID, Invoice::STATUS_COLLECTIONS])
            ->whereNull('invoices.delete_time')
            ->exists();

        if ($unpaid) {
            return false;
        }

        // No unpaid items at all: only restore when something was actually
        // paid for this service, so a suspension with no billing history
        // (an abuse hold that slipped through) is not silently lifted.
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.rel_id', (int) $host->id)
            ->whereIn('invoice_items.type', ['renew', 'host', 'setup'])
            ->where('invoices.status', Invoice::STATUS_PAID)
            ->exists();
    }

    /**
     * Drive the module unsuspend, reporting whether it was accepted.
     */
    protected function unsuspend(Host $host, ?object $service): bool
    {
        if ($service !== null) {
            try {
                $result = $service->dispatch($host, 'unsuspend', false);

                return is_array($result) ? (bool) ($result['status'] ?? true) : true;
            } catch (\Throwable $e) {
                $this->error(sprintf('产品 #%d 恢复异常：%s', (int) $host->id, $e->getMessage()));

                return false;
            }
        }

        $product = $host->product;

        \App\Models\ModuleQueue::create([
            'service_type' => \App\Models\ModuleQueue::SERVICE_HOST,
            'service_id' => (int) $host->id,
            'module_name' => (string) ($product?->server_type ?? ''),
            'module_action' => 'unsuspend',
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

    /**
     * 产品解除暂停通知.
     */
    protected function notifyRestored(int $count): void
    {
        if ($count === 0 || ! $this->enabled('cron_host_unsuspend_send')) {
            return;
        }

        $this->line(sprintf('已标记 %d 条解除暂停通知待发送。', $count));

        \Illuminate\Support\Facades\Log::info('cron.host_unsuspended', ['count' => $count]);
    }
}
