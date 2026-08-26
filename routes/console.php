<?php

use App\Models\Invoice;
use App\Models\Service;
use App\Models\SupplierOperation;
use App\Services\BillingService;
use App\Services\SupplierProvisioningProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

$supplierFailureMessage = static function (int $operationId, Throwable $exception): string {
    try {
        $errorCode = SupplierOperation::query()->whereKey($operationId)->value('last_error_code');
    } catch (Throwable) {
        $errorCode = null;
    }

    if (is_string($errorCode) && preg_match('/\A[a-z0-9_.-]{1,64}\z/i', $errorCode)) {
        return 'error_code='.$errorCode;
    }

    return 'exception='.class_basename($exception);
};

$supplierStatusSummary = static function (array $operationIds): string {
    if ($operationIds === []) {
        return 'none';
    }

    $counts = SupplierOperation::query()
        ->whereKey($operationIds)
        ->pluck('status')
        ->countBy();
    $missing = count($operationIds) - $counts->sum();
    if ($missing > 0) {
        $counts->put('missing', $missing);
    }

    return $counts->sortKeys()
        ->map(fn (int $count, string $status): string => $status.'='.$count)
        ->implode(', ');
};

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
                    $billing->autoRenewDueService($service);
                    $renewed++;
                } catch (ValidationException) {
                    logger()->warning('Automatic renewal skipped.', [
                        'service_id' => $service->id,
                        'reason' => 'validation_failed',
                    ]);
                    $skipped++;
                } catch (Throwable $exception) {
                    report($exception);
                    $skipped++;
                }
            }
        });

    $this->info("Renewed $renewed service(s); skipped $skipped.");
})->purpose('Renew eligible services from customer credit');

Artisan::command('kjaiu:supplier-recover {--limit=100 : Maximum stale running provisions to inspect}', function (
    SupplierProvisioningProcessor $processor,
) {
    $limit = max(1, min(500, (int) $this->option('limit')));
    $result = $processor->recoverStaleRunning($limit);

    $this->info(sprintf(
        'Inspected %d stale running provision(s); requeued=%d, awaiting_confirmation=%d, ambiguous=%d, failed=%d, skipped=%d, errors=%d.',
        $result['selected'],
        $result['requeued'],
        $result['awaiting_confirmation'],
        $result['ambiguous'],
        $result['failed'],
        $result['skipped'],
        $result['errors'],
    ));

    return $result['errors'] === 0 ? 0 : 1;
})->purpose('Conservatively recover stale supplier provisioning claims without replaying mutations');

Artisan::command('kjaiu:supplier-reconcile-renewals {--limit=500 : Maximum queued legacy renewals to reconcile}', function (
    SupplierProvisioningProcessor $processor,
) {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $result = $processor->reconcileUnsupportedRenewals($limit);

    $this->info(sprintf(
        'Inspected %d queued legacy supplier renewal(s); reconciled=%d, skipped=%d, errors=%d.',
        $result['selected'],
        $result['reconciled'],
        $result['skipped'],
        $result['errors'],
    ));

    return $result['errors'] === 0 ? 0 : 1;
})->purpose('Fail unsupported queued supplier renewals without charging or supplier HTTP');

Artisan::command('kjaiu:supplier-process {--limit=20 : Maximum queued provisions to process}', function (
    SupplierProvisioningProcessor $processor,
) use ($supplierFailureMessage, $supplierStatusSummary) {
    $limit = max(1, min(100, (int) $this->option('limit')));
    $operationIds = SupplierOperation::query()
        ->where('action', SupplierOperation::ACTION_PROVISION)
        ->where('status', SupplierOperation::STATUS_QUEUED)
        ->where(fn ($query) => $query
            ->whereNull('available_at')
            ->orWhere('available_at', '<=', now()))
        ->orderBy('available_at')
        ->orderBy('id')
        ->limit($limit)
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $errors = 0;

    foreach ($operationIds as $operationId) {
        try {
            $processor->process($operationId);
        } catch (Throwable $exception) {
            $errors++;
            $this->warn("Provision operation {$operationId} failed: ".
                $supplierFailureMessage($operationId, $exception));
        }
    }

    $this->info(sprintf(
        'Selected %d provision operation(s); statuses: %s; errors=%d.',
        count($operationIds),
        $supplierStatusSummary($operationIds),
        $errors,
    ));

    return $errors === 0 ? 0 : 1;
})->purpose('Process queued first-purchase supplier provisioning operations');

Artisan::command('kjaiu:supplier-poll {--limit=50 : Maximum supplier hosts to poll}', function (
    SupplierProvisioningProcessor $processor,
) use ($supplierFailureMessage, $supplierStatusSummary) {
    $limit = max(1, min(200, (int) $this->option('limit')));
    $operationIds = SupplierOperation::query()
        ->where('action', SupplierOperation::ACTION_PROVISION)
        ->where('status', SupplierOperation::STATUS_AWAITING_CONFIRMATION)
        ->where(fn ($query) => $query
            ->whereNull('available_at')
            ->orWhere('available_at', '<=', now()))
        ->orderBy('available_at')
        ->orderBy('id')
        ->limit($limit)
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    $errors = 0;

    foreach ($operationIds as $operationId) {
        try {
            $processor->poll($operationId);
        } catch (Throwable $exception) {
            $errors++;
            $this->warn("Provision poll {$operationId} failed: ".
                $supplierFailureMessage($operationId, $exception));
        }
    }

    $this->info(sprintf(
        'Selected %d host confirmation(s); statuses: %s; errors=%d.',
        count($operationIds),
        $supplierStatusSummary($operationIds),
        $errors,
    ));

    return $errors === 0 ? 0 : 1;
})->purpose('Poll supplier hosts awaiting provisioning confirmation');

Schedule::command('kjaiu:expire-invoices')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('kjaiu:auto-renew')->hourly()->withoutOverlapping();
Schedule::command('kjaiu:supplier-reconcile-renewals')->everyMinute()->withoutOverlapping(120);
Schedule::command('kjaiu:supplier-recover')->everyMinute()->withoutOverlapping(120);
Schedule::command('kjaiu:supplier-process')->everyMinute()->withoutOverlapping(120);
Schedule::command('kjaiu:supplier-poll')->everyMinute()->withoutOverlapping(120);
