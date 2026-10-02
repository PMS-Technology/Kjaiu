<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\FinanceApi;
use App\Services\Admin\UpstreamSyncService;
use Illuminate\Console\Command;

/**
 * Refreshes local product data from every supplier that opted into it.
 *
 * A supplier is only synced when its own `auto_update` flag is on, which is
 * the per-row switch in the admin's 供应商管理 form — a supplier the
 * administrator maintains by hand must not have its prices overwritten
 * behind their back.
 */
class UpstreamSyncCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:upstream-sync
                            {--api= : 只同步指定供应商ID}
                            {--force : 忽略运行间隔限制}
                            {--dry-run : 只列出将同步的供应商，不写入}';

    protected $description = '同步上游供应商的商品信息';

    public function handle(UpstreamSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->claimWindow('hour')) {
            return self::SUCCESS;
        }

        $apis = $this->suppliers();

        if ($apis->isEmpty()) {
            $this->logRun('没有开启自动更新的供应商。', ['suppliers' => 0]);

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        foreach ($apis as $api) {
            $label = sprintf('#%d %s', (int) $api->id, (string) $api->name);

            // A manual supplier has no API to pull from.
            if (! $api->isApiReseller() && (string) $api->type !== 'v10') {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('[dry-run] 同步供应商 %s', $label));
                $updated++;

                continue;
            }

            $result = $sync->refreshAll($api);

            if ($result['status']) {
                $updated++;

                $this->info(sprintf('%s：%s', $label, (string) $result['msg']));
            } else {
                $failed++;

                $this->error(sprintf('%s：%s', $label, (string) $result['msg']));
            }

            $details[] = [
                'api_id' => (int) $api->id,
                'name' => (string) $api->name,
                'status' => (bool) $result['status'],
                'msg' => (string) $result['msg'],
                'data' => $result['data'],
            ];
        }

        $this->logRun(
            sprintf(
                '%s：同步 %d 个供应商，失败 %d，跳过 %d。',
                $dryRun ? '预演' : '完成',
                $updated,
                $failed,
                $skipped,
            ),
            [
                'updated' => $updated,
                'failed' => $failed,
                'skipped' => $skipped,
                'details' => $details,
            ],
        );

        if (! $dryRun) {
            $this->touchHeartbeat();
        }

        return self::SUCCESS;
    }

    /**
     * Suppliers eligible for the hourly refresh: `auto_update` on, and not a
     * resource pool (pools are priced per allocation, not per catalogue).
     */
    protected function suppliers()
    {
        $query = FinanceApi::query()
            ->where('auto_update', 1)
            ->where('is_resource', 0)
            ->orderBy('id');

        $id = (int) ($this->option('api') ?? 0);

        if ($id > 0) {
            $query->where('id', $id);
        }

        return $query->get();
    }
}
