<?php

namespace Tests\Feature;

use App\Integrations\Idcsmart\FinanceClient;
use App\Integrations\Idcsmart\FinanceClientFactory;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierOperation;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Services\SupplierProvisioningOutbox;
use App\Services\SupplierProvisioningProcessor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;
use Tests\TestCase;

class SupplierProvisioningCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        config()->set('app.url', 'https://billing.kjaiu.test');
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_command_selects_only_due_queued_provisions_in_stable_order(): void
    {
        $account = $this->account('process-selector');
        $first = $this->operation($account, 'first', availableAt: now()->subSecond());
        $second = $this->operation($account, 'second', availableAt: now());
        $this->operation($account, 'future', availableAt: now()->addMinute());
        $this->operation($account, 'renew', action: SupplierOperation::ACTION_RENEW);
        $manualReview = $this->operation(
            $account,
            'manual-payment-review',
            status: SupplierOperation::STATUS_BLOCKED_CREDIT,
        );
        $manualReview->update([
            'step' => 'awaiting_manual_supplier_payment',
            'last_error_code' => 'legacy_payment_review_required',
        ]);
        foreach ([
            SupplierOperation::STATUS_RUNNING,
            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            SupplierOperation::STATUS_AMBIGUOUS,
            SupplierOperation::STATUS_FAILED,
            SupplierOperation::STATUS_SUCCEEDED,
        ] as $status) {
            $this->operation($account, 'excluded-'.$status, status: $status);
        }
        $processor = new RecordingSupplierProvisioningProcessor;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        $this->assertSame(0, Artisan::call('kjaiu:supplier-process', ['--limit' => 20]));

        $this->assertSame([$first->id, $second->id], $processor->processed);
        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $manualReview->fresh()->status,
        );
        $this->assertStringContainsString(
            'Selected 2 provision operation(s); statuses: succeeded=2; errors=0.',
            Artisan::output(),
        );

        $this->assertSame(0, Artisan::call('kjaiu:supplier-process', ['--limit' => 20]));
        $this->assertSame([$first->id, $second->id], $processor->processed);
    }

    public function test_recovery_command_is_bounded_and_reports_only_state_counts(): void
    {
        $processor = new RecordingSupplierProvisioningProcessor;
        $processor->recoveryResult = [
            'selected' => 4,
            'requeued' => 1,
            'awaiting_confirmation' => 1,
            'ambiguous' => 1,
            'failed' => 0,
            'skipped' => 1,
            'errors' => 0,
        ];
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        $this->assertSame(0, Artisan::call('kjaiu:supplier-recover', ['--limit' => 1000]));

        $this->assertSame([500], $processor->recovered);
        $output = Artisan::output();
        $this->assertStringContainsString(
            'Inspected 4 stale running provision(s); requeued=1, awaiting_confirmation=1, ambiguous=1, failed=0, skipped=1, errors=0.',
            $output,
        );
        foreach (['api-password', 'jwt-command-secret', 'raw-remote-body'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        $processor->recovered = [];
        Artisan::call('kjaiu:supplier-recover', ['--limit' => 0]);
        $this->assertSame([1], $processor->recovered);
    }

    public function test_recovery_command_returns_failure_when_an_inspection_errors(): void
    {
        $processor = new RecordingSupplierProvisioningProcessor;
        $processor->recoveryResult['errors'] = 1;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        $this->assertSame(1, Artisan::call('kjaiu:supplier-recover'));
        $this->assertStringContainsString('errors=1', Artisan::output());
    }

    public function test_process_command_caps_limit_between_one_and_one_hundred(): void
    {
        $account = $this->account('process-limit');
        $operations = [];
        foreach (range(1, 103) as $index) {
            $operations[] = $this->operation($account, 'process-limit-'.$index);
        }
        $processor = new RecordingSupplierProvisioningProcessor;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        Artisan::call('kjaiu:supplier-process', ['--limit' => 1000]);
        $this->assertSame(
            array_map(fn (SupplierOperation $operation): int => $operation->id, array_slice($operations, 0, 100)),
            $processor->processed,
        );

        $processor->processed = [];
        Artisan::call('kjaiu:supplier-process', ['--limit' => 0]);
        $this->assertSame([$operations[100]->id], $processor->processed);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operations[101]->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operations[102]->fresh()->status);
    }

    public function test_process_command_continues_after_a_failure_and_never_prints_raw_secrets(): void
    {
        $account = $this->account('process-failure');
        $first = $this->operation($account, 'failure-first');
        $second = $this->operation($account, 'failure-second');
        $third = $this->operation($account, 'failure-third');
        $processor = new RecordingSupplierProvisioningProcessor;
        $processor->processFailureId = $first->id;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        $this->assertSame(1, Artisan::call('kjaiu:supplier-process'));

        $this->assertSame([$first->id, $second->id, $third->id], $processor->processed);
        $output = Artisan::output();
        $this->assertStringContainsString(
            "Provision operation {$first->id} failed: error_code=processor_test_failure",
            $output,
        );
        $this->assertStringContainsString('errors=1', $output);
        foreach (['api-password', 'jwt-command-secret', 'raw-remote-body'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_poll_command_selects_only_due_awaiting_provisions(): void
    {
        $account = $this->account('poll-selector');
        $first = $this->operation(
            $account,
            'poll-first',
            status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
        );
        $second = $this->operation(
            $account,
            'poll-second',
            status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
        );
        $this->operation(
            $account,
            'poll-future',
            status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            availableAt: now()->addMinute(),
        );
        $this->operation($account, 'poll-queued');
        $manualReview = $this->operation(
            $account,
            'poll-manual-payment-review',
            status: SupplierOperation::STATUS_BLOCKED_CREDIT,
        );
        $manualReview->update([
            'step' => 'awaiting_manual_supplier_payment',
            'last_error_code' => 'legacy_payment_review_required',
        ]);
        $this->operation(
            $account,
            'poll-renew',
            status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            action: SupplierOperation::ACTION_RENEW,
        );
        $this->operation($account, 'poll-failed', status: SupplierOperation::STATUS_FAILED);
        $processor = new RecordingSupplierProvisioningProcessor;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        $this->assertSame(0, Artisan::call('kjaiu:supplier-poll', ['--limit' => 50]));

        $this->assertSame([$first->id, $second->id], $processor->polled);
        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $manualReview->fresh()->status,
        );
        $this->assertStringContainsString(
            'Selected 2 host confirmation(s); statuses: succeeded=2; errors=0.',
            Artisan::output(),
        );
    }

    public function test_poll_command_continues_after_a_failure_and_returns_failure(): void
    {
        $account = $this->account('poll-failure');
        $first = $this->operation(
            $account,
            'poll-failure-first',
            status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
        );
        $second = $this->operation(
            $account,
            'poll-failure-second',
            status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
        );
        $processor = new RecordingSupplierProvisioningProcessor;
        $processor->pollFailureId = $first->id;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        $this->assertSame(1, Artisan::call('kjaiu:supplier-poll'));

        $this->assertSame([$first->id, $second->id], $processor->polled);
        $output = Artisan::output();
        $this->assertStringContainsString(
            "Provision poll {$first->id} failed: error_code=processor_test_failure",
            $output,
        );
        $this->assertStringContainsString('errors=1', $output);
        foreach (['api-password', 'jwt-command-secret', 'raw-remote-body'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_poll_command_caps_limit_between_one_and_two_hundred(): void
    {
        $account = $this->account('poll-limit');
        $operations = [];
        foreach (range(1, 203) as $index) {
            $operations[] = $this->operation(
                $account,
                'poll-limit-'.$index,
                status: SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            );
        }
        $processor = new RecordingSupplierProvisioningProcessor;
        $this->app->instance(SupplierProvisioningProcessor::class, $processor);

        Artisan::call('kjaiu:supplier-poll', ['--limit' => 1000]);
        $this->assertSame(
            array_map(fn (SupplierOperation $operation): int => $operation->id, array_slice($operations, 0, 200)),
            $processor->polled,
        );

        $processor->polled = [];
        Artisan::call('kjaiu:supplier-poll', ['--limit' => -1]);
        $this->assertSame([$operations[200]->id], $processor->polled);
        $this->assertSame(
            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            $operations[201]->fresh()->status,
        );
        $this->assertSame(
            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            $operations[202]->fresh()->status,
        );
    }

    public function test_repeated_process_command_does_not_repeat_purchase_mutations(): void
    {
        $operation = $this->queuedProvision('command-repeat');
        $paths = [];
        $this->app->instance(FinanceClientFactory::class, new class extends FinanceClientFactory
        {
            public function make(SupplierAccount $account): FinanceClient
            {
                return new FinanceClient($account, fn (): array => ['8.8.8.8']);
            }
        });
        Http::fake(function (Request $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-command-repeat'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 501, 'hostid' => 601]],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertSame(0, Artisan::call('kjaiu:supplier-process'));
        $this->assertSame(0, Artisan::call('kjaiu:supplier-process'));

        $this->assertSame([
            '/zjmf_api_login',
            '/cart/set_config',
            '/cart/get_total',
            '/cart/clear',
            '/cart/add_to_shop',
            '/cart/settle',
            '/apply_credit',
        ], $paths);
        $this->assertSame(
            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            $operation->fresh()->status,
        );
        $this->assertSame(1, $operation->fresh()->attempts);
    }

    public function test_legacy_renewal_reconciliation_is_idempotent_and_performs_no_http(): void
    {
        $account = $this->account('renew-reconciliation');
        $first = $this->operation(
            $account,
            'renew-first',
            action: SupplierOperation::ACTION_RENEW,
        );
        $second = $this->operation(
            $account,
            'renew-future',
            action: SupplierOperation::ACTION_RENEW,
            availableAt: now()->addDay(),
        );
        $terminal = $this->operation(
            $account,
            'renew-terminal',
            status: SupplierOperation::STATUS_SUCCEEDED,
            action: SupplierOperation::ACTION_RENEW,
        );

        $this->assertSame(0, Artisan::call('kjaiu:supplier-reconcile-renewals'));
        $this->assertStringContainsString(
            'Inspected 2 queued legacy supplier renewal(s); reconciled=2, skipped=0, errors=0.',
            Artisan::output(),
        );
        foreach ([$first, $second] as $operation) {
            $operation->refresh();
            $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
            $this->assertSame('unsupported_supplier_renewal', $operation->step);
            $this->assertSame('unsupported_supplier_renewal', $operation->last_error_code);
            $this->assertSame(0, $operation->attempts);
            $this->assertNull($operation->available_at);
            $this->assertNotNull($operation->finished_at);
        }
        $this->assertSame(SupplierOperation::STATUS_SUCCEEDED, $terminal->fresh()->status);
        $this->assertSame(
            2,
            AuditLog::query()
                ->where('action', 'supplier.renewal.unsupported_reconciled')
                ->count(),
        );
        Http::assertNothingSent();

        $this->assertSame(0, Artisan::call('kjaiu:supplier-reconcile-renewals'));
        $this->assertStringContainsString('Inspected 0 queued legacy supplier renewal(s)', Artisan::output());
        $account->update(['base_url' => 'https://supplier-renew-reconciled.test']);
        $this->assertSame('https://supplier-renew-reconciled.test', $account->fresh()->base_url);
        Http::assertNothingSent();
    }

    public function test_schedule_list_contains_foreground_non_overlapping_supplier_commands(): void
    {
        $this->assertSame(0, Artisan::call('schedule:list'));
        $output = Artisan::output();
        $commands = [
            'kjaiu:supplier-reconcile-renewals',
            'kjaiu:supplier-recover',
            'kjaiu:supplier-process',
            'kjaiu:supplier-poll',
        ];
        $scheduled = collect(Schedule::events())
            ->map(fn ($event): ?string => collect($commands)
                ->first(fn (string $command): bool => str_contains((string) $event->command, $command)))
            ->filter()
            ->values()
            ->all();
        $this->assertSame($commands, $scheduled);

        foreach ($commands as $command) {
            $this->assertStringContainsString($command, $output);
            $event = collect(Schedule::events())
                ->first(fn ($event): bool => str_contains((string) $event->command, $command));
            $this->assertNotNull($event);
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertFalse($event->runInBackground);
        }
    }

    private function account(string $suffix): SupplierAccount
    {
        return SupplierAccount::create([
            'code' => 'command-'.$suffix,
            'name' => 'Command Supplier '.$suffix,
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier-'.$suffix.'.test',
            'credentials' => ['username' => 'api-user', 'password' => 'api-password'],
            'options' => ['allow_legacy_unbounded_credit_payment' => true],
            'is_active' => true,
        ]);
    }

    private function operation(
        SupplierAccount $account,
        string $suffix,
        string $status = SupplierOperation::STATUS_QUEUED,
        string $action = SupplierOperation::ACTION_PROVISION,
        mixed $availableAt = null,
    ): SupplierOperation {
        return SupplierOperation::createFor($account, [
            'action' => $action,
            'status' => $status,
            'step' => 'command-test',
            'idempotency_key' => 'command:'.$suffix,
            'request_payload' => ['marker' => $suffix],
            'attempts' => 0,
            'available_at' => $availableAt,
        ]);
    }

    private function queuedProvision(string $suffix): SupplierOperation
    {
        $group = ProductGroup::create(['name' => 'Group '.$suffix]);
        $product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Product '.$suffix,
            'type' => 'cloud',
            'billing_cycle' => 'monthly',
            'price' => '10.00',
            'setup_fee' => '0.00',
            'stock_control' => false,
            'auto_setup' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'Paid',
            'total' => '10.00',
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => '10.00',
            'setup_fee' => '0.00',
            'amount' => '10.00',
            'configuration' => [],
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'number' => 'CMD-'.strtoupper(substr(hash('sha256', $suffix), 0, 20)),
            'status' => 'Paid',
            'total' => '10.00',
            'paid_at' => now(),
        ]);
        $service = Service::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'unit_index' => 0,
            'name' => 'Service '.$suffix,
            'status' => 'Pending',
            'billing_cycle' => 'monthly',
            'registered_at' => now(),
        ]);
        $account = $this->account($suffix);
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'upstream-'.$suffix,
            'name' => 'Upstream '.$suffix,
            'currency' => 'CNY',
            'billing_cycles' => ['month'],
            'metadata' => [
                'prices' => [
                    'month' => ['price' => '10.00', 'setup_fee' => '0.00'],
                ],
            ],
            'is_active' => true,
        ]);
        $mapping = SupplierProductMapping::createFor($account, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'month',
            'options' => ['configoption' => ['image' => 'ubuntu']],
            'is_active' => true,
        ]);

        return DB::transaction(function () use ($invoice, $orderItem, $service, $mapping): SupplierOperation {
            $outbox = app(SupplierProvisioningOutbox::class);
            $mapping->load(['account', 'catalogProduct']);
            $route = $outbox->freezeRoute($orderItem, $mapping, 'CNY');

            return $outbox->queueProvision(
                $invoice,
                $orderItem,
                $service,
                $route,
            );
        });
    }

    private function jsonResponse(array $payload, int $status = 200)
    {
        return Http::response($payload, $status, ['Content-Type' => 'application/json']);
    }
}

class RecordingSupplierProvisioningProcessor extends SupplierProvisioningProcessor
{
    public array $recovered = [];

    public array $processed = [];

    public array $polled = [];

    public array $recoveryResult = [
        'selected' => 0,
        'requeued' => 0,
        'awaiting_confirmation' => 0,
        'ambiguous' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public ?int $processFailureId = null;

    public ?int $pollFailureId = null;

    public function __construct() {}

    public function recoverStaleRunning(int $limit = 100): array
    {
        $this->recovered[] = $limit;

        return $this->recoveryResult;
    }

    public function process(int $operationId): bool
    {
        $this->processed[] = $operationId;
        $operation = SupplierOperation::query()->findOrFail($operationId);
        if ($operationId === $this->processFailureId) {
            $operation->update(['last_error_code' => 'processor_test_failure']);

            throw new RuntimeException(
                'password=api-password Bearer jwt-command-secret raw-remote-body',
            );
        }

        $operation->update([
            'status' => SupplierOperation::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);

        return true;
    }

    public function poll(int $operationId): bool
    {
        $this->polled[] = $operationId;
        $operation = SupplierOperation::query()->findOrFail($operationId);
        if ($operationId === $this->pollFailureId) {
            $operation->update(['last_error_code' => 'processor_test_failure']);

            throw new RuntimeException(
                'password=api-password Bearer jwt-command-secret raw-remote-body',
            );
        }

        $operation->update([
            'status' => SupplierOperation::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);

        return true;
    }
}
