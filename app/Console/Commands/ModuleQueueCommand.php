<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\ModuleQueue;
use Illuminate\Console\Command;

/**
 * Processes pending provisioning tasks (`shd_module_queue`).
 *
 * The queue is written by the order flow and by the overdue automation; this
 * command drains it every minute. ModuleService owns the actual module calls
 * and is written by another workstream, so it is resolved lazily and the
 * command degrades to a diagnostic when it is not present.
 */
class ModuleQueueCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:module-queue
                            {--limit=20 : 单次处理的最大任务数}
                            {--force : 忽略运行间隔限制}';

    protected $description = '处理模块任务队列（shd_module_queue）';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if (! $this->option('force') && ! $this->claimWindow('minute')) {
            return self::SUCCESS;
        }

        $service = $this->moduleService();

        if ($service === null) {
            return $this->reportUnavailable();
        }

        $pending = ModuleQueue::query()->where('completed', 0)->count();

        if ($pending === 0) {
            $this->logRun('无待处理任务。', ['pending' => 0]);

            return self::SUCCESS;
        }

        try {
            $result = $service->processQueue($limit);
        } catch (\Throwable $e) {
            $this->logRun('处理失败：' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'pending' => $pending,
            ]);

            return self::FAILURE;
        }

        // ModuleService reports {processed, completed, failed} in this build,
        // but a plain integer count is also tolerated.
        if (is_array($result)) {
            $processed = (int) ($result['processed'] ?? 0);
            $completed = (int) ($result['completed'] ?? $processed);
            $failed = (int) ($result['failed'] ?? 0);
        } else {
            $processed = (int) $result;
            $completed = $processed;
            $failed = 0;
        }

        $remaining = ModuleQueue::query()->where('completed', 0)->count();

        $this->logRun(
            sprintf('已处理 %d 个任务（成功 %d，失败 %d），剩余 %d 个。', $processed, $completed, $failed, $remaining),
            [
                'processed' => $processed,
                'completed' => $completed,
                'failed' => $failed,
                'remaining' => $remaining,
                'limit' => $limit,
            ],
        );

        $this->touchHeartbeat();

        return self::SUCCESS;
    }

    /**
     * Resolve ModuleService without requiring it at class-load time.
     */
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

        if (! method_exists($service, 'processQueue')) {
            return null;
        }

        return $service;
    }

    /**
     * ModuleService is absent: report what is queued so the operator can see
     * the backlog rather than silently doing nothing.
     */
    protected function reportUnavailable(): int
    {
        $pending = ModuleQueue::query()->where('completed', 0)->count();

        if ($pending === 0) {
            $this->logRun('模块服务未就绪，且当前无待处理任务。', ['pending' => 0]);

            return self::SUCCESS;
        }

        $this->warn(sprintf('模块服务（App\\Services\\ModuleService）尚未就绪，%d 个任务等待处理。', $pending));

        $this->logRun(
            sprintf('模块服务未就绪，%d 个任务等待处理。', $pending),
            ['pending' => $pending],
        );

        return self::SUCCESS;
    }
}
