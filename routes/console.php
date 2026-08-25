<?php

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('kjaiu:jwt-key {--force : Replace an existing JWT secret}', function () {
    $path = base_path('.env');
    if (! is_file($path)) {
        $this->error('.env does not exist. Copy .env.example first.');

        return 1;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $this->error('Unable to read .env.');

        return 1;
    }

    if (! $this->option('force') && preg_match('/^KJAIU_JWT_SECRET=(.+)$/m', $contents)) {
        $this->info('KJAIU_JWT_SECRET is already configured.');

        return 0;
    }

    $line = 'KJAIU_JWT_SECRET=base64:'.base64_encode(random_bytes(32));
    $updated = preg_match('/^KJAIU_JWT_SECRET=.*$/m', $contents)
        ? preg_replace('/^KJAIU_JWT_SECRET=.*$/m', $line, $contents)
        : rtrim($contents).PHP_EOL.$line.PHP_EOL;

    if ($updated === null || file_put_contents($path, $updated, LOCK_EX) === false) {
        $this->error('Unable to update .env.');

        return 1;
    }

    $this->call('config:clear');
    $this->info('KJAIU_JWT_SECRET generated.');

    return 0;
})->purpose('Generate the independent HS256 API token secret');

Artisan::command('kjaiu:expire-invoices', function (BillingService $billing) {
    $count = 0;
    Invoice::query()
        ->where('status', 'Unpaid')
        ->where('due_at', '<', now())
        ->orderBy('id')
        ->chunkById(100, function ($invoices) use ($billing, &$count) {
            foreach ($invoices as $invoice) {
                try {
                    $changed = false;
                    $billing->cancelInvoice($invoice, $changed);
                    if ($changed) {
                        $count++;
                    }
                } catch (ValidationException $exception) {
                    $this->warn("Skipped invoice {$invoice->id}: ".$exception->getMessage());
                }
            }
        });

    $this->info("Expired $count invoice(s).");
})->purpose('Cancel expired order invoices and release reserved stock');

Artisan::command('kjaiu:auto-renew', function (BillingService $billing) {
    $renewed = 0;
    $skipped = 0;
    Service::query()
        ->where('auto_renew', true)
        ->where('status', 'Active')
        ->whereNotNull('next_due_at')
        ->where('next_due_at', '<=', now())
        ->orderBy('id')
        ->chunkById(100, function ($services) use ($billing, &$renewed, &$skipped) {
            foreach ($services as $service) {
                try {
                    $user = User::query()->findOrFail($service->user_id);
                    $invoice = $billing->createRenewalInvoice($user, $service, $service->billing_cycle);
                    if ($invoice->status !== 'Paid') {
                        $billing->payWithCredit($user, $invoice);
                    }
                    $renewed++;
                } catch (ValidationException $exception) {
                    logger()->warning('Automatic renewal skipped.', [
                        'service_id' => $service->id,
                        'reason' => $exception->getMessage(),
                    ]);
                    $skipped++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $skipped++;
                }
            }
        });

    $this->info("Renewed $renewed service(s); skipped $skipped.");
})->purpose('Renew eligible services from customer credit');

Schedule::command('kjaiu:expire-invoices')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('kjaiu:auto-renew')->hourly()->withoutOverlapping();
