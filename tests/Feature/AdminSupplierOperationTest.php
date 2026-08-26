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
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminSupplierOperationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

    public function test_operation_routes_require_an_active_administrator(): void
    {
        $operation = $this->genericOperation($this->account('authorization'), 'authorization');
        $paths = $this->recoveryPaths($operation);

        $this->get('/admin/supplier-operations')->assertRedirect('/login');
        foreach ($paths as $path) {
            $this->post($path)->assertRedirect('/login');
        }

        $client = User::factory()->create(['role' => 'client', 'status' => 'Active']);
        $this->actingAs($client)->get('/admin/supplier-operations')->assertForbidden();
        foreach ($paths as $path) {
            $this->post($path)->assertForbidden();
        }

        $inactiveAdministrator = User::factory()->create([
            'role' => 'admin',
            'status' => 'Suspended',
        ]);
        $this->actingAs($inactiveAdministrator)
            ->get('/admin/supplier-operations')
            ->assertRedirect('/login');

        $this->actingAs($this->administrator())
            ->get('/admin/supplier-operations')
            ->assertOk()
            ->assertSee('上游操作')
            ->assertSee('name="_token"', false);
    }

    public function test_recovery_writes_are_protected_by_csrf_middleware(): void
    {
        $fixture = $this->blockedCreditFixture('csrf');
        $this->actingAs($this->administrator());
        $this->app->instance('env', 'production');

        try {
            foreach ($this->recoveryPaths($fixture['operation']) as $path) {
                $this->post($path, $this->confirmedPayload([
                    'upstream_host_id' => 'host-csrf',
                ]))->assertStatus(419);
            }
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $fixture['operation']->fresh()->status,
        );
        Http::assertNothingSent();
    }

    public function test_operation_recovery_routes_keep_their_required_throttles(): void
    {
        foreach ([
            'admin.supplier-operations.resume-credit' => 'throttle:5,1',
            'admin.supplier-operations.attest-payment' => 'throttle:supplier-sensitive',
            'admin.supplier-operations.recover-poll' => 'throttle:5,1',
            'admin.supplier-operations.reconcile-host' => 'throttle:5,1',
        ] as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertContains($middleware, $route->gatherMiddleware());
        }

        $attestation = Route::getRoutes()->getByName('admin.supplier-operations.attest-payment');
        $this->assertSame(
            'admin/supplier-operations/{supplierOperation}/manual-attestation',
            $attestation->uri(),
        );
        $this->assertSame('manualAttestation', $attestation->getActionMethod());
    }

    public function test_manual_payment_attestation_uses_the_shared_generic_throttle(): void
    {
        $fixture = $this->manualPaymentFixture('attestation-throttle');
        $this->actingAs($this->administrator())
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.31']);

        foreach (range(1, 10) as $attempt) {
            $this->post($this->attestationPath($fixture['operation']), [
                'current_password' => 'wrong-admin-password',
                'confirmation' => '1',
                'upstream_host_id' => 'host-attestation-throttle',
            ])->assertRedirect()->assertSessionHasErrors('current_password');
        }

        $response = $this->post(
            $this->attestationPath($fixture['operation']),
            $this->confirmedPayload(['upstream_host_id' => 'host-attestation-throttle']),
        );
        $response->assertTooManyRequests();
        $this->assertSame('Too many supplier administration attempts.', $response->getContent());
        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $fixture['operation']->fresh()->status,
        );
        Http::assertNothingSent();
    }

    public function test_recovery_requires_current_password_and_explicit_confirmation_without_flashing_secrets(): void
    {
        $fixture = $this->blockedCreditFixture('confirmation');
        $administrator = $this->administrator();
        $wrongPassword = 'wrong-current-password';

        $response = $this->actingAs($administrator)->post(
            $this->creditPath($fixture['operation']),
            [
                '_form' => 'supplier-operation-'.$fixture['operation']->id,
                'current_password' => $wrongPassword,
                'confirmation' => '1',
            ],
        );
        $response->assertSessionHasErrors('current_password')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.confirmation');

        $response = $this->post($this->creditPath($fixture['operation']), [
            '_form' => 'supplier-operation-'.$fixture['operation']->id,
            'current_password' => 'admin-password',
        ]);
        $response->assertSessionHasErrors('confirmation')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.confirmation');

        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $fixture['operation']->fresh()->status,
        );
        Http::assertNothingSent();
        $audits = AuditLog::query()
            ->where('action', 'supplier.operation.credit_resume')
            ->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $payload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($wrongPassword, $payload);
            $this->assertStringNotContainsString('admin-password', $payload);
            $this->assertSame('validation_rejected', $audit->after['outcome']);
            $this->assertSame('[REDACTED]', $audit->user_agent);
        }
    }

    public function test_index_filters_operations_and_never_renders_sensitive_operation_fields(): void
    {
        $alpha = $this->account('filter-alpha', ['name' => 'Filter Alpha']);
        $beta = $this->account('filter-beta', ['name' => 'Filter Beta']);
        $failed = $this->genericOperation(
            $alpha,
            'filter-failed',
            SupplierOperation::STATUS_FAILED,
        );
        $renewed = $this->genericOperation(
            $alpha,
            'filter-renewed',
            SupplierOperation::STATUS_SUCCEEDED,
            SupplierOperation::ACTION_RENEW,
        );
        $otherSupplier = $this->genericOperation(
            $beta,
            'filter-beta',
            SupplierOperation::STATUS_FAILED,
        );
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->get('/admin/supplier-operations?status=succeeded')
            ->assertOk()
            ->assertSee('SUPPLIER OPERATION #'.$renewed->id)
            ->assertDontSee('SUPPLIER OPERATION #'.$failed->id)
            ->assertDontSee('SUPPLIER OPERATION #'.$otherSupplier->id);
        $this->get('/admin/supplier-operations?action=renew')
            ->assertOk()
            ->assertSee('SUPPLIER OPERATION #'.$renewed->id)
            ->assertDontSee('SUPPLIER OPERATION #'.$failed->id);
        $this->get('/admin/supplier-operations?supplier='.$beta->id)
            ->assertOk()
            ->assertSee('SUPPLIER OPERATION #'.$otherSupplier->id)
            ->assertDontSee('SUPPLIER OPERATION #'.$failed->id)
            ->assertDontSee('SUPPLIER OPERATION #'.$renewed->id);

        $fixture = $this->provisionFixture('secret-view');
        $credentials = $fixture['account']->credentials;
        $jwtReference = 'host-eyJabcdefghijk.lmnopqrst.uvwxyzABCD-reference';
        $serviceLink = SupplierServiceLink::createFor(
            $fixture['account'],
            $fixture['service'],
            $fixture['mapping'],
            [
                'upstream_service_id' => $jwtReference,
                'upstream_status' => 'Pending',
                'metadata' => [
                    'password' => 'hidden-host-machine-password',
                    'raw_body' => 'hidden-host-raw-body',
                ],
            ],
        );
        $invoiceLink = SupplierInvoiceLink::createFor(
            $fixture['account'],
            $fixture['invoice'],
            $serviceLink,
            [
                'upstream_order_id' => 'safe-order-reference',
                'upstream_invoice_id' => $credentials['password'],
                'upstream_status' => 'Unpaid',
                'metadata' => ['correlation_token' => 'hidden-invoice-correlation-token'],
            ],
        );
        $this->assertSame([
            'password' => 'hidden-host-machine-password',
            'raw_body' => 'hidden-host-raw-body',
        ], $serviceLink->fresh()->metadata);
        $this->assertSame(
            ['correlation_token' => 'hidden-invoice-correlation-token'],
            $invoiceLink->fresh()->metadata,
        );
        $operation = $fixture['operation'];
        $operation->serviceLink()->associate($serviceLink);
        $operation->invoiceLink()->associate($invoiceLink);
        $operation->update([
            'status' => SupplierOperation::STATUS_FAILED,
            'step' => 'safe_review',
            'idempotency_key' => 'hidden-idempotency-secret',
            'upstream_reference' => 'unsafe-upstream-reference',
            'request_payload' => ['password' => 'request-payload-secret'],
            'response_payload' => [
                'raw_body' => 'raw-response-secret',
                'jwt' => 'response-jwt-secret',
            ],
            'last_error_code' => 'safe_error',
            'last_error' => 'Safe operator summary.',
            'metadata' => ['correlation_token' => 'hidden-correlation-secret'],
        ]);

        $response = $this->get('/admin/supplier-operations?status=failed');
        $response->assertOk()
            ->assertSee('Safe operator summary.')
            ->assertSee('safe-order-reference');
        foreach ([
            $credentials['username'],
            $credentials['password'],
            $jwtReference,
            'hidden-idempotency-secret',
            'unsafe-upstream-reference',
            'request-payload-secret',
            'raw-response-secret',
            'response-jwt-secret',
            'hidden-correlation-secret',
            'hidden-host-machine-password',
            'hidden-host-raw-body',
            'hidden-invoice-correlation-token',
        ] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_operation_view_never_references_raw_or_secret_operation_fields(): void
    {
        $view = file_get_contents(resource_path('views/admin/supplier-operations/_dialog.blade.php'));
        $this->assertIsString($view);

        foreach ([
            '$operation[\'request_payload\']',
            '$operation[\'response_payload\']',
            '$operation[\'metadata\']',
            '$operation[\'credentials\']',
            'correlation_token',
            'raw_body',
            'host_password',
        ] as $unsafeReference) {
            $this->assertStringNotContainsString($unsafeReference, $view);
        }
    }

    public function test_recovery_state_guards_reject_purchase_replay_and_unrelated_polling(): void
    {
        $credit = $this->blockedCreditFixture('state-credit');
        $credit['operation']->update(['status' => SupplierOperation::STATUS_AMBIGUOUS]);
        $poll = $this->blockedCreditFixture('state-poll');
        $poll['operation']->update(['status' => SupplierOperation::STATUS_QUEUED]);
        $poll['service']->update(['status' => 'Suspended']);
        $reconcile = $this->provisionFixture('state-reconcile');
        $reconcile['operation']->update(['status' => SupplierOperation::STATUS_SUCCEEDED]);
        $this->actingAs($this->administrator());

        $this->post($this->creditPath($credit['operation']), $this->confirmedPayload())
            ->assertSessionHasErrors('operation');
        $this->post($this->pollPath($poll['operation']), $this->confirmedPayload())
            ->assertSessionHasErrors('operation');
        $this->post($this->hostPath($reconcile['operation']), $this->confirmedPayload([
            'upstream_host_id' => 'host-state-reconcile',
        ]))->assertSessionHasErrors('operation');

        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $credit['operation']->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $poll['operation']->fresh()->status);
        $this->assertSame('Suspended', $poll['service']->fresh()->status);
        $this->assertSame(SupplierOperation::STATUS_SUCCEEDED, $reconcile['operation']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_blocked_credit_resume_calls_only_apply_credit_for_the_existing_invoice(): void
    {
        $fixture = $this->blockedCreditFixture('resume-credit');
        $administrator = $this->administrator();
        $paths = [];
        $transactionLevels = [];
        Http::fake(function (ClientRequest $request) use (&$paths, &$transactionLevels) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;
            $transactionLevels[] = DB::transactionLevel();

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-resume-credit'],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->assertTrue($fixture['account']->allowsLegacyUnboundedCreditPayment());
        $this->actingAs($administrator)
            ->get('/admin/supplier-operations')
            ->assertOk()
            ->assertSee('兼容自动扣余额')
            ->assertSee('无法原子限制实际扣款金额或币种')
            ->assertSee('action="'.url($this->creditPath($fixture['operation'])).'"', false);
        $this->actingAs($administrator)
            ->post($this->creditPath($fixture['operation']), $this->confirmedPayload())
            ->assertRedirect(route('admin.supplier-operations.index'))
            ->assertSessionHas('success');

        $operation = $fixture['operation']->fresh();
        $this->assertSame([
            '/zjmf_api_login',
            '/apply_credit',
        ], $paths);
        $this->assertSame([0, 0], $transactionLevels);
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('awaiting_confirmation', $operation->step);
        $this->assertSame(1, $operation->attempts);
        $this->assertSame('Paid', $operation->invoiceLink->upstream_status);
        Http::assertSent(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/apply_credit'
            && $request->method() === 'POST'
            && $request->data() === [
                'invoiceid' => 'invoice-resume-credit',
                'use_credit' => 1,
                'enough' => 1,
            ]);
        foreach (['/cart/clear', '/cart/add_to_shop', '/cart/settle'] as $forbiddenPath) {
            Http::assertNotSent(fn (ClientRequest $request): bool => parse_url(
                $request->url(),
                PHP_URL_PATH,
            ) === $forbiddenPath);
        }

        $audit = AuditLog::query()
            ->where('action', 'supplier.operation.credit_resume')
            ->sole();
        $this->assertSame($administrator->id, $audit->actor_id);
        $this->assertSame('processed', $audit->after['outcome']);
        $auditPayload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('admin-password', $auditPayload);
        $this->assertStringNotContainsString('api-password-resume-credit', $auditPayload);
        $this->assertStringNotContainsString('jwt-resume-credit', $auditPayload);
    }

    public function test_credit_resume_is_hidden_and_rejected_when_legacy_policy_is_not_strict_true(): void
    {
        $administrator = $this->administrator();

        foreach ([false, 1, '1'] as $index => $option) {
            $fixture = $this->blockedCreditFixture('resume-disabled-'.$index, $option);
            $this->assertFalse($fixture['account']->allowsLegacyUnboundedCreditPayment());

            $this->actingAs($administrator)
                ->get('/admin/supplier-operations?status=blocked_credit')
                ->assertOk()
                ->assertDontSee('action="'.url($this->creditPath($fixture['operation'])).'"', false);
            $this->post(
                $this->creditPath($fixture['operation']),
                $this->confirmedPayload(),
            )->assertSessionHasErrors('operation');

            $this->assertSame(
                SupplierOperation::STATUS_BLOCKED_CREDIT,
                $fixture['operation']->fresh()->status,
            );
            $this->assertSame('Unpaid', $fixture['invoiceLink']->fresh()->upstream_status);
        }

        Http::assertNothingSent();
    }

    public function test_credit_resume_requires_an_existing_upstream_invoice_id_without_http(): void
    {
        $fixture = $this->blockedCreditFixture('resume-no-invoice');
        $fixture['invoiceLink']->upstream_order_id = 'order-resume-no-invoice';
        $fixture['invoiceLink']->upstream_invoice_id = null;
        $fixture['invoiceLink']->save();

        $this->actingAs($this->administrator())
            ->get('/admin/supplier-operations')
            ->assertOk()
            ->assertDontSee('action="'.url($this->creditPath($fixture['operation'])).'"', false);
        $this->post(
            $this->creditPath($fixture['operation']),
            $this->confirmedPayload(),
        )->assertSessionHasErrors('operation');

        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $fixture['operation']->fresh()->status,
        );
        $this->assertSame('Unpaid', $fixture['invoiceLink']->fresh()->upstream_status);
        Http::assertNothingSent();
    }

    public function test_indeterminate_credit_resume_becomes_ambiguous_without_purchase_replay(): void
    {
        $fixture = $this->blockedCreditFixture('credit-ambiguous');
        $paths = [];
        Http::fake(function (ClientRequest $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return match ($path) {
                '/zjmf_api_login' => $this->jsonResponse([
                    'status' => 200,
                    'jwt' => 'jwt-credit-ambiguous',
                ]),
                '/apply_credit' => Http::response(
                    '<html>gateway timeout raw body</html>',
                    504,
                    ['Content-Type' => 'text/html'],
                ),
            };
        });

        $this->actingAs($this->administrator())
            ->post($this->creditPath($fixture['operation']), $this->confirmedPayload())
            ->assertRedirect(route('admin.supplier-operations.index'));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(['/zjmf_api_login', '/apply_credit'], $paths);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('credit_outcome_unknown', $operation->last_error_code);
        $this->assertStringNotContainsString('raw body', (string) $operation->last_error);
        $this->assertSame('Unpaid', $operation->invoiceLink->upstream_status);

        $this->post($this->creditPath($fixture['operation']), $this->confirmedPayload())
            ->assertSessionHasErrors('operation');
        $this->assertSame(['/zjmf_api_login', '/apply_credit'], $paths);
    }

    public function test_partial_payment_confirmation_marker_blocks_credit_replay(): void
    {
        $fixture = $this->blockedCreditFixture('partial-payment-marker');
        $fixture['operation']->update([
            'metadata' => array_replace($fixture['operation']->metadata ?? [], [
                'payment_confirmed' => true,
            ]),
        ]);

        $this->actingAs($this->administrator())
            ->post($this->creditPath($fixture['operation']), $this->confirmedPayload())
            ->assertSessionHasErrors('operation');

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_BLOCKED_CREDIT, $operation->status);
        $this->assertSame('Unpaid', $operation->invoiceLink->upstream_status);
        $this->assertTrue($operation->metadata['payment_confirmed']);
        Http::assertNothingSent();
    }

    public function test_payment_response_marker_hides_and_blocks_credit_recovery(): void
    {
        $fixture = $this->blockedCreditFixture('payment-response-marker');
        $fixture['operation']->update([
            'response_payload' => [
                'endpoint' => 'apply_credit',
                'status' => 1001,
                'invoice_id' => 'invoice-payment-response-marker',
            ],
        ]);

        $this->actingAs($this->administrator())
            ->get('/admin/supplier-operations')
            ->assertOk()
            ->assertDontSee('兼容自动扣余额');
        $this->post($this->creditPath($fixture['operation']), $this->confirmedPayload())
            ->assertSessionHasErrors('operation');

        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $fixture['operation']->fresh()->status,
        );
        Http::assertNothingSent();
    }

    public function test_successful_credit_without_a_known_host_stays_in_manual_review(): void
    {
        $fixture = $this->blockedCreditWithoutHostFixture('credit-no-host');
        $paths = [];
        Http::fake(function (ClientRequest $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-credit-no-host'],
                '/apply_credit' => ['status' => 1001, 'msg' => 'invoice paid'],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->creditPath($fixture['operation']), $this->confirmedPayload())
            ->assertRedirect(route('admin.supplier-operations.index'));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(['/zjmf_api_login', '/apply_credit'], $paths);
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('ambiguous', $operation->step);
        $this->assertSame('host_reconciliation_required', $operation->last_error_code);
        $this->assertNull($operation->supplier_service_link_id);
        $this->assertNull($operation->available_at);
        $this->assertSame('Paid', $operation->invoiceLink->upstream_status);
        $this->assertTrue($operation->metadata['payment_confirmed']);
        $this->assertSame(1001, $operation->metadata['payment_application_status']);
        $this->assertSame('invoice-credit-no-host', $operation->metadata['payment_invoice_id']);
    }

    public function test_default_manual_payment_can_be_attested_then_only_active_poll_activates_service(): void
    {
        $fixture = $this->manualPaymentFixture('manual-attestation');
        $administrator = $this->administrator();
        $paths = [];
        $transactionLevels = [];
        $hostReads = 0;
        Http::fake(function (ClientRequest $request) use (
            &$paths,
            &$transactionLevels,
            &$hostReads,
        ) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;
            $transactionLevels[] = DB::transactionLevel();

            if ($path === '/host/header') {
                $hostReads++;

                return $this->jsonResponse(['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-manual-attestation',
                    'domainstatus' => $hostReads === 1 ? 'Pending' : 'Active',
                    'domain' => 'manual-attestation.example.com',
                    'password' => 'machine-password-manual-attestation',
                ]]]);
            }

            return $this->jsonResponse([
                'status' => 200,
                'jwt' => 'jwt-manual-attestation',
            ]);
        });

        $this->assertFalse($fixture['account']->allowsLegacyUnboundedCreditPayment());
        $this->actingAs($administrator)
            ->get('/admin/supplier-operations')
            ->assertOk()
            ->assertSee('已在上游人工付款并确认主机')
            ->assertSee('不是密码学付款验证')
            ->assertSee('准确账单、商品、应付金额和币种完全一致')
            ->assertSee('action="'.url($this->attestationPath($fixture['operation'])).'"', false)
            ->assertSee('name="upstream_host_id"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('name="confirmation"', false)
            ->assertDontSee('action="'.url($this->creditPath($fixture['operation'])).'"', false)
            ->assertDontSee('machine-password-manual-attestation');

        $this->post($this->attestationPath($fixture['operation']), $this->confirmedPayload([
            'upstream_host_id' => 'host-manual-attestation',
        ]))->assertRedirect(route('admin.supplier-operations.index'))
            ->assertSessionHas('success');

        $operation = $fixture['operation']->fresh();
        $serviceLink = $operation->serviceLink;
        $metadata = $operation->metadata;
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('awaiting_confirmation', $operation->step);
        $this->assertTrue($operation->available_at->isCurrentSecond());
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertNull($fixture['service']->fresh()->activated_at);
        $this->assertSame('Paid', $fixture['invoiceLink']->fresh()->upstream_status);
        $this->assertSame($fixture['account']->id, $serviceLink->supplier_account_id);
        $this->assertSame($fixture['service']->id, $serviceLink->service_id);
        $this->assertSame($fixture['mapping']->id, $serviceLink->supplier_product_mapping_id);
        $this->assertSame('host-manual-attestation', $serviceLink->upstream_service_id);
        $this->assertSame('Pending', $serviceLink->upstream_status);
        $this->assertTrue($metadata['payment_confirmed']);
        $this->assertSame('admin_attested', $metadata['payment_confirmation']);
        $this->assertSame($administrator->id, $metadata['payment_confirmed_by']);
        $this->assertSame('invoice-manual-attestation', $metadata['payment_invoice_id']);
        $this->assertSame('host-manual-attestation', $metadata['payment_host_id']);
        $this->assertNotEmpty($metadata['payment_confirmed_at']);
        $this->assertArrayNotHasKey('payment_application_status', $metadata);

        $audit = AuditLog::query()
            ->where('action', 'supplier.operation.manual_payment_attested')
            ->sole();
        $this->assertSame($administrator->id, $audit->actor_id);
        $this->assertSame('processed', $audit->after['outcome']);
        $this->assertSame($operation->id, $audit->after['operation_id']);
        $this->assertSame($fixture['account']->id, $audit->after['supplier_account_id']);
        $this->assertSame($fixture['service']->id, $audit->after['service_id']);
        $this->assertSame($fixture['invoice']->id, $audit->after['invoice_id']);
        $this->assertSame($serviceLink->id, $audit->after['supplier_service_link_id']);
        $this->assertSame($fixture['invoiceLink']->id, $audit->after['supplier_invoice_link_id']);
        $this->assertSame('awaiting_confirmation', $audit->after['status']);
        $this->assertSame('Pending', $audit->after['local_service_status']);
        $this->assertSame('Pending', $audit->after['upstream_host_status']);
        $this->assertSame('Paid', $audit->after['upstream_invoice_status']);
        $this->assertTrue($audit->after['payment_confirmed']);
        $this->assertSame('admin_attested', $audit->after['payment_confirmation']);
        $this->assertSame($administrator->id, $audit->after['payment_confirmed_by']);
        $auditPayload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        foreach ([
            'admin-password',
            'api-password-manual-attestation',
            'jwt-manual-attestation',
            'machine-password-manual-attestation',
            'host-manual-attestation',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $auditPayload);
        }

        $this->assertTrue(app(SupplierProvisioningProcessor::class)->poll($operation->id));
        $this->assertSame(SupplierOperation::STATUS_SUCCEEDED, $operation->fresh()->status);
        $this->assertSame('host_confirmed_active', $operation->fresh()->step);
        $this->assertSame('Active', $fixture['service']->fresh()->status);
        $this->assertNotNull($fixture['service']->fresh()->activated_at);
        $this->assertSame(2, $hostReads);
        $this->assertSame([0, 0, 0], $transactionLevels);
        foreach (['/apply_credit', '/cart/clear', '/cart/add_to_shop', '/cart/settle'] as $path) {
            $this->assertNotContains($path, $paths);
        }
    }

    public function test_manual_payment_attestation_rejects_authorization_state_and_invoice_errors_without_http(): void
    {
        $administrator = $this->administrator();
        $fixture = $this->manualPaymentFixture('attestation-validation');
        $this->actingAs($administrator);

        $this->post($this->attestationPath($fixture['operation']), $this->confirmedPayload([
            'current_password' => 'wrong-admin-password',
            'upstream_host_id' => 'host-attestation-validation',
        ]))->assertSessionHasErrors('current_password')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.upstream_host_id');
        $this->post($this->attestationPath($fixture['operation']), [
            'current_password' => 'admin-password',
            'upstream_host_id' => 'host-attestation-validation',
        ])->assertSessionHasErrors('confirmation');

        $wrongState = $this->manualPaymentFixture('attestation-wrong-state');
        $wrongState['operation']->update(['step' => 'blocked_credit']);
        $this->post(
            $this->attestationPath($wrongState['operation']),
            $this->confirmedPayload(['upstream_host_id' => 'host-attestation-wrong-state']),
        )->assertSessionHasErrors('operation');

        $noInvoice = $this->manualPaymentFixture('attestation-no-invoice');
        $noInvoice['operation']->invoiceLink()->dissociate();
        $noInvoice['operation']->save();
        $this->get('/admin/supplier-operations?status=blocked_credit')
            ->assertOk()
            ->assertDontSee(
                'action="'.url($this->attestationPath($wrongState['operation'])).'"',
                false,
            )
            ->assertDontSee(
                'action="'.url($this->attestationPath($noInvoice['operation'])).'"',
                false,
            );
        $this->post(
            $this->attestationPath($noInvoice['operation']),
            $this->confirmedPayload(['upstream_host_id' => 'host-attestation-no-invoice']),
        )->assertSessionHasErrors('operation');

        foreach ([$fixture, $wrongState, $noInvoice] as $rejected) {
            $this->assertSame(
                SupplierOperation::STATUS_BLOCKED_CREDIT,
                $rejected['operation']->fresh()->status,
            );
            $this->assertSame('Pending', $rejected['service']->fresh()->status);
        }
        $this->assertDatabaseCount('supplier_service_links', 0);
        Http::assertNothingSent();
    }

    public function test_manual_payment_attestation_rejects_foreign_host_shape_before_persistence(): void
    {
        $fixture = $this->manualPaymentFixture('attestation-foreign-host');
        $transactionLevels = [];
        Http::fake(function (ClientRequest $request) use (&$transactionLevels) {
            $transactionLevels[] = DB::transactionLevel();

            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => [
                    'status' => 200,
                    'jwt' => 'jwt-attestation-foreign-host',
                ],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'different-host',
                    'domainstatus' => 'Active',
                    'password' => 'machine-password-foreign-host',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->attestationPath($fixture['operation']), $this->confirmedPayload([
                'upstream_host_id' => 'host-attestation-foreign-host',
            ]))->assertSessionHasErrors('operation');

        $this->assertSame([0, 0], $transactionLevels);
        $this->assertDatabaseCount('supplier_service_links', 0);
        $this->assertSame('Unpaid', $fixture['invoiceLink']->fresh()->upstream_status);
        $this->assertSame(
            SupplierOperation::STATUS_BLOCKED_CREDIT,
            $fixture['operation']->fresh()->status,
        );
        $this->assertSame(
            'awaiting_manual_supplier_payment',
            $fixture['operation']->fresh()->step,
        );
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertArrayNotHasKey(
            'payment_confirmed',
            $fixture['operation']->fresh()->metadata ?? [],
        );
        $audit = AuditLog::query()
            ->where('action', 'supplier.operation.manual_payment_attested')
            ->sole();
        $this->assertSame('failed', $audit->after['outcome']);
        $payload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('machine-password-foreign-host', $payload);
        $this->assertStringNotContainsString('jwt-attestation-foreign-host', $payload);
    }

    public function test_manual_review_host_reconciliation_links_evidence_but_cannot_attest_or_activate(): void
    {
        $fixture = $this->manualPaymentFixture('manual-reconciliation-only');
        Http::fake(function (ClientRequest $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => [
                    'status' => 200,
                    'jwt' => 'jwt-manual-reconciliation-only',
                ],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-manual-reconciliation-only',
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->hostPath($fixture['operation']), $this->confirmedPayload([
                'upstream_host_id' => 'host-manual-reconciliation-only',
            ]))->assertRedirect(route('admin.supplier-operations.index'));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_BLOCKED_CREDIT, $operation->status);
        $this->assertSame('awaiting_manual_supplier_payment', $operation->step);
        $this->assertSame('legacy_payment_review_required', $operation->last_error_code);
        $this->assertSame('Unpaid', $fixture['invoiceLink']->fresh()->upstream_status);
        $this->assertSame('Active', $operation->serviceLink->upstream_status);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertNull($fixture['service']->fresh()->activated_at);
        $this->assertArrayNotHasKey('payment_confirmed', $operation->metadata ?? []);
        $this->assertFalse(app(SupplierProvisioningProcessor::class)->poll($operation->id));
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        Http::assertNotSent(fn (ClientRequest $request): bool => parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === '/apply_credit');
    }

    public function test_ambiguous_operation_renders_evidence_only_guidance_without_write_retry(): void
    {
        $fixture = $this->provisionFixture('ambiguous-view');
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_AMBIGUOUS,
            'step' => 'ambiguous',
            'last_error_code' => 'supplier_outcome_unknown',
        ]);

        $response = $this->actingAs($this->administrator())
            ->get('/admin/supplier-operations?status=ambiguous');
        $response->assertOk()
            ->assertSee('严禁重放任何采购、结算或付款写操作')
            ->assertSee('不构成付款确认的证据关联')
            ->assertSee('action="'.url($this->hostPath($fixture['operation'])).'"', false)
            ->assertDontSee('action="'.url($this->creditPath($fixture['operation'])).'"', false)
            ->assertDontSee('action="'.url($this->attestationPath($fixture['operation'])).'"', false)
            ->assertDontSee('/retry', false)
            ->assertDontSee('重试采购');
    }

    public function test_host_reconciliation_validates_the_host_before_transactional_linking(): void
    {
        $fixture = $this->provisionFixture('host-reconciliation');
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_AMBIGUOUS,
            'step' => 'ambiguous',
        ]);
        $paths = [];
        Http::fake(function (ClientRequest $request) use ($fixture, &$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;
            $this->assertSame(0, DB::transactionLevel());
            if ($path === '/host/header') {
                $this->assertDatabaseCount('supplier_service_links', 0);
                $this->assertSame(
                    SupplierOperation::STATUS_AMBIGUOUS,
                    $fixture['operation']->fresh()->status,
                );
            }

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-host-reconciliation'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'domainstatus' => 'Active',
                    'domain' => 'reconciled.example.com',
                    'dedicatedip' => '203.0.113.30',
                    'password' => 'upstream-machine-password',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->hostPath($fixture['operation']), $this->confirmedPayload([
                'upstream_host_id' => 'host-reconciliation-evidence',
            ]))
            ->assertRedirect(route('admin.supplier-operations.index'))
            ->assertSessionHas('success');

        $this->assertSame(['/zjmf_api_login', '/host/header'], $paths);
        $operation = $fixture['operation']->fresh();
        $serviceLink = $operation->serviceLink;
        $this->assertSame(SupplierOperation::STATUS_AMBIGUOUS, $operation->status);
        $this->assertSame('host_observed_payment_unconfirmed', $operation->step);
        $this->assertSame('payment_unconfirmed', $operation->last_error_code);
        $this->assertNull($operation->available_at);
        $this->assertSame('host-reconciliation-evidence', $serviceLink->upstream_service_id);
        $this->assertSame($fixture['service']->id, $serviceLink->service_id);
        $this->assertSame($fixture['mapping']->id, $serviceLink->supplier_product_mapping_id);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $safeState = json_encode([
            $serviceLink->metadata,
            $operation->response_payload,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('upstream-machine-password', $safeState);
        Http::assertSent(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/host/header'
            && $request->method() === 'GET'
            && $request['host_id'] === 'host-reconciliation-evidence');
        $audit = AuditLog::query()
            ->where('action', 'supplier.operation.host_reconciliation')
            ->sole();
        $this->assertSame('processed', $audit->after['outcome']);
        $this->assertStringNotContainsString(
            'host-reconciliation-evidence',
            json_encode($audit->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_paid_host_reconciliation_enters_safe_confirmation_polling(): void
    {
        $fixture = $this->blockedCreditWithoutHostFixture('paid-host-reconciliation');
        $invoiceId = $fixture['invoiceLink']->upstream_invoice_id;
        $fixture['invoiceLink']->update(['upstream_status' => 'Paid']);
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_AMBIGUOUS,
            'step' => 'ambiguous',
            'last_error_code' => 'host_reconciliation_required',
            'metadata' => array_replace($fixture['operation']->metadata ?? [], [
                'payment_confirmed' => true,
                'payment_application_status' => 1001,
                'payment_invoice_id' => $invoiceId,
            ]),
        ]);
        Http::fake(function (ClientRequest $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-paid-host-reconciliation'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->hostPath($fixture['operation']), $this->confirmedPayload([
                'upstream_host_id' => 'host-paid-reconciliation',
            ]))
            ->assertRedirect(route('admin.supplier-operations.index'));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_AWAITING_CONFIRMATION, $operation->status);
        $this->assertSame('awaiting_confirmation', $operation->step);
        $this->assertTrue($operation->metadata['payment_confirmed']);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertTrue($operation->available_at->isCurrentSecond());
        $this->assertSame('host-paid-reconciliation', $operation->serviceLink->upstream_service_id);
    }

    public function test_blocked_credit_host_reconciliation_only_records_observation(): void
    {
        $fixture = $this->blockedCreditWithoutHostFixture('blocked-host-observation');
        Http::fake(function (ClientRequest $request) {
            return $this->jsonResponse(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-blocked-host-observation'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->hostPath($fixture['operation']), $this->confirmedPayload([
                'upstream_host_id' => 'host-blocked-observation',
            ]))
            ->assertRedirect(route('admin.supplier-operations.index'));

        $operation = $fixture['operation']->fresh();
        $this->assertSame(SupplierOperation::STATUS_BLOCKED_CREDIT, $operation->status);
        $this->assertSame('blocked_credit', $operation->step);
        $this->assertSame('upstream_credit_insufficient', $operation->last_error_code);
        $this->assertNotTrue($operation->metadata['payment_confirmed'] ?? false);
        $this->assertSame('host-blocked-observation', $operation->serviceLink->upstream_service_id);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        $this->assertNull($fixture['service']->fresh()->activated_at);
    }

    public function test_host_reconciliation_rejects_a_connection_change_after_validation(): void
    {
        $fixture = $this->provisionFixture('host-account-change');
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_FAILED,
            'step' => 'failed',
        ]);
        $paths = [];
        Http::fake(function (ClientRequest $request) use ($fixture, &$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;
            if ($path === '/host/header') {
                $fixture['account']->update([
                    'base_url' => 'https://changed-supplier.example.test',
                ]);
            }

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-host-account-change'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'domainstatus' => 'Active',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->hostPath($fixture['operation']), $this->confirmedPayload([
                'upstream_host_id' => 'host-account-change-evidence',
            ]))
            ->assertSessionHasErrors('operation');

        $this->assertSame(['/zjmf_api_login', '/host/header'], $paths);
        $this->assertDatabaseCount('supplier_service_links', 0);
        $this->assertSame(
            SupplierOperation::STATUS_FAILED,
            $fixture['operation']->fresh()->status,
        );
        $audit = AuditLog::query()
            ->where('action', 'supplier.operation.host_reconciliation')
            ->sole();
        $this->assertSame('state_rejected', $audit->after['outcome']);
    }

    public function test_poll_recovery_only_reads_the_existing_host_and_can_activate_the_service(): void
    {
        $fixture = $this->blockedCreditFixture('poll-recovery');
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_FAILED,
            'step' => 'poll_exhausted',
            'last_error_code' => 'poll_exhausted',
            'metadata' => [
                'poll_attempts' => 10,
                'payment_confirmed' => true,
                'payment_application_status' => 1001,
                'payment_invoice_id' => 'invoice-poll-recovery',
            ],
        ]);
        $fixture['invoiceLink']->update(['upstream_status' => 'Paid']);
        $paths = [];
        Http::fake(function (ClientRequest $request) use (&$paths) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;

            return $this->jsonResponse(match ($path) {
                '/zjmf_api_login' => ['status' => 200, 'jwt' => 'jwt-poll-recovery'],
                '/host/header' => ['status' => 200, 'data' => ['host_data' => [
                    'hostid' => 'host-poll-recovery',
                    'domainstatus' => 'Active',
                    'domain' => 'active-after-poll.example.com',
                ]]],
            });
        });

        $this->actingAs($this->administrator())
            ->post($this->pollPath($fixture['operation']), $this->confirmedPayload())
            ->assertRedirect(route('admin.supplier-operations.index'))
            ->assertSessionHas('success');

        $this->assertSame(['/zjmf_api_login', '/host/header'], $paths);
        $this->assertSame(SupplierOperation::STATUS_SUCCEEDED, $fixture['operation']->fresh()->status);
        $this->assertSame('host_confirmed_active', $fixture['operation']->fresh()->step);
        $this->assertSame('Active', $fixture['service']->fresh()->status);
        foreach (['/apply_credit', '/cart/clear', '/cart/add_to_shop', '/cart/settle'] as $path) {
            Http::assertNotSent(fn (ClientRequest $request): bool => parse_url(
                $request->url(),
                PHP_URL_PATH,
            ) === $path);
        }
    }

    public function test_poll_recovery_rejects_unconfirmed_payment_without_host_http(): void
    {
        $fixture = $this->blockedCreditFixture('poll-unconfirmed');
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_FAILED,
            'step' => 'poll_exhausted',
            'last_error_code' => 'poll_exhausted',
        ]);

        $this->actingAs($this->administrator())
            ->post($this->pollPath($fixture['operation']), $this->confirmedPayload())
            ->assertSessionHasErrors('operation');

        $this->assertSame(SupplierOperation::STATUS_FAILED, $fixture['operation']->fresh()->status);
        $this->assertSame('Pending', $fixture['service']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_host_evidence_cannot_contain_credentials_and_is_never_flashed_or_audited(): void
    {
        $fixture = $this->provisionFixture('host-secret');
        $fixture['operation']->update(['status' => SupplierOperation::STATUS_AMBIGUOUS]);
        $upstreamPassword = $fixture['account']->credentials['password'];

        $response = $this->actingAs($this->administrator())
            ->post($this->hostPath($fixture['operation']), $this->confirmedPayload([
                '_form' => 'supplier-operation-'.$fixture['operation']->id,
                'upstream_host_id' => $upstreamPassword,
            ]));

        $response->assertSessionHasErrors('upstream_host_id')
            ->assertSessionMissing('_old_input.upstream_host_id')
            ->assertSessionMissing('_old_input.current_password');
        $this->assertDatabaseCount('supplier_service_links', 0);
        Http::assertNothingSent();
        $audit = AuditLog::query()
            ->where('action', 'supplier.operation.host_reconciliation')
            ->sole();
        $this->assertSame('validation_rejected', $audit->after['outcome']);
        $this->assertStringNotContainsString(
            $upstreamPassword,
            json_encode($audit->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_there_is_no_general_supplier_purchase_retry_route(): void
    {
        $operation = $this->genericOperation($this->account('no-retry'), 'no-retry');
        $this->actingAs($this->administrator())
            ->post('/admin/supplier-operations/'.$operation->id.'/retry', $this->confirmedPayload())
            ->assertNotFound();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with(
                $route->uri(),
                'admin/supplier-operations',
            ));
        $this->assertSame([
            'admin.supplier-operations.attest-payment',
            'admin.supplier-operations.index',
            'admin.supplier-operations.reconcile-host',
            'admin.supplier-operations.recover-poll',
            'admin.supplier-operations.resume-credit',
        ], $routes->pluck('action.as')->sort()->values()->all());
        $this->assertFalse($routes->contains(
            fn ($route): bool => str_contains($route->uri(), 'retry')
                || str_contains((string) $route->getName(), 'retry'),
        ));
        Http::assertNothingSent();
    }

    private function blockedCreditFixture(string $suffix, mixed $legacyCreditOption = true): array
    {
        $fixture = $this->provisionFixture($suffix, [
            'options' => [
                'allow_legacy_unbounded_credit_payment' => $legacyCreditOption,
            ],
        ]);
        $serviceLink = SupplierServiceLink::createFor(
            $fixture['account'],
            $fixture['service'],
            $fixture['mapping'],
            [
                'upstream_service_id' => 'host-'.$suffix,
                'upstream_status' => 'Pending',
            ],
        );
        $invoiceLink = SupplierInvoiceLink::createFor(
            $fixture['account'],
            $fixture['invoice'],
            $serviceLink,
            [
                'upstream_invoice_id' => 'invoice-'.$suffix,
                'upstream_status' => 'Unpaid',
            ],
        );
        $fixture['operation']->serviceLink()->associate($serviceLink);
        $fixture['operation']->invoiceLink()->associate($invoiceLink);
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_BLOCKED_CREDIT,
            'step' => 'blocked_credit',
            'last_error_code' => 'upstream_credit_insufficient',
            'last_error' => 'The supplier credit balance is insufficient.',
            'finished_at' => now(),
        ]);
        $fixture['serviceLink'] = $serviceLink;
        $fixture['invoiceLink'] = $invoiceLink;

        return $fixture;
    }

    private function blockedCreditWithoutHostFixture(string $suffix): array
    {
        $fixture = $this->provisionFixture($suffix, [
            'options' => ['allow_legacy_unbounded_credit_payment' => true],
        ]);
        $invoiceLink = SupplierInvoiceLink::createFor(
            $fixture['account'],
            $fixture['invoice'],
            null,
            [
                'upstream_invoice_id' => 'invoice-'.$suffix,
                'upstream_status' => 'Unpaid',
            ],
        );
        $fixture['operation']->invoiceLink()->associate($invoiceLink);
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_BLOCKED_CREDIT,
            'step' => 'blocked_credit',
            'last_error_code' => 'upstream_credit_insufficient',
            'last_error' => 'The supplier credit balance is insufficient.',
            'finished_at' => now(),
        ]);
        $fixture['invoiceLink'] = $invoiceLink;

        return $fixture;
    }

    private function manualPaymentFixture(string $suffix): array
    {
        $fixture = $this->provisionFixture($suffix);
        $invoiceLink = SupplierInvoiceLink::createFor(
            $fixture['account'],
            $fixture['invoice'],
            null,
            [
                'upstream_invoice_id' => 'invoice-'.$suffix,
                'upstream_status' => 'Unpaid',
            ],
        );
        $fixture['operation']->invoiceLink()->associate($invoiceLink);
        $fixture['operation']->update([
            'status' => SupplierOperation::STATUS_BLOCKED_CREDIT,
            'step' => 'awaiting_manual_supplier_payment',
            'last_error_code' => 'legacy_payment_review_required',
            'last_error' => 'The supplier invoice requires manual payment review.',
            'finished_at' => now(),
        ]);
        $fixture['invoiceLink'] = $invoiceLink;

        return $fixture;
    }

    private function provisionFixture(string $suffix, array $accountAttributes = []): array
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
            'number' => 'OPS-'.strtoupper(substr(hash('sha256', $suffix), 0, 20)),
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
        $account = $this->account($suffix, $accountAttributes);
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
        $operation = DB::transaction(function () use ($invoice, $orderItem, $service, $mapping): SupplierOperation {
            $outbox = app(SupplierProvisioningOutbox::class);
            $mapping->load(['account', 'catalogProduct']);
            $route = $outbox->freezeRoute($orderItem, $mapping, 'CNY');

            return $outbox->queueProvision($invoice, $orderItem, $service, $route);
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
            'operation',
        );
    }

    private function account(string $suffix, array $attributes = []): SupplierAccount
    {
        return SupplierAccount::create(array_replace([
            'code' => 'operation-'.$suffix,
            'name' => 'Operation Supplier '.$suffix,
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier-'.$suffix.'.test',
            'credentials' => [
                'username' => 'api-user-'.$suffix,
                'password' => 'api-password-'.$suffix,
            ],
            'options' => [],
            'is_active' => true,
        ], $attributes));
    }

    private function genericOperation(
        SupplierAccount $account,
        string $suffix,
        string $status = SupplierOperation::STATUS_FAILED,
        string $action = SupplierOperation::ACTION_PROVISION,
    ): SupplierOperation {
        return SupplierOperation::createFor($account, [
            'action' => $action,
            'status' => $status,
            'step' => 'generic-review',
            'idempotency_key' => 'operation:'.$suffix,
            'request_payload' => ['marker' => $suffix],
            'attempts' => 0,
        ]);
    }

    private function administrator(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
            'password' => 'admin-password',
        ]);
    }

    private function confirmedPayload(array $attributes = []): array
    {
        return array_replace([
            'current_password' => 'admin-password',
            'confirmation' => '1',
        ], $attributes);
    }

    private function recoveryPaths(SupplierOperation $operation): array
    {
        return [
            $this->creditPath($operation),
            $this->attestationPath($operation),
            $this->pollPath($operation),
            $this->hostPath($operation),
        ];
    }

    private function creditPath(SupplierOperation $operation): string
    {
        return '/admin/supplier-operations/'.$operation->id.'/resume-credit';
    }

    private function pollPath(SupplierOperation $operation): string
    {
        return '/admin/supplier-operations/'.$operation->id.'/recover-poll';
    }

    private function hostPath(SupplierOperation $operation): string
    {
        return '/admin/supplier-operations/'.$operation->id.'/reconcile-host';
    }

    private function attestationPath(SupplierOperation $operation): string
    {
        return '/admin/supplier-operations/'.$operation->id.'/manual-attestation';
    }

    private function jsonResponse(array $payload, int $status = 200)
    {
        return Http::response($payload, $status, ['Content-Type' => 'application/json']);
    }
}
