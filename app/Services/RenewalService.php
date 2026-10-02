<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Host;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\RenewCycle;
use Illuminate\Support\Facades\DB;

/**
 * Service renewal: pricing a renewal, turning it into an invoice and (when the
 * customer switched away from their original cycle) writing the
 * `shd_renew_cycle` marker the cron uses to apply the new recurring amount.
 */
class RenewalService
{
    public function __construct(
        protected PricingService $pricing = new PricingService(),
        protected InvoiceService $invoices = new InvoiceService(),
        protected HostService $hosts = new HostService(),
        protected ModuleService $modules = new ModuleService(),
    ) {
    }

    /**
     * Cycles available for a host plus the pay-type rules.
     */
    public function page(Host $host, ?string $cycle = null): array
    {
        $cycles = $this->hosts->renewalCycles($host);
        $currency = Currency::default();

        return [
            'currency' => $this->hosts->currencyPayload($currency),
            'cycle' => $cycles,
            'pay_type' => $host->product === null
                ? []
                : $this->hosts->payTypePayload($host->product),
            'billingcycle' => $cycle ?: (string) $host->billingcycle,
            'nextduedate' => (int) $host->nextduedate,
            'amount' => $this->hosts->money((float) $host->amount),
        ];
    }

    /**
     * Batch renewal page: every host with its selectable cycles and totals.
     *
     * @param  array<int, Host>  $hosts
     * @param  array<int|string, string>  $cycles  host id => chosen cycle
     */
    public function batchPage(array $hosts, array $cycles = []): array
    {
        $currency = Currency::default();
        $total = 0.0;
        $totalSale = 0.0;
        $rows = [];
        $groupMenus = [];

        foreach ($hosts as $host) {
            $allowed = $this->hosts->renewalCycles($host);
            $chosen = (string) ($cycles[$host->id] ?? $host->billingcycle);

            $amount = null;

            foreach ($allowed as $candidate) {
                if ($candidate['billingcycle'] === $chosen) {
                    $amount = (float) $candidate['amount'];

                    break;
                }
            }

            $amount = $amount ?? (float) $host->amount;
            $sale = null;

            foreach ($allowed as $candidate) {
                if ($candidate['billingcycle'] === $chosen && $candidate['saleproducts'] !== '0.00') {
                    $sale = (float) $candidate['saleproducts'];
                }
            }

            $total += $amount;

            if ($sale !== null) {
                $totalSale += $amount - $sale;
            }

            $product = $host->product;
            $menu = $product === null ? null : $this->hosts->groupMenu($product);

            if ($menu !== null) {
                $groupMenus[$menu['id']] = $menu;
            }

            $rows[] = [
                'id' => (int) $host->id,
                'productid' => (int) $host->productid,
                'uid' => (int) $host->uid,
                'name' => $this->hosts->productName($host),
                'dedicatedip' => (string) $host->dedicatedip,
                'nextduedate' => (int) $host->nextduedate,
                'nextduedate_renew' => $this->nextDueAfter($chosen, (int) $host->nextduedate),
                'billingcycle' => (string) $host->billingcycle,
                'amount' => $this->hosts->money($amount),
                'flag' => $host->product !== null && $host->product->group?->headline !== null ? 1 : 0,
                'groupid' => $product === null ? 0 : (int) $product->gid,
                'promoid' => (int) $host->promoid,
                'groupn' => $menu ?? ['id' => 0, 'groupname' => '', 'fa_icon' => '', 'order' => 0],
                'flags' => $sale !== null ? 1 : 0,
                'saleproducts' => $sale === null ? 0 : $this->hosts->money($amount - $sale),
                'allow_billingcycle' => array_map(fn ($c) => array_merge($c, [
                    'flags' => $c['saleproducts'] !== '0.00' ? 1 : 0,
                ]), $allowed),
            ];
        }

        return [
            'total' => $this->hosts->money($total),
            'totalsale' => $this->hosts->money($totalSale),
            'currency' => $this->hosts->currencyPayload($currency),
            'hosts' => $rows,
            'hosts_group' => array_values($groupMenus),
        ];
    }

    /**
     * Next due date movement for a renewal starting from the current due date
     * (or now when the host already expired).
     */
    public function nextDueAfter(string $cycle, int $currentDue): int
    {
        $base = $currentDue > time() ? $currentDue : time();
        $next = $this->pricing->nextDueDate($cycle, $base);

        return $next ?? $currentDue;
    }

    /**
     * Create the renewal invoice for one host.
     *
     * @return array{invoice:Invoice, amount:float}
     */
    public function renew(Host $host, string $cycle, ?Client $client = null): array
    {
        $client = $client ?? $host->client;

        if ($client === null) {
            throw new \InvalidArgumentException('订单不存在');
        }

        $amount = $this->hosts->cycleAmount($host, $cycle);

        if ($amount === null) {
            throw new \InvalidArgumentException('该商品不支持所选计费周期');
        }

        $invoice = $this->invoices->create($client, [[
            'type' => 'renew',
            'rel_id' => (int) $host->id,
            'description' => sprintf(
                '%s - %s (续费)',
                $this->hosts->productName($host) ?: ('服务 #' . $host->id),
                HostService::cycleLabel($cycle)
            ),
            'amount' => $amount,
            'taxed' => $host->product === null ? 0 : (int) $host->product->tax,
        ]], time() + 86400 * 7, 'renew');

        // Switching cycles has to be recorded so the cron applies the new
        // recurring amount once the invoice is paid.
        if ($cycle !== (string) $host->billingcycle) {
            RenewCycle::create([
                'uid' => (int) $client->id,
                'type' => 'normal',
                'relid' => (int) $host->id,
                'new_cycle' => $cycle,
                'new_recurring_amount' => $amount,
                'recurringchange' => PricingService::money($amount - (float) $host->amount),
                'status' => 'Pending',
                'paid' => 'N',
                'create_time' => time(),
                'expire_time' => 0,
                'delete_time' => 0,
                'duration' => 0,
            ]);
        }

        return ['invoice' => $invoice, 'amount' => $amount];
    }

    /**
     * Renew several hosts into one invoice.
     *
     * @param  array<int, Host>  $hosts
     * @param  array<int|string, string>  $cycles
     * @return array{invoice:Invoice, amount:float}
     */
    public function renewBatch(array $hosts, array $cycles, Client $client): array
    {
        if ($hosts === []) {
            throw new \InvalidArgumentException('请选择需要续费的产品');
        }

        $items = [];
        $total = 0.0;
        $pending = [];

        foreach ($hosts as $host) {
            $cycle = (string) ($cycles[$host->id] ?? $host->billingcycle);
            $amount = $this->hosts->cycleAmount($host, $cycle);

            if ($amount === null) {
                throw new \InvalidArgumentException(sprintf(
                    '产品 #%d 不支持周期 %s',
                    $host->id,
                    $cycle
                ));
            }

            $items[] = [
                'type' => 'renew',
                'rel_id' => (int) $host->id,
                'description' => sprintf(
                    '%s - %s (批量续费)',
                    $this->hosts->productName($host) ?: ('服务 #' . $host->id),
                    HostService::cycleLabel($cycle)
                ),
                'amount' => $amount,
                'taxed' => $host->product === null ? 0 : (int) $host->product->tax,
            ];

            $total += $amount;
            $pending[] = ['host' => $host, 'cycle' => $cycle, 'amount' => $amount];
        }

        $invoice = $this->invoices->create($client, $items, time() + 86400 * 7, 'renew');

        return ['invoice' => $invoice, 'amount' => PricingService::money($total), 'hosts' => $pending];
    }

    /**
     * Apply a paid renewal to a host: move the due date, persist a cycle
     * change and queue the module's own renewal call.
     */
    public function applyPaidRenewal(Host $host, string $cycle, float $amount): void
    {
        $next = $this->nextDueAfter($cycle, (int) $host->nextduedate);

        $host->billingcycle = $cycle;
        $host->amount = PricingService::money($amount);
        $host->nextduedate = $next;
        $host->nextinvoicedate = $next;
        $host->last_settle = time();
        $host->update_time = time();
        $host->save();

        $pending = RenewCycle::query()
            ->where('relid', $host->id)
            ->where('status', 'Pending')
            ->where('delete_time', 0)
            ->orderByDesc('id')
            ->first();

        if ($pending !== null) {
            $pending->status = 'Completed';
            $pending->paid = 'Y';
            $pending->expire_time = time();
            $pending->save();
        }

        $this->modules->dispatch($host, 'renew', true);
    }

    /**
     * Toggle the automatic balance-renewal flag.
     */
    public function setAutoRenew(Host $host, int $flag): Host
    {
        $host->initiative_renew = $flag === 1 ? 1 : 0;
        $host->update_time = time();
        $host->save();

        return $host;
    }

    /**
     * Hosts in a client's account that are eligible for renewal billing.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, Host>
     */
    public function hostsFor(Client $client, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        if ($ids === []) {
            return [];
        }

        return Host::query()
            ->whereIn('id', $ids)
            ->where('uid', $client->id)
            ->whereIn('domainstatus', [Host::STATUS_ACTIVE, Host::STATUS_SUSPENDED, Host::STATUS_PENDING])
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Unpaid renewal invoices for a host, used to stop duplicate billing.
     */
    public function outstandingFor(Host $host): ?Invoice
    {
        return Invoice::query()
            ->where('uid', $host->uid)
            ->where('status', Invoice::STATUS_UNPAID)
            ->where('is_delete', 0)
            ->whereHas('items', function ($query) use ($host) {
                $query->where('type', 'renew')->where('rel_id', $host->id);
            })
            ->orderByDesc('id')
            ->first();
    }
}
