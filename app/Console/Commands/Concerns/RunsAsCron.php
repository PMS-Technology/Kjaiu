<?php

namespace App\Console\Commands\Concerns;

use App\Models\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Shared behaviour for the automation commands: setting access, cron logging
 * and the manual-run markers the admin's 定时任务 dashboard reads.
 */
trait RunsAsCron
{
    /**
     * Whether an automation switch is on.
     *
     * The original stores these as the strings "0"/"1" (occasionally as an
     * empty string when the form was never saved), so an unset key is off.
     */
    protected function enabled(string $key, bool $default = false): bool
    {
        $value = Configuration::value($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array((string) $value, ['1', 'true', 'on'], true);
    }

    /**
     * An integer automation setting with a fallback.
     */
    protected function setting(string $key, int $default = 0): int
    {
        $value = Configuration::value($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    /**
     * A string automation setting with a fallback.
     */
    protected function settingString(string $key, string $default = ''): string
    {
        $value = Configuration::value($key);

        return ($value === null || $value === '') ? $default : (string) $value;
    }

    /**
     * Record one run in `shd_cron_log` and echo the outcome.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function logRun(string $value, array $payload = [], ?string $method = null): void
    {
        $this->line($value);

        try {
            $now = time();
            $table = $this->prefixed('cron_log');

            DB::table($table)->insert([
                'name' => $this->getName(),
                'method' => $method ?? (static::class),
                'value' => $value === '' ? null : $this->encode($payload === [] ? $value : $payload),
                'create_time' => $now,
                'update_time' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('cron.log_failed', [
                'command' => $this->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the "自动任务状态正常/异常" heartbeat the dashboard reads.
     */
    protected function touchHeartbeat(): void
    {
        try {
            Configuration::put('cron_last_run_time', time());
            Configuration::put('cron_last_run_time_over', time());
            Configuration::put('cron_last_run_time_over_in', time());
        } catch (\Throwable) {
            // Non-fatal.
        }
    }

    /**
     * Should this run proceed, given the run locks in `shd_run_croning`?
     *
     * Re-running within the same window is skipped so a frequently-invoked
     * scheduler cannot double-invoice. The window is one of minute/hour/day,
     * matching the cadence of the command being guarded.
     */
    protected function claimWindow(string $window): bool
    {
        try {
            $table = $this->prefixed('run_croning');
            $now = time();
            $bucket = $this->bucket($window);

            $existing = DB::table($table)
                ->where('unique_tab', $bucket)
                ->where('status', 1)
                ->first();

            if ($existing !== null) {
                $this->line("本轮（{$bucket}）已执行过，跳过。");

                return false;
            }

            DB::table($table)->insert([
                'cron_type' => $this->cronType(),
                'active_type' => 0,
                'status' => 1,
                'create_time' => $now,
                'datetime' => $now,
                'unique_tab' => $bucket,
            ]);

            // Keep the lock table from growing without bound.
            DB::table($table)->where('create_time', '<', $now - (86400 * 7))->delete();

            return true;
        } catch (\Throwable) {
            // If the lock table is unavailable, run anyway rather than
            // silently skipping automation forever.
            return true;
        }
    }

    /**
     * A stable identifier for the current time window, namespaced per command
     * so two daily jobs never consume each other's lock.
     */
    protected function bucket(string $window): string
    {
        $name = str_replace(':', '-', $this->getName());
        $now = time();

        return match ($window) {
            'minute' => $name . '-' . date('YmdHi', $now),
            'hour' => $name . '-' . date('YmdH', $now),
            default => $name . '-' . date('Ymd', $now),
        };
    }

    /**
     * Whether the configured daily start hour has arrived.
     *
     * `cron_day_start_time` is an hour (0-23) chosen in the admin's 定时任务
     * form. The original runs the daily batch once at that hour; we allow the
     * job to run any time from that hour onward, and the window lock makes the
     * first passing invocation the one that does the work.
     */
    protected function dailyHourReached(): bool
    {
        $target = $this->setting('cron_day_start_time', 0);

        if ($target < 0 || $target > 23) {
            return true;
        }

        return (int) date('G') >= $target;
    }

    protected function cronType(): int
    {
        return match (static::class) {
            \App\Console\Commands\ModuleQueueCommand::class => 1,
            \App\Console\Commands\InvoiceGenerationCommand::class => 2,
            \App\Console\Commands\SuspendOverdueCommand::class => 3,
            \App\Console\Commands\TerminateOverdueCommand::class => 4,
            \App\Console\Commands\UnsuspendPaidCommand::class => 5,
            \App\Console\Commands\UpstreamSyncCommand::class => 6,
            \App\Console\Commands\ClientCareCommand::class => 7,
            default => 0,
        };
    }

    /**
     * Record an outbound module action in `shd_run_maping`, the outbound task
     * trail the admin's 任务队列 views render.
     */
    protected function mapAction(
        int $hostId,
        string $description,
        string $activeType,
        string $user = '',
        int $userId = 0,
        int $fromType = 0,
    ): void {
        try {
            $now = time();

            DB::table($this->prefixed('run_maping'))->insert([
                'user_id' => $userId,
                'user' => $user,
                'host_id' => $hostId,
                'description' => $description,
                'from_type' => $fromType,
                'active_user' => '',
                'active_type' => $this->activeTypeCode($activeType),
                'active_type_param' => $activeType,
                'status' => 1,
                'create_time' => $now,
                'last_execute_time' => $now,
            ]);
        } catch (\Throwable) {
            // Trail records are informational.
        }
    }

    /**
     * Map an action name onto the numeric codes the queue views filter by.
     */
    protected function activeTypeCode(string $action): int
    {
        return match (strtolower($action)) {
            'create', 'on' => 1,
            'suspend', 'off' => 2,
            'unsuspend' => 3,
            'terminate' => 4,
            'renew' => 5,
            default => 0,
        };
    }

    /**
     * Resolve a logical table name.
     *
     * The connection already applies the `shd_` prefix, so this is a pass
     * through that exists to keep call sites readable.
     */
    protected function prefixed(string $name): string
    {
        return $name;
    }

    protected function tableExists(string $name): bool
    {
        try {
            return Schema::hasTable($name);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
