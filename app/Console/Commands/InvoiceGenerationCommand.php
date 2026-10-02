<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCron;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Host;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Raises renewal invoices for services approaching their due date.
 *
 * `cron_invoice_create_default_days` (续费通知并生成账单) says how many days
 * before `nextduedate` the invoice appears; the per-cycle switches
 * `cron_invoice_create_{hour,day,monthly,…}` decide which billing cycles are
 * invoiced automatically at all — a cycle the admin left blank is billed
 * manually.
 *
 * After invoicing, `nextinvoicedate` advances so the same period is never
 * billed twice.
 */
class InvoiceGenerationCommand extends Command
{
    use RunsAsCron;

    protected $signature = 'kjaiu:invoice-generation
                            {--days= : 提前生成天数（默认读取系统设置）}
                            {--force : 忽略运行间隔限制}
                            {--dry-run : 只列出将生成的账单，不写入}';

    protected $description = '为即将到期的产品生成续费账单';

    /** Billing cycles the settings page can toggle, in schema order. */
    protected const AUTO_CYCLES = [
        'hour', 'day', 'monthly', 'quarterly', 'semiannually', 'annually',
        'biennially', 'triennially', 'fourly', 'fively', 'sixly', 'sevenly',
        'eightly', 'ninely', 'tenly',
    ];

    public function handle(InvoiceService $invoices): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
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
            $days = $this->setting('cron_invoice_create_default_days', 7);
        }

        $days = max(0, (int) $days);
        $cutoff = time() + ($days * 86400);

        $hosts = $this->dueHosts($cutoff);

        if ($hosts->isEmpty()) {
            $this->logRun('没有需要生成账单的产品。', ['candidates' => 0]);

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($hosts as $host) {
            if (! $this->cycleEnabled((string) $host->billingcycle)) {
                $skipped++;

                continue;
            }

            $client = Client::query()->find((int) $host->uid);

            if ($client === null) {
                $skipped++;

                continue;
            }

            // Guard against a second invoice for a period already billed.
            if ($this->alreadyInvoiced($host)) {
                $this->advanceDueDate($host);
                $skipped++;

                continue;
            }

            $amount = $this->renewalAmount($host);

            if ($amount <= 0) {
                $skipped++;

                continue;
            }

            $description = $this->describe($host);

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] 产品 #%d %s -> %s %s',
                    (int) $host->id,
                    (string) $host->domain,
                    $client->fullName(),
                    PricingService::money($amount),
                ));

                $created++;

                continue;
            }

            try {
                $invoice = $invoices->create(
                    $client,
                    [[
                        'type' => 'renew',
                        'rel_id' => (int) $host->id,
                        'description' => $description,
                        'amount' => $amount,
                    ]],
                    (int) $host->nextduedate,
                    'renew',
                    ['is_cron' => 1],
                );

                $host->last_settle = time();
                $this->advanceDueDate($host);

                $created++;
                $details[] = [
                    'host_id' => (int) $host->id,
                    'invoice_id' => (int) $invoice->id,
                    'amount' => $amount,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = [
                    'host_id' => (int) $host->id,
                    'error' => $e->getMessage(),
                ];

                $this->error(sprintf('产品 #%d 生成账单失败：%s', (int) $host->id, $e->getMessage()));
            }
        }

        $this->logRun(
            sprintf(
                '%s：生成 %d 张续费账单，跳过 %d，失败 %d（提前 %d 天）。',
                $dryRun ? '预演' : '完成',
                $created,
                $skipped,
                $failed,
                $days,
            ),
            [
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
                'days' => $days,
                'details' => $details,
            ],
        );

        if (! $dryRun) {
            $this->touchHeartbeat();
        }

        return self::SUCCESS;
    }

    /**
     * Live services whose due date falls inside the notice window and which
     * have not been invoiced for it yet.
     */
    protected function dueHosts(int $cutoff)
    {
        return Host::query()
            ->whereIn('domainstatus', [Host::STATUS_ACTIVE, Host::STATUS_SUSPENDED])
            ->where('nextduedate', '>', 0)
            ->where('nextduedate', '<=', $cutoff)
            // The due date has not been billed yet for this period.
            ->where(function ($query) {
                $query->whereColumn('nextinvoicedate', '<', 'nextduedate')
                    ->orWhere('nextinvoicedate', 0);
            })
            ->orderBy('nextduedate')
            ->limit(500)
            ->get();
    }

    /**
     * Only cycles switched on in 财务设置 get automatic invoices.
     */
    protected function cycleEnabled(string $cycle): bool
    {
        $cycle = strtolower(trim($cycle));

        if ($cycle === '') {
            return false;
        }

        // One-off and trial products are never auto-renewed.
        if (in_array($cycle, ['onetime', 'ontrial'], true)) {
            return false;
        }

        if (! in_array($cycle, self::AUTO_CYCLES, true)) {
            return false;
        }

        return $this->enabled('cron_invoice_create_' . $cycle);
    }

    /**
     * Has this host already got an unpaid renewal invoice covering the
     * upcoming period? Stops the daily run from stacking duplicates.
     */
    protected function alreadyInvoiced(Host $host): bool
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.type', 'renew')
            ->where('invoice_items.rel_id', (int) $host->id)
            ->whereIn('invoices.status', [Invoice::STATUS_UNPAID, Invoice::STATUS_COLLECTIONS])
            ->whereNull('invoices.delete_time')
            ->exists();
    }

    /**
     * Renewal price: the amount stored on the service, falling back to the
     * product's pricing row for its cycle.
     */
    protected function renewalAmount(Host $host): float
    {
        $amount = (float) $host->amount;

        if ($amount > 0) {
            return PricingService::money($amount);
        }

        $product = $host->product;

        if ($product === null) {
            return 0.0;
        }

        $cycle = (string) $host->billingcycle;
        $price = (new PricingService())->cyclePrice($product, $cycle);

        return $price === null ? 0.0 : PricingService::money($price);
    }

    /**
     * Move `nextinvoicedate` forward one cycle.
     *
     * The original advances the invoice marker to the due date it just billed,
     * leaving `nextduedate` alone: the service is still due on that date, it
     * merely has an invoice for it now.
     */
    protected function advanceDueDate(Host $host): void
    {
        $host->nextinvoicedate = (int) $host->nextduedate;
        $host->update_time = time();
        $host->save();
    }

    protected function describe(Host $host): string
    {
        $product = $host->product;
        $name = (string) ($product?->name ?? '产品');
        $cycle = (string) $host->billingcycle;
        $from = (int) $host->nextduedate;
        $days = (new PricingService())->cycleDays($cycle);
        $to = $days !== null ? $from + ($days * 86400) : $from;

        return sprintf(
            '%s - %s (%s - %s)',
            $name,
            (string) $host->domain,
            date('Y-m-d', $from),
            date('Y-m-d', $to),
        );
    }
}
