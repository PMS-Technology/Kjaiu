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
use App\Models\SupplierInvoiceLink;
use App\Models\SupplierOperation;
use App\Models\SupplierProductMapping;
use App\Models\SupplierServiceLink;
use App\Models\User;
use App\Services\SupplierProvisioningOutbox;
use App\Services\SupplierProvisioningProcessor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class SupplierProvisioningProcessorTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://billing.kjaiu.test');
        Cache::flush();
        Http::preventStrayRequests();
        $this->app->instance(FinanceClientFactory::class, new class extends FinanceClientFactory
        {
            public function make(SupplierAccount $account): FinanceClient
            {
                return new FinanceClient($account, fn (): array => ['8.8.8.8']);
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_uses_exact_sequence_outside_transactions_and_is_not_replayed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:30:00'));
        $fixture = $this->queuedProvision('sequence');
        $this->assertTrue($fixture['account']->allowsLegacyUnboundedCreditPayment());
        $requests = [];
        $transactionLevels = [];
        Http::fake(function (Request $request) use (&$requests, &$transactionLevels) {
            $requests[] = [$request->method(), parse_url($request->url(), PHP_URL_PATH), $request->data()];
            $transactionLevels[] = DB::transactionLevel();

            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-sequence'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 501, 'hostid' => [601]]],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $service = $fixture['service']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('awaiting_confirmation', $operation->step);
        $this->assertSame(1, $operation->attempts);
        $this->assertSame('Pending', $service->status);
        $this->assertSame('Paid', $fixture['invoice']->fresh()->status);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $transactionLevels);
        $this->assertSame([
            ['POST', '/zjmf_api_login'],
            ['GET', '/cart/set_config'],
            ['POST', '/cart/get_total'],
            ['POST', '/cart/clear'],
            ['POST', '/cart/add_to_shop'],
            ['POST', '/cart/settle'],
            ['POST', '/apply_credit'],
        ], array_map(fn (array $request): array => array_slice($request, 0, 2), $requests));

        $payload = $operation->request_payload;
        $this->assertSame([
            'pid' => 'upstream-sequence',
            'billingcycle' => 'month',
        ], $requests[1][2]);
        $this->assertSame([
            'pid' => 'upstream-sequence',
            'billingcycle' => 'month',
            'qty' => 1,
            'configoption' => [
                'server' => ['image' => 'ubuntu', 'region' => 'upstream'],
            ],
        ], $requests[2][2]);
        $this->assertSame($payload['correlation'], $requests[3][2]);
        $this->assertSame([
            'downstream_id' => $payload['correlation']['downstream_id'],
            'downstream_token' => $payload['correlation']['downstream_token'],
            'downstream_url' => $payload['correlation']['downstream_url'],
            'pid' => 'upstream-sequence',
            'billingcycle' => 'month',
            'qty' => 1,
            'configoption' => [
                'server' => ['image' => 'ubuntu', 'region' => 'upstream'],
            ],
        ], $requests[4][2]);
        $this->assertStringNotContainsString(
            'customer',
            json_encode($requests[4][2], JSON_THROW_ON_ERROR),
        );
        $this->assertSame($payload['correlation'], $requests[5][2]);
        $this->assertSame([
            'invoiceid' => '501',
            'use_credit' => 1,
            'enough' => 1,
        ], $requests[6][2]);

        $serviceLink = $operation->serviceLink;
        $invoiceLink = $operation->invoiceLink;
        $this->assertSame('601', $serviceLink->upstream_service_id);
        $this->assertSame('Pending', $serviceLink->upstream_status);
        $this->assertSame('501', $invoiceLink->upstream_invoice_id);
        $this->assertSame('Paid', $invoiceLink->upstream_status);
        $this->assertSame($serviceLink->id, $invoiceLink->supplier_service_link_id);
        $this->assertTrue($operation->metadata['payment_confirmed']);
        $this->assertSame(1001, $operation->metadata['payment_application_status']);
        $this->assertSame('501', $operation->metadata['payment_invoice_id']);
        $this->assertSame('10.00', $operation->metadata['quote_evidence']['amount']);
        $this->assertSame('CNY', $operation->metadata['quote_evidence']['currency']);
        $this->assertSame(200, $operation->metadata['quote_evidence']['set_config_status']);
        $this->assertSame(hash('sha256', json_encode(
            $operation->metadata['quote_evidence'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )), $operation->metadata['quote_hash']);
        $audit = AuditLog::query()
            ->where('action', 'supplier.provisioning.awaiting_confirmation')
            ->sole();
        $this->assertNull($audit->actor_id);

        $raw = DB::table('supplier_operations')->where('id', $operation->id)->first();
        $this->assertStringNotContainsString($payload['correlation']['downstream_token'], $raw->request_payload);
        $this->assertStringNotContainsString('jwt-sequence', (string) $raw->response_payload);

        $this->assertFalse($this->processor()->process($operation->id));
        Http::assertSentCount(7);
        $this->assertSame(1, $operation->fresh()->attempts);
    }

    public function test_default_disabled_credit_payment_stops_after_settlement_for_manual_review(): void
    {
        $fixture = $this->queuedProvision(
            'manual-payment-review',
            allowLegacyCreditPayment: false,
        );
        $paths = [];
        Http::fake(function (Request $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-manual-payment-review'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => [
                    'invoiceid' => 'invoice-manual-payment-review',
                    'hostid' => 'host-manual-payment-review',
                ]],
            });
        });

        $this->assertFalse($fixture['account']->allowsLegacyUnboundedCreditPayment());
        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame([
            '/zjmf_api_login',
            '/cart/set_config',
            '/cart/get_total',
            '/cart/clear',
            '/cart/add_to_shop',
            '/cart/settle',
        ], $paths);
        $this->assertSame(SupplierOperation::STATUS_BLOCKED_CREDIT, $operation->status);
        $this->assertSame('awaiting_manual_supplier_payment', $operation->step);
        $this->assertSame('legacy_payment_review_required', $operation->last_error_code);
        $this->assertSame(1, $operation->attempts);
        $this->assertNull($operation->available_at);
        $this->assertSame('invoice-manual-payment-review', $operation->invoiceLink->upstream_invoice_id);
        $this->assertSame('Unpaid', $operation->invoiceLink->upstream_status);
        $this->assertSame('host-manual-payment-review', $operation->serviceLink->upstream_service_id);
        $this->assertSame('Pending', $operation->serviceLink->upstream_status);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertNull($fixture['service']->fresh()->activated_at);
        $this->assertNotTrue($operation->metadata['payment_confirmed'] ?? false);
        Http::assertNotSent(fn (Request $request): bool => parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === '/apply_credit');

        $this->assertFalse($this->processor()->process($operation->id));
        $this->assertFalse($this->processor()->poll($operation->id));
        $this->assertSame(['selected' => 0, 'processed' => 0], $this->processor()->processQueued());
        $this->assertSame(['selected' => 0, 'polled' => 0], $this->processor()->pollAwaiting());
        Http::assertSentCount(6);
    }

    public function test_clear_cart_recovery_skips_add_and_settle(): void
    {
        $fixture = $this->queuedProvision('recovery');
        $paths = [];
        Http::fake(function (Request $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-recovery'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => [
                    'status' => 400,
                    'msg' => 'existing order recovered',
                    'data' => ['invoiceid' => 'invoice-recovered', 'hostid' => ['host-recovered']],
                ],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $this->assertSame([
            '/zjmf_api_login',
            '/cart/set_config',
            '/cart/get_total',
            '/cart/clear',
            '/apply_credit',
        ], $paths);
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('host-recovered', $operation->serviceLink->upstream_service_id);
        $this->assertSame('invoice-recovered', $operation->invoiceLink->upstream_invoice_id);
        $this->assertSame('Paid', $operation->invoiceLink->upstream_status);
        $this->assertTrue($operation->metadata['payment_confirmed']);
    }

    public function test_host_without_an_invoice_is_preserved_but_payment_remains_unconfirmed(): void
    {
        $fixture = $this->queuedProvision('host-without-invoice');
        $paths = [];
        Http::fake(function (Request $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-host-without-invoice'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'data' => ['hostid' => 'host-without-invoice']],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame([
            '/zjmf_api_login',
            '/cart/set_config',
            '/cart/get_total',
            '/cart/clear',
        ], $paths);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('payment_unconfirmed', $operation->last_error_code);
        $this->assertSame('host-without-invoice', $operation->serviceLink->upstream_service_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        $this->assertNotTrue($operation->metadata['payment_confirmed'] ?? false);
        $this->assertFalse($this->processor()->poll($operation->id));
        Http::assertSentCount(4);
    }

    public function test_confirmed_payment_without_a_host_requires_manual_reconciliation(): void
    {
        $fixture = $this->queuedProvision('paid-without-host');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-paid-without-host'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 'invoice-paid-no-host']],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('host_reconciliation_required', $operation->last_error_code);
        $this->assertTrue($operation->metadata['payment_confirmed']);
        $this->assertSame('invoice-paid-no-host', $operation->metadata['payment_invoice_id']);
        $this->assertSame('Paid', $operation->invoiceLink->upstream_status);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->available_at);
        $this->assertFalse($this->processor()->poll($operation->id));
    }

    public function test_settlement_references_are_committed_before_credit_is_attempted(): void
    {
        $fixture = $this->queuedProvision('durable-references');
        Http::fake(function (Request $request) use ($fixture) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/apply_credit') {
                $operation = $fixture['operation']->fresh();
                $this->assertSame('apply_credit_mutation_started', $operation->step);
                $this->assertSame('invoice-durable', $operation->invoiceLink?->upstream_invoice_id);
                $this->assertSame('host-durable', $operation->serviceLink?->upstream_service_id);
            }

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-durable'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => [
                    'status' => 200,
                    'data' => ['invoiceid' => 'invoice-durable', 'hostid' => 'host-durable'],
                ],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $this->assertSame(
            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            $fixture['operation']->fresh()->status,
        );
    }

    public function test_conflicting_settlement_references_fail_closed_before_credit(): void
    {
        $fixture = $this->queuedProvision('conflicting-settlement');
        $paths = [];
        Http::fake(function (Request $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-conflicting-settlement'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => [
                    'status' => 200,
                    'invoiceid' => 'invoice-top',
                    'data' => [
                        'invoiceid' => 'invoice-data',
                        'hostid' => 'host-conflicting-settlement',
                    ],
                ],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame([
            '/zjmf_api_login',
            '/cart/set_config',
            '/cart/get_total',
            '/cart/clear',
            '/cart/add_to_shop',
            '/cart/settle',
        ], $paths);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('supplier_mutation_ambiguous', $operation->last_error_code);
        $this->assertNull($operation->supplier_invoice_link_id);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNotTrue($operation->metadata['payment_confirmed'] ?? false);
        Http::assertNotSent(fn (Request $request): bool => parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === '/apply_credit');
    }

    public function test_invalid_snapshot_fails_before_any_http_request(): void
    {
        $fixture = $this->queuedProvision('invalid-snapshot');
        DB::table('supplier_operations')
            ->where('id', $fixture['operation']->id)
            ->update(['request_hash' => str_repeat('0', 64)]);

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('snapshot_validation_failed', $operation->last_error_code);
        $this->assertSame(1, $operation->attempts);
        $this->assertNull($operation->available_at);
        $this->assertArrayNotHasKey('preflight_failures', $operation->metadata ?? []);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_auth_preflight_failures_back_off_twice_then_fail_without_mutation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        $fixture = $this->queuedProvision('preflight-backoff');
        $paths = [];
        Http::fake(function (Request $request) use (&$paths) {
            $paths[] = parse_url($request->url(), PHP_URL_PATH);

            return $this->jsonResponse([
                'status' => 405,
                'msg' => 'password=api-secret token=jwt-preflight-private authentication failed',
            ]);
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operation->status);
        $this->assertSame('preflight_retry_backoff', $operation->step);
        $this->assertSame('preflight_retry_scheduled', $operation->last_error_code);
        $this->assertSame(1, $operation->attempts);
        $this->assertSame(1, $operation->metadata['preflight_failures']);
        $this->assertSame('2026-08-26 12:01:00', $operation->available_at->format('Y-m-d H:i:s'));
        $this->assertFalse($this->processor()->process($operation->id));
        $this->assertSame(['/zjmf_api_login'], $paths);

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:01:00'));
        $this->assertTrue($this->processor()->process($operation->id));
        $operation->refresh();
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operation->status);
        $this->assertSame(2, $operation->attempts);
        $this->assertSame(2, $operation->metadata['preflight_failures']);
        $this->assertSame('2026-08-26 12:03:00', $operation->available_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:02:59'));
        $this->assertFalse($this->processor()->process($operation->id));
        $this->assertCount(2, $paths);

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:03:00'));
        $this->assertTrue($this->processor()->process($operation->id));
        $operation->refresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('preflight_retry_exhausted', $operation->step);
        $this->assertSame('preflight_retry_exhausted', $operation->last_error_code);
        $this->assertSame(3, $operation->attempts);
        $this->assertSame(3, $operation->metadata['preflight_failures']);
        $this->assertNull($operation->available_at);
        $this->assertNotNull($operation->finished_at);
        $this->assertSame([
            '/zjmf_api_login',
            '/zjmf_api_login',
            '/zjmf_api_login',
        ], $paths);
        foreach (['api-secret', 'jwt-preflight-private'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $operation->last_error);
            $this->assertStringNotContainsString($secret, AuditLog::query()->get()->toJson());
        }
        foreach (['/cart/set_config', '/cart/get_total', '/cart/clear'] as $path) {
            $this->assertNotContains($path, $paths);
        }
    }

    public function test_client_construction_failure_is_safely_deferred_before_http(): void
    {
        $fixture = $this->queuedProvision('client-construction-backoff');
        $fixture['account']->update(['credentials' => []]);

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operation->status);
        $this->assertSame('preflight_retry_scheduled', $operation->last_error_code);
        $this->assertSame(1, $operation->metadata['preflight_failures']);
        $this->assertTrue($operation->available_at->isFuture());
        Http::assertNothingSent();
    }

    public function test_queued_provision_uses_rotated_credentials_with_the_frozen_base_url(): void
    {
        $fixture = $this->queuedProvision('rotated-credentials');
        $baseUrl = $fixture['account']->base_url;
        $fixture['account']->update(['credentials' => [
            'username' => 'rotated-api-user',
            'password' => 'rotated-api-password',
        ]]);
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-rotated-credentials'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 400, 'msg' => 'deterministic rejection'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $this->assertSame($baseUrl, $fixture['account']->fresh()->base_url);
        Http::assertSent(fn (Request $request): bool => parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === '/zjmf_api_login'
            && $request['username'] === 'rotated-api-user'
            && $request['password'] === 'rotated-api-password');
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('clear_cart_rejected', $operation->last_error_code);
        $this->assertArrayNotHasKey('preflight_failures', $operation->metadata ?? []);
    }

    #[DataProvider('invalidQuoteEnvelopes')]
    public function test_invalid_quotes_fail_before_any_supplier_mutation(array $quoteEnvelope): void
    {
        $suffix = 'quote-rejected-'.substr(hash('sha256', serialize($quoteEnvelope)), 0, 12);
        $fixture = $this->queuedProvision($suffix);
        $paths = [];
        Http::fake(function (Request $request) use (&$paths, $quoteEnvelope) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-quote-rejected'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => $quoteEnvelope,
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame([
            '/zjmf_api_login',
            '/cart/set_config',
            '/cart/get_total',
        ], $paths);
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('quote_preflight_failed', $operation->last_error_code);
        $this->assertNull($operation->available_at);
        $this->assertArrayNotHasKey('preflight_failures', $operation->metadata ?? []);
        $this->assertArrayNotHasKey('quote_evidence', $operation->metadata ?? []);
        $this->assertArrayNotHasKey('quote_hash', $operation->metadata ?? []);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        foreach (['/cart/clear', '/cart/add_to_shop', '/cart/settle', '/apply_credit'] as $path) {
            Http::assertNotSent(fn (Request $request): bool => parse_url(
                $request->url(),
                PHP_URL_PATH,
            ) === $path);
        }
    }

    public function test_queued_purchase_uses_the_immutable_mapping_snapshot_after_live_updates(): void
    {
        $fixture = $this->queuedProvision('immutable-routing');
        $snapshot = $fixture['operation']->request_payload['route'];
        $fixture['account']->update(['name' => 'Renamed supplier display']);
        $fixture['catalog']->update(['name' => 'Renamed catalog display']);
        $fixture['product']->update(['name' => 'Renamed local display']);
        DB::table('supplier_product_mappings')->where('id', $fixture['mapping']->id)->update([
            'upstream_billing_cycle' => 'year',
            'options' => json_encode([
                'configoption' => ['server' => ['image' => 'debian', 'region' => 'changed']],
            ], JSON_THROW_ON_ERROR),
            'is_active' => false,
            'updated_at' => now(),
        ]);
        DB::table('supplier_catalog_products')->where('id', $fixture['catalog']->id)->update([
            'upstream_product_id' => 'changed-live-product',
            'is_active' => false,
            'updated_at' => now(),
        ]);
        $addRequest = null;
        Http::fake(function (Request $request) use (&$addRequest) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/cart/add_to_shop') {
                $addRequest = $request->data();
            }

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-immutable-routing'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => [
                    'invoiceid' => 'invoice-immutable-routing',
                    'hostid' => 'host-immutable-routing',
                ]],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $this->assertSame('upstream-immutable-routing', $addRequest['pid']);
        $this->assertSame('month', $addRequest['billingcycle']);
        $this->assertSame([
            'server' => ['image' => 'ubuntu', 'region' => 'upstream'],
        ], $addRequest['configoption']);
        $this->assertSame($snapshot, $fixture['operation']->fresh()->request_payload['route']);
        $this->assertSame(
            SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            $fixture['operation']->fresh()->status,
        );
    }

    public function test_snapshot_reference_corruption_with_a_matching_hash_fails_before_http(): void
    {
        $fixture = $this->queuedProvision('corrupt-reference');
        $payload = $fixture['operation']->request_payload;
        $payload['route']['mapping']['supplier_catalog_product_id'] = 999999;
        $fixture['operation']->update([
            'request_payload' => $payload,
            'request_hash' => hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ]);

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('snapshot_validation_failed', $operation->last_error_code);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        Http::assertNothingSent();
    }

    public function test_live_order_item_ownership_corruption_fails_before_http(): void
    {
        $fixture = $this->queuedProvision('corrupt-order-item');
        $otherOrder = Order::create([
            'user_id' => $fixture['user']->id,
            'status' => 'Paid',
            'total' => '10.00',
        ]);
        DB::table('order_items')->where('id', $fixture['orderItem']->id)->update([
            'order_id' => $otherOrder->id,
        ]);

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('snapshot_validation_failed', $operation->last_error_code);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        Http::assertNothingSent();
    }

    public function test_stale_preflight_claim_is_requeued_after_the_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $stale = $this->queuedProvision('stale-preflight');
        $recent = $this->queuedProvision('recent-preflight');
        $this->markRunning($stale['operation'], 'validation', stale: true);
        $this->markRunning($recent['operation'], 'validation', stale: false);

        $result = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $result['selected']);
        $this->assertSame(1, $result['requeued']);
        $this->assertSame(0, $result['ambiguous']);
        $operation = $stale['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operation->status);
        $this->assertSame('queued', $operation->step);
        $this->assertSame(1, $operation->attempts);
        $this->assertNull($operation->started_at);
        $this->assertSame(SupplierOperation::STATUS_RUNNING, $recent['operation']->fresh()->status);
        $audit = AuditLog::query()->where('action', 'supplier.provisioning.stale_recovered')->sole();
        $this->assertSame([
            'status' => SupplierOperation::STATUS_QUEUED,
            'outcome' => 'preflight_requeued',
        ], $audit->after);
        Http::assertNothingSent();
    }

    public function test_stale_preflight_with_a_payment_marker_is_never_requeued(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $fixture = $this->queuedProvision('stale-preflight-payment-marker');
        $this->markRunning(
            $fixture['operation'],
            'validation',
            stale: true,
            attributes: [
                'metadata' => array_replace($fixture['operation']->metadata ?? [], [
                    'payment_confirmed' => true,
                ]),
            ],
        );

        $result = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $result['selected']);
        $this->assertSame(0, $result['requeued']);
        $this->assertSame(1, $result['ambiguous']);
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('stale_running_mutation_ambiguous', $operation->last_error_code);
        $this->assertTrue($operation->metadata['payment_confirmed']);
        Http::assertNothingSent();
    }

    public function test_stale_post_mutation_claim_becomes_ambiguous_without_replay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $fixture = $this->queuedProvision('stale-mutation');
        $this->markRunning(
            $fixture['operation'],
            'settle_cart_mutation_completed',
            stale: true,
            attributes: [
                'response_payload' => [
                    'endpoint' => 'settle_cart',
                    'status' => 200,
                    'invoice_id' => 'invoice-stale-mutation',
                ],
            ],
        );

        $result = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $result['selected']);
        $this->assertSame(1, $result['ambiguous']);
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('stale_running_ambiguous', $operation->step);
        $this->assertSame('stale_running_mutation_ambiguous', $operation->last_error_code);
        $this->assertSame('invoice-stale-mutation', $operation->upstream_reference);
        $this->assertSame('invoice-stale-mutation', $operation->invoiceLink->upstream_invoice_id);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertFalse($this->processor()->process($operation->id));
        $this->assertSame(1, $operation->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_stale_claim_with_non_scalar_reference_evidence_is_ambiguous(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $fixture = $this->queuedProvision('stale-invalid-reference');
        $this->markRunning(
            $fixture['operation'],
            'settle_cart_mutation_completed',
            stale: true,
            attributes: [
                'response_payload' => [
                    'endpoint' => 'settle_cart',
                    'status' => 200,
                    'host_id' => ['host-one', 'host-two'],
                ],
            ],
        );

        $result = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $result['selected']);
        $this->assertSame(1, $result['ambiguous']);
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('stale_recovery_reference_invalid', $operation->last_error_code);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        Http::assertNothingSent();
    }

    public function test_stale_claim_with_a_known_host_moves_to_safe_confirmation_polling(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $fixture = $this->queuedProvision('stale-known-host');
        $this->markRunning(
            $fixture['operation'],
            'apply_credit_mutation_completed',
            stale: true,
            attributes: [
                'upstream_reference' => 'invoice-stale-known-host',
                'response_payload' => [
                    'endpoint' => 'apply_credit',
                    'status' => 1001,
                    'invoice_id' => 'invoice-stale-known-host',
                    'host_id' => 'host-stale-known-host',
                ],
            ],
        );

        $result = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $result['selected']);
        $this->assertSame(1, $result['awaiting_confirmation']);
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('awaiting_confirmation', $operation->step);
        $this->assertSame('invoice-stale-known-host', $operation->upstream_reference);
        $this->assertSame('host-stale-known-host', $operation->serviceLink->upstream_service_id);
        $this->assertSame('invoice-stale-known-host', $operation->invoiceLink->upstream_invoice_id);
        $this->assertSame($operation->serviceLink->id, $operation->invoiceLink->supplier_service_link_id);
        $audit = AuditLog::query()->where('action', 'supplier.provisioning.stale_recovered')->sole();
        $this->assertSame([
            'status' => SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            'outcome' => 'known_host_awaiting_confirmation',
        ], $audit->after);
        $this->assertFalse($this->processor()->process($operation->id));
        Http::assertNothingSent();
    }

    public function test_stale_known_host_without_payment_confirmation_is_not_polled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $fixture = $this->queuedProvision('stale-host-unpaid');
        $this->markRunning(
            $fixture['operation'],
            'settle_cart_mutation_completed',
            stale: true,
            attributes: [
                'response_payload' => [
                    'endpoint' => 'settle_cart',
                    'status' => 200,
                    'invoice_id' => 'invoice-stale-host-unpaid',
                    'host_id' => 'host-stale-host-unpaid',
                ],
            ],
        );

        $result = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $result['ambiguous']);
        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('payment_unconfirmed', $operation->last_error_code);
        $this->assertSame('host-stale-host-unpaid', $operation->serviceLink->upstream_service_id);
        $this->assertSame('Unpaid', $operation->invoiceLink->upstream_status);
        $this->assertNotTrue($operation->metadata['payment_confirmed'] ?? false);
        $this->assertFalse($this->processor()->poll($operation->id));
        Http::assertNothingSent();
    }

    public function test_repeated_stale_recovery_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 16:00:00'));
        $fixture = $this->queuedProvision('stale-repeat');
        $this->markRunning($fixture['operation'], 'apply_credit_mutation_started', stale: true);

        $first = $this->processor()->recoverStaleRunning();
        $second = $this->processor()->recoverStaleRunning();

        $this->assertSame(1, $first['ambiguous']);
        $this->assertSame(0, $second['selected']);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $fixture['operation']->fresh()->status);
        $this->assertSame(1, $fixture['operation']->fresh()->attempts);
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'supplier.provisioning.ambiguous')->count(),
        );
        $this->assertDatabaseCount('supplier_service_links', 0);
        $this->assertDatabaseCount('supplier_invoice_links', 0);
        Http::assertNothingSent();
    }

    public function test_a_late_mutation_response_cannot_overwrite_a_recovered_claim(): void
    {
        $fixture = $this->queuedProvision('late-response');
        Http::fake(function (Request $request) use ($fixture) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/cart/clear') {
                DB::table('supplier_operations')->where('id', $fixture['operation']->id)->update([
                    'status' => SupplierOperation::STATUS_AMBIGUOUS,
                    'step' => 'stale_running_ambiguous',
                    'last_error_code' => 'stale_running_mutation_ambiguous',
                    'last_error' => 'A stale supplier mutation will not be replayed.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-late-response'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => [
                    'status' => 200,
                    'data' => ['invoiceid' => 'invoice-late', 'hostid' => 'host-late'],
                ],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('stale_running_ambiguous', $operation->step);
        $this->assertSame('stale_running_mutation_ambiguous', $operation->last_error_code);
        $this->assertNull($operation->upstream_reference);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->supplier_invoice_link_id);
        $this->assertDatabaseCount('supplier_service_links', 0);
        $this->assertDatabaseCount('supplier_invoice_links', 0);
        Http::assertSentCount(4);
    }

    public function test_explicit_insufficient_credit_is_blocked_and_excluded_from_future_sweeps(): void
    {
        $fixture = $this->queuedProvision('credit');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-credit'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 701, 'hostid' => 801]],
                '/apply_credit' => ['status' => 400, 'msg' => 'Insufficient credit balance'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_BLOCKED_CREDIT, $operation->status);
        $this->assertSame('upstream_credit_insufficient', $operation->last_error_code);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertSame('Paid', $fixture['invoice']->fresh()->status);
        $this->assertSame('Unpaid', $operation->invoiceLink->upstream_status);
        Http::assertSentCount(7);

        $this->assertFalse($this->processor()->process($operation->id));
        Http::assertSentCount(7);
        $this->assertSame(1, $operation->fresh()->attempts);
    }

    public function test_generic_credit_status_200_is_not_payment_proof_and_is_not_replayed(): void
    {
        $fixture = $this->queuedProvision('credit-unknown');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-credit-unknown'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 702, 'hostid' => 802]],
                '/apply_credit' => ['status' => 200, 'msg' => 'Request accepted'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('credit_outcome_unknown', $operation->last_error_code);
        $this->assertSame('702', $operation->invoiceLink->upstream_invoice_id);
        $this->assertSame('Unpaid', $operation->invoiceLink->upstream_status);
        $this->assertNotTrue($operation->metadata['payment_confirmed'] ?? false);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        Http::assertSentCount(7);

        $this->assertFalse($this->processor()->process($operation->id));
        Http::assertSentCount(7);
        $this->assertSame(1, $operation->fresh()->attempts);
    }

    public function test_mutation_auth_failure_is_ambiguous_sanitized_and_never_replayed(): void
    {
        $fixture = $this->queuedProvision('auth');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-auth-secret'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 401, 'msg' => 'token=jwt-auth-secret expired'],
            });
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('supplier_mutation_ambiguous', $operation->last_error_code);
        $this->assertNull($operation->available_at);
        $this->assertArrayNotHasKey('preflight_failures', $operation->metadata ?? []);
        $this->assertStringNotContainsString('jwt-auth-secret', (string) $operation->last_error);
        $this->assertSame('Paid', $fixture['invoice']->fresh()->status);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        Http::assertSentCount(4);

        $this->assertFalse($this->processor()->process($operation->id));
        Http::assertSentCount(4);
        $this->assertSame(1, $operation->fresh()->attempts);
    }

    public function test_unknown_http_outcome_after_mutation_is_ambiguous_without_replay(): void
    {
        $fixture = $this->queuedProvision('unknown');
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => $this->jsonResponse(['status' => 200, 'jwt' => 'jwt-unknown']),
                '/cart/set_config' => $this->jsonResponse(['status' => 200, 'product' => []]),
                '/cart/get_total' => $this->jsonResponse([
                    'status' => 200,
                    'sale_total' => '10.00',
                    'currency' => 'CNY',
                ]),
                '/cart/clear' => $this->jsonResponse(['status' => 200, 'msg' => 'cart cleared']),
                '/cart/add_to_shop' => $this->jsonResponse(['status' => 200, 'msg' => 'product added']),
                '/cart/settle' => Http::response(
                    '<html>gateway timeout</html>',
                    504,
                    ['Content-Type' => 'text/html'],
                ),
            };
        });

        $this->assertTrue($this->processor()->process($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('supplier_mutation_ambiguous', $operation->last_error_code);
        $this->assertSame('Paid', $fixture['invoice']->fresh()->status);
        Http::assertSentCount(6);
        $this->assertFalse($this->processor()->process($operation->id));
        Http::assertSentCount(6);
    }

    public function test_matching_host_identity_can_activate_only_after_confirmed_safe_read(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:30:00'));
        $fixture = $this->awaitingProvision('active');
        $pendingService = $fixture['service']->fresh();
        $this->assertNull($pendingService->activated_at);
        $this->assertNull($pendingService->billing_anchor_day);
        $this->assertNull($pendingService->next_due_at);

        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));
        $transactionLevels = [];
        Http::fake(function (Request $request) use (&$transactionLevels) {
            $transactionLevels[] = DB::transactionLevel();

            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-poll'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-active',
                    'domainstatus' => 'Active',
                    'domain' => 'node.example.com',
                    'dedicatedip' => '203.0.113.10',
                    'assignedips' => '203.0.113.11, invalid, 2001:4860:4860::8888',
                    'password' => 'must-not-persist',
                ]]],
            });
        });

        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $service = $fixture['service']->fresh();
        $operation = $fixture['operation']->fresh();
        $this->assertSame([0, 0], $transactionLevels);
        $this->assertSame('Active', $service->status);
        $this->assertSame('2026-08-31 14:00:00', $service->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame(31, $service->billing_anchor_day);
        $this->assertSame('2026-09-30 14:00:00', $service->next_due_at->format('Y-m-d H:i:s'));
        $this->assertSame('node.example.com', $service->domain);
        $this->assertSame('203.0.113.10', $service->dedicated_ip);
        $this->assertSame([
            '203.0.113.11',
            '2001:4860:4860::8888',
        ], $service->assigned_ips);
        $this->assertSame(SupplierOperation::STATUS_SUCCEEDED, $operation->status);
        $this->assertSame('host_confirmed_active', $operation->step);
        $this->assertStringNotContainsString(
            'must-not-persist',
            json_encode($operation->response_payload).json_encode($operation->serviceLink->metadata),
        );
    }

    #[DataProvider('unverifiedPollHostIdentities')]
    public function test_unverified_host_identity_fails_closed_without_persistence_or_retry(
        array $identity,
    ): void {
        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));
        $suffix = 'unverified-'.substr(hash('sha256', serialize($identity)), 0, 12);
        $fixture = $this->awaitingProvision($suffix);
        $fixture['service']->update([
            'domain' => 'original.example.com',
            'dedicated_ip' => '198.51.100.10',
            'assigned_ips' => ['198.51.100.11'],
            'registered_at' => Carbon::parse('2026-07-01 09:00:00'),
            'activated_at' => null,
            'next_due_at' => null,
        ]);
        $fixture['serviceLink']->update([
            'metadata' => ['source' => 'settlement'],
            'synced_at' => Carbon::parse('2026-08-01 09:00:00'),
        ]);
        $fixture['operation']->update([
            'response_payload' => ['endpoint' => 'apply_credit', 'status' => 1001],
        ]);
        $operationBefore = $fixture['operation']->fresh();
        $expectedOperationMetadata = array_replace(
            $operationBefore->metadata ?? [],
            ['poll_attempts' => 1],
        );
        $paths = [];
        Http::fake(function (Request $request) use ($identity, &$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-unverified-host'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => $identity + [
                    'domainstatus' => 'Active',
                    'domain' => 'mismatch.example.com',
                    'dedicatedip' => '203.0.113.90',
                    'assignedips' => '203.0.113.91',
                    'regdate' => '2026-08-01 10:00:00',
                    'nextduedate' => '2026-10-01 10:00:00',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $service = $fixture['service']->fresh();
        $serviceLink = $fixture['serviceLink']->fresh();
        $this->assertSame(['/zjmf_api_login', '/host/header'], $paths);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('host_identity_unverified', $operation->step);
        $this->assertSame('host_identity_unverified', $operation->last_error_code);
        $this->assertNull($operation->available_at);
        $this->assertNotNull($operation->finished_at);
        $this->assertSame($expectedOperationMetadata, $operation->metadata);
        $this->assertSame(['endpoint' => 'apply_credit', 'status' => 1001], $operation->response_payload);
        $this->assertSame('Pending', $service->status);
        $this->assertSame('original.example.com', $service->domain);
        $this->assertSame('198.51.100.10', $service->dedicated_ip);
        $this->assertSame(['198.51.100.11'], $service->assigned_ips);
        $this->assertSame('2026-07-01 09:00:00', $service->registered_at->format('Y-m-d H:i:s'));
        $this->assertNull($service->activated_at);
        $this->assertNull($service->next_due_at);
        $this->assertSame('Pending', $serviceLink->upstream_status);
        $this->assertSame('host-'.$suffix, $serviceLink->upstream_service_id);
        $this->assertSame(['source' => 'settlement'], $serviceLink->metadata);
        $this->assertSame('2026-08-01 09:00:00', $serviceLink->synced_at->format('Y-m-d H:i:s'));
        $this->assertSame('Paid', $fixture['invoice']->fresh()->status);
        $this->assertSame('Paid', $fixture['invoiceLink']->fresh()->upstream_status);
        $persisted = json_encode([
            $service->toArray(),
            $serviceLink->metadata,
            $operation->response_payload,
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'mismatch.example.com',
            '203.0.113.90',
            '203.0.113.91',
            '2026-08-01 10:00:00',
            '2026-10-01 10:00:00',
        ] as $foreignHostValue) {
            $this->assertStringNotContainsString($foreignHostValue, $persisted);
        }

        $this->assertFalse($this->processor()->poll($operation->id));
        $this->assertSame(['/zjmf_api_login', '/host/header'], $paths);
        foreach (['/apply_credit', '/cart/clear', '/cart/add_to_shop', '/cart/settle'] as $path) {
            $this->assertNotContains($path, $paths);
        }
    }

    public function test_payment_evidence_removed_after_poll_claim_prevents_activation(): void
    {
        $fixture = $this->awaitingProvision('payment-race');
        Http::fake(function (Request $request) use ($fixture) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/host/header') {
                DB::table('supplier_operations')->where('id', $fixture['operation']->id)->update([
                    'metadata' => json_encode(['payment_confirmed' => false], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-payment-race'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-payment-race',
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('payment_unconfirmed', $operation->last_error_code);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertNull($fixture['service']->fresh()->activated_at);
    }

    public function test_upstream_host_term_dates_align_local_activation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));
        $fixture = $this->awaitingProvision('upstream-term');
        $nextDueTimestamp = Carbon::parse('2026-10-01 09:15:00')->getTimestamp();
        Http::fake(function (Request $request) use ($nextDueTimestamp) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-upstream-term'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-upstream-term',
                    'domainstatus' => 'Active',
                    'regdate' => '2026-08-01T01:15:00+00:00',
                    'nextduedate' => $nextDueTimestamp,
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $service = $fixture['service']->fresh();
        $this->assertSame('Active', $service->status);
        $this->assertSame('2026-08-01 09:15:00', $service->registered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01 09:15:00', $service->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 09:15:00', $service->next_due_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $service->billing_anchor_day);
    }

    public function test_activation_without_upstream_registration_sets_local_registration_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));
        $fixture = $this->awaitingProvision('local-registration-fallback');
        $fixture['service']->update(['registered_at' => null]);
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-local-registration'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-local-registration-fallback',
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $service = $fixture['service']->fresh();
        $this->assertSame('Active', $service->status);
        $this->assertSame('2026-08-31 14:00:00', $service->registered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 14:00:00', $service->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 14:00:00', $service->next_due_at->format('Y-m-d H:i:s'));
    }

    #[DataProvider('unsafeHostTerms')]
    public function test_unsafe_upstream_host_term_dates_never_activate(mixed $regdate, mixed $nextDueDate): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));
        $fixture = $this->awaitingProvision('unsafe-term-'.md5(serialize([$regdate, $nextDueDate])));
        Http::fake(function (Request $request) use ($fixture, $regdate, $nextDueDate) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-unsafe-term'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => $fixture['serviceLink']->upstream_service_id,
                    'domainstatus' => 'Active',
                    'regdate' => $regdate,
                    'nextduedate' => $nextDueDate,
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('host_poll_deferred', $operation->step);
        $this->assertSame('host_poll_error', $operation->last_error_code);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertNull($fixture['service']->fresh()->activated_at);
    }

    public function test_host_activation_preserves_an_existing_activation_term(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 14:00:00'));
        $fixture = $this->awaitingProvision('active-idempotent');
        $fixture['service']->update([
            'activated_at' => Carbon::parse('2026-07-31 09:15:00'),
            'billing_anchor_day' => 31,
            'next_due_at' => Carbon::parse('2026-08-31 09:15:00'),
        ]);
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-idempotent-activation'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-active-idempotent',
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $service = $fixture['service']->fresh();
        $this->assertSame('Active', $service->status);
        $this->assertSame('2026-07-31 09:15:00', $service->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame(31, $service->billing_anchor_day);
        $this->assertSame('2026-08-31 09:15:00', $service->next_due_at->format('Y-m-d H:i:s'));
    }

    #[DataProvider('activationTerms')]
    public function test_host_activation_uses_the_same_supported_cycles_as_billing(
        string $billingCycle,
        string $expectedNextDueAt,
    ): void {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:20:30'));
        $fixture = $this->awaitingProvision('cycle-'.$billingCycle, $billingCycle);
        Http::fake(function (Request $request) use ($fixture) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-cycle-activation'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => $fixture['serviceLink']->upstream_service_id,
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $service = $fixture['service']->fresh();
        $this->assertSame('2026-01-31 10:20:30', $service->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame(31, $service->billing_anchor_day);
        $this->assertSame($expectedNextDueAt, $service->next_due_at->format('Y-m-d H:i:s'));
    }

    public function test_pending_host_stays_pending_and_is_scheduled_for_another_bounded_read(): void
    {
        $fixture = $this->awaitingProvision('pending');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-pending'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-pending',
                    'domainstatus' => 'Pending',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('host_pending', $operation->step);
        $this->assertSame(1, $operation->metadata['poll_attempts']);
        $this->assertTrue($operation->available_at->isFuture());
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
    }

    public function test_only_domainstatus_can_activate_a_service(): void
    {
        $fixture = $this->awaitingProvision('status-fallback');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-status-fallback'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-status-fallback',
                    'status' => 'Active',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('host_poll_deferred', $operation->step);
        $this->assertSame('host_status_missing', $operation->last_error_code);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
    }

    #[DataProvider('terminalHostStatuses')]
    public function test_terminal_non_active_host_statuses_map_conservatively(
        string $upstreamStatus,
        string $localStatus,
    ): void {
        $fixture = $this->awaitingProvision(strtolower($upstreamStatus));
        Http::fake(function (Request $request) use ($upstreamStatus) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-terminal'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-'.strtolower($upstreamStatus),
                    'domainstatus' => $upstreamStatus,
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame($localStatus, $fixture['service']->fresh()->status);
        $this->assertSame('upstream_host_'.strtolower($upstreamStatus), $operation->last_error_code);
    }

    public function test_poll_exhaustion_leaves_the_local_service_pending(): void
    {
        $fixture = $this->awaitingProvision('exhaustion');
        $fixture['operation']->update([
            'metadata' => array_replace($fixture['operation']->metadata ?? [], ['poll_attempts' => 9]),
        ]);
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-exhaustion'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-exhaustion',
                    'domainstatus' => 'Pending',
                ]]],
            });
        });

        $this->assertTrue($this->processor()->poll($fixture['operation']->id));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('poll_exhausted', $operation->step);
        $this->assertSame('poll_exhausted', $operation->last_error_code);
        $this->assertSame(10, $operation->metadata['poll_attempts']);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
    }

    public function test_process_sweep_honors_limit_and_excludes_non_provision_or_terminal_states(): void
    {
        $first = $this->queuedProvision('limit-first');
        $second = $this->queuedProvision('limit-second');
        $third = $this->queuedProvision('limit-third');
        $renew = $this->queuedProvision('limit-renew');
        $renew['operation']->update([
            'action' => SupplierOperation::ACTION_RENEW,
            'idempotency_key' => 'renew-excluded-'.$renew['operation']->id,
        ]);
        $ambiguous = $this->queuedProvision('limit-ambiguous');
        $ambiguous['operation']->update(['status' => SupplierOperation::STATUS_AMBIGUOUS]);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-limit'],
                '/cart/set_config' => ['status' => 200, 'product' => []],
                '/cart/get_total' => ['status' => 200, 'sale_total' => '10.00', 'currency' => 'CNY'],
                '/cart/clear' => ['status' => 200, 'msg' => 'cart cleared'],
                '/cart/add_to_shop' => ['status' => 200, 'msg' => 'product added'],
                '/cart/settle' => ['status' => 200, 'data' => [
                    'invoiceid' => 'invoice-'.$request['downstream_id'],
                    'hostid' => 'host-'.$request['downstream_id'],
                ]],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertSame(
            ['selected' => 2, 'processed' => 2],
            $this->processor()->processQueued(2),
        );

        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $first['operation']->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $second['operation']->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $third['operation']->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $renew['operation']->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $ambiguous['operation']->fresh()->status);
        $this->assertSame(0, $third['operation']->fresh()->attempts);
    }

    public function test_poll_sweep_honors_limit_and_only_reads_awaiting_provisions(): void
    {
        $first = $this->awaitingProvision('poll-first');
        $second = $this->awaitingProvision('poll-second');
        $third = $this->awaitingProvision('poll-third');
        $queued = $this->queuedProvision('poll-queued');
        Http::fake(function (Request $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-poll-limit'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => $request['host_id'],
                    'domainstatus' => 'Pending',
                ]]],
            });
        });

        $this->assertSame(
            ['selected' => 2, 'polled' => 2],
            $this->processor()->pollAwaiting(2),
        );

        $this->assertSame(1, $first['operation']->fresh()->metadata['poll_attempts']);
        $this->assertSame(1, $second['operation']->fresh()->metadata['poll_attempts']);
        $this->assertArrayNotHasKey('poll_attempts', $third['operation']->fresh()->metadata ?? []);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $queued['operation']->fresh()->status);
    }

    public static function terminalHostStatuses(): array
    {
        return [
            'failed' => ['Failed', 'Failed'],
            'cancelled' => ['Cancelled', 'Cancelled'],
            'canceled' => ['Canceled', 'Cancelled'],
            'deleted' => ['Deleted', 'Deleted'],
            'suspended' => ['Suspended', 'Suspended'],
        ];
    }

    public static function unverifiedPollHostIdentities(): array
    {
        return [
            'conflicting hostid' => [['hostid' => 'another-host']],
            'missing identity' => [[]],
            'malformed hostid' => [['hostid' => ['not-an-opaque-identifier']]],
        ];
    }

    public static function invalidQuoteEnvelopes(): array
    {
        return [
            'missing payable total' => [[
                'status' => 200,
                'currency' => 'CNY',
            ]],
            'malformed payable total' => [[
                'status' => 200,
                'sale_total' => ['10.00'],
                'currency' => 'CNY',
            ]],
            'conflicting preferred totals' => [[
                'status' => 200,
                'sale_total' => '10.00',
                'currency' => 'CNY',
                'data' => ['sale_total' => '9.00'],
            ]],
            'conflicting fallback totals' => [[
                'status' => 200,
                'total' => '10.00',
                'currency' => 'CNY',
                'products' => ['total' => '9.00'],
            ]],
            'conflicting preferred and fallback totals' => [[
                'status' => 200,
                'sale_total' => '9.00',
                'total' => '10.00',
                'currency' => 'CNY',
            ]],
            'conflicting currencies' => [[
                'status' => 200,
                'sale_total' => '10.00',
                'currency' => 'CNY',
                'data' => ['currency_code' => 'USD'],
            ]],
            'wrong currency' => [[
                'status' => 200,
                'sale_total' => '10.00',
                'currency' => 'USD',
            ]],
            'amount above frozen maximum' => [[
                'status' => 200,
                'sale_total' => '10.01',
                'currency' => 'CNY',
            ]],
        ];
    }

    public static function activationTerms(): array
    {
        return [
            'hourly' => ['hourly', '2026-01-31 11:20:30'],
            'daily' => ['daily', '2026-02-01 10:20:30'],
            'weekly' => ['weekly', '2026-02-07 10:20:30'],
            'monthly' => ['monthly', '2026-02-28 10:20:30'],
            'quarterly' => ['quarterly', '2026-04-30 10:20:30'],
            'semiannually' => ['semiannually', '2026-07-31 10:20:30'],
            'annually' => ['annually', '2027-01-31 10:20:30'],
            'yearly' => ['yearly', '2027-01-31 10:20:30'],
            'biennially' => ['biennially', '2028-01-31 10:20:30'],
            'triennially' => ['triennially', '2029-01-31 10:20:30'],
        ];
    }

    public static function unsafeHostTerms(): array
    {
        return [
            'invalid calendar date' => ['2026-02-30', '2026-10-01'],
            'control character' => ["2026-08-01\0", '2026-10-01'],
            'registration in future' => ['2026-09-01', '2026-10-01'],
            'due date before registration' => ['2026-08-01', '2026-07-01'],
            'timestamp below bound' => [946684799, 1790812800],
            'timestamp above bound' => [1788220800, 4102444801],
        ];
    }

    public function test_account_lock_lease_covers_the_full_request_sequence_without_renewal(): void
    {
        $reflection = new ReflectionClass(SupplierProvisioningProcessor::class);

        $this->assertSame(900, $reflection->getConstant('ACCOUNT_LOCK_SECONDS'));
        $source = file_get_contents(app_path('Services/SupplierProvisioningProcessor.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('->block(', $source);
        $this->assertStringNotContainsString('->extend(', $source);
    }

    private function processor(): SupplierProvisioningProcessor
    {
        return app(SupplierProvisioningProcessor::class);
    }

    private function markRunning(
        SupplierOperation $operation,
        string $step,
        bool $stale,
        array $attributes = [],
    ): void {
        $operation->update($attributes + [
            'status' => SupplierOperation::STATUS_RUNNING,
            'step' => $step,
            'attempts' => 1,
            'available_at' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);
        if ($stale) {
            DB::table('supplier_operations')->where('id', $operation->id)->update([
                'updated_at' => now()->subMinutes(SupplierProvisioningProcessor::STALE_RUNNING_MINUTES + 1),
            ]);
        }
    }

    private function awaitingProvision(string $suffix, string $billingCycle = 'monthly'): array
    {
        $fixture = $this->queuedProvision($suffix, $billingCycle);
        $serviceLink = SupplierServiceLink::createFor(
            $fixture['account'],
            $fixture['service'],
            $fixture['mapping'],
            [
                'upstream_service_id' => 'host-'.$suffix,
                'upstream_status' => 'Pending',
            ],
        );
        $invoiceId = 'invoice-'.$suffix;
        $invoiceLink = SupplierInvoiceLink::createFor(
            $fixture['account'],
            $fixture['invoice'],
            $serviceLink,
            [
                'upstream_invoice_id' => $invoiceId,
                'upstream_status' => 'Paid',
            ],
        );
        $fixture['operation']->serviceLink()->associate($serviceLink);
        $fixture['operation']->invoiceLink()->associate($invoiceLink);
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_AWAITING_CONFIRMATION,
            'step' => 'awaiting_confirmation',
            'upstream_reference' => $invoiceId,
            'metadata' => array_replace($fixture['operation']->metadata ?? [], [
                'payment_confirmed' => true,
                'payment_application_status' => 1001,
                'payment_invoice_id' => $invoiceId,
            ]),
            'available_at' => now(),
        ]);
        $fixture['serviceLink'] = $serviceLink;
        $fixture['invoiceLink'] = $invoiceLink;

        return $fixture;
    }

    private function queuedProvision(
        string $suffix,
        string $billingCycle = 'monthly',
        bool $allowLegacyCreditPayment = true,
    ): array {
        $group = ProductGroup::create(['name' => 'Group '.$suffix]);
        $product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Product '.$suffix,
            'type' => 'cloud',
            'billing_cycle' => $billingCycle,
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
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'unit_price' => '10.00',
            'setup_fee' => '0.00',
            'amount' => '10.00',
            'configuration' => [],
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'number' => 'SUP-'.strtoupper(substr(hash('sha256', $suffix), 0, 20)),
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
            'billing_cycle' => $billingCycle,
            'registered_at' => now(),
        ]);
        $account = SupplierAccount::create([
            'code' => 'supplier-'.$suffix,
            'name' => 'Supplier '.$suffix,
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier-'.$suffix.'.test',
            'credentials' => ['username' => 'api-user', 'password' => 'api-secret'],
            'options' => [
                'allow_legacy_unbounded_credit_payment' => $allowLegacyCreditPayment,
            ],
            'is_active' => true,
        ]);
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
            'local_billing_cycle' => $billingCycle,
            'upstream_billing_cycle' => 'month',
            'options' => [
                'configoption' => [
                    'server' => ['image' => 'ubuntu', 'region' => 'upstream'],
                ],
            ],
            'is_active' => true,
        ]);
        [$route, $operation] = DB::transaction(function () use (
            $invoice,
            $orderItem,
            $service,
            $mapping,
        ): array {
            $outbox = app(SupplierProvisioningOutbox::class);
            $mapping->load(['account', 'catalogProduct']);
            $route = $outbox->freezeRoute($orderItem, $mapping, 'CNY');

            return [$route, $outbox->queueProvision(
                $invoice,
                $orderItem,
                $service,
                $route,
            )];
        });

        return compact(
            'user',
            'product',
            'order',
            'orderItem',
            'invoice',
            'service',
            'account',
            'catalog',
            'mapping',
            'route',
            'operation',
        );
    }

    private function jsonResponse(array $payload, int $status = 200)
    {
        return Http::response($payload, $status, ['Content-Type' => 'application/json']);
    }
}
