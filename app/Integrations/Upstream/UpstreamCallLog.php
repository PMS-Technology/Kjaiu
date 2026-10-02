<?php

namespace App\Integrations\Upstream;

use Illuminate\Support\Facades\Log;

/**
 * Records every upstream exchange so the administrator's API log view
 * (`/api-log`, fed by `zjmf_finance_api/logs`) has something to render.
 *
 * Writes go to the application log always. When a host row is in scope the
 * entry is additionally persisted to `shd_api_resource_log`, which is the
 * table the original panel reads for the per-client resource audit trail.
 */
class UpstreamCallLog
{
    /** Paths whose payloads are noisy enough to skip in the audit table. */
    protected const QUIET_PATHS = [
        'login_api',
        'hosts',
        'products',
        'productsconfig',
        'module/status',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        int $apiId,
        string $method,
        string $path,
        int $httpStatus,
        float $durationMs,
        bool $ok,
        string $msg = '',
        array $context = [],
    ): void {
        Log::info('upstream.call', [
            'api_id' => $apiId,
            'method' => $method,
            'path' => $path,
            'http_status' => $httpStatus,
            'duration_ms' => round($durationMs, 2),
            'ok' => $ok,
            'msg' => $msg,
            'uid' => $context['uid'] ?? null,
            'host_id' => $context['host_id'] ?? null,
        ]);
    }

    /**
     * Persist an upstream action against a local host for the resource audit
     * trail. Failures are swallowed: logging must never break provisioning.
     *
     * @param  array<string, mixed>  $context  expects uid, host_id, source, port, ip
     */
    public static function audit(
        string $description,
        string $method,
        string $path,
        int $httpStatus,
        bool $ok,
        array $context = [],
    ): void {
        if (! self::shouldAudit($path)) {
            return;
        }

        try {
            $now = time();

            \Illuminate\Support\Facades\DB::table('api_resource_log')->insert([
                'uid' => (int) ($context['uid'] ?? 0),
                'pid' => (int) ($context['host_id'] ?? 0),
                'version' => (string) ($context['version'] ?? ''),
                'description' => sprintf(
                    '[%s] %s %s -> %d %s',
                    $ok ? 'OK' : 'FAIL',
                    strtoupper($method),
                    $path,
                    $httpStatus,
                    $description,
                ),
                'ip' => (string) ($context['ip'] ?? ''),
                'create_time' => $now,
                'update_time' => $now,
                'port' => (string) ($context['port'] ?? ''),
                'source' => (string) ($context['source'] ?? 'API'),
            ]);
        } catch (\Throwable) {
            // Never surface a logging failure to the caller.
        }
    }

    protected static function shouldAudit(string $path): bool
    {
        $path = ltrim($path, '/');

        foreach (self::QUIET_PATHS as $quiet) {
            if ($path === $quiet || str_starts_with($path, $quiet)) {
                return false;
            }
        }

        return true;
    }
}
