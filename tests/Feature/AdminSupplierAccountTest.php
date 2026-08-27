<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SupplierAccount;
use App\Models\SupplierOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminSupplierAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_supplier_routes_require_an_active_administrator(): void
    {
        $supplier = $this->supplier();

        $this->get('/admin/suppliers')->assertRedirect('/login');
        $this->post('/admin/suppliers')->assertRedirect('/login');
        $this->put('/admin/suppliers/'.$supplier->id)->assertRedirect('/login');
        $this->post('/admin/suppliers/test-active')->assertRedirect('/login');
        $this->post('/admin/suppliers/'.$supplier->id.'/catalog-sync')->assertRedirect('/login');
        $this->get('/admin/suppliers/'.$supplier->id.'/catalog')->assertRedirect('/login');
        $this->post('/admin/suppliers/'.$supplier->id.'/catalog-import')->assertRedirect('/login');
        $this->put('/admin/suppliers/'.$supplier->id.'/mappings')->assertRedirect('/login');

        $client = User::factory()->create(['role' => 'client', 'status' => 'Active']);
        $this->actingAs($client)->get('/admin/suppliers')->assertForbidden();
        $this->post('/admin/suppliers')->assertForbidden();
        $this->put('/admin/suppliers/'.$supplier->id)->assertForbidden();
        $this->post('/admin/suppliers/test-active')->assertForbidden();
        $this->post('/admin/suppliers/'.$supplier->id.'/catalog-sync')->assertForbidden();
        $this->get('/admin/suppliers/'.$supplier->id.'/catalog')->assertForbidden();
        $this->post('/admin/suppliers/'.$supplier->id.'/catalog-import')->assertForbidden();
        $this->put('/admin/suppliers/'.$supplier->id.'/mappings')->assertForbidden();

        $inactiveAdministrator = User::factory()->create([
            'role' => 'admin',
            'status' => 'Suspended',
        ]);
        $this->actingAs($inactiveAdministrator)
            ->get('/admin/suppliers')
            ->assertRedirect('/login');

        $administrator = $this->administrator();
        $this->actingAs($administrator)
            ->get('/admin/suppliers')
            ->assertOk()
            ->assertSee('上游供应商')
            ->assertSee('data-auto-submit', false)
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('同步上游目录')
            ->assertSee('name="_token"', false);
    }

    public function test_supplier_writes_are_protected_by_csrf_middleware(): void
    {
        $this->actingAs($this->administrator());
        $this->app->instance('env', 'production');

        $this->post('/admin/suppliers', $this->accountPayload())
            ->assertStatus(419);

        $this->assertDatabaseCount('supplier_accounts', 0);
    }

    public function test_every_sensitive_supplier_route_uses_the_shared_throttle(): void
    {
        foreach ([
            'admin.suppliers.store',
            'admin.suppliers.update',
            'admin.suppliers.test-active',
            'admin.suppliers.catalog-sync',
            'admin.suppliers.catalog-import',
            'admin.suppliers.mappings',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertContains('throttle:supplier-sensitive', $route->gatherMiddleware());
        }
    }

    public function test_shared_supplier_throttle_aggregates_supplier_writes(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $this->actingAs($administrator)->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        foreach (range(1, 10) as $attempt) {
            $this->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
                'code' => $supplier->code,
                'base_url' => $supplier->base_url,
                'username' => '',
                'password' => '',
            ]))->assertRedirect();
        }

        $response = $this->post('/admin/suppliers/test-active');
        $response->assertTooManyRequests();
        $this->assertSame(
            'Too many supplier administration attempts.',
            $response->getContent(),
        );
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        Http::assertNothingSent();
    }

    public function test_sync_operational_limit_uses_the_same_generic_throttle_response(): void
    {
        $supplier = $this->supplier();
        Http::fake(['*' => Http::response(['status' => 500, 'msg' => 'unavailable'])]);
        $this->actingAs($this->administrator())
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21']);

        foreach (range(1, 2) as $attempt) {
            $this->post('/admin/suppliers/'.$supplier->id.'/catalog-sync')->assertRedirect();
        }

        $response = $this->post('/admin/suppliers/'.$supplier->id.'/catalog-sync');
        $response->assertTooManyRequests();
        $this->assertSame('Too many supplier administration attempts.', $response->getContent());
        Http::assertSentCount(2);
    }

    public function test_only_idcsmart_finance_with_tls_verification_can_be_managed(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->post('/admin/suppliers', $this->accountPayload([
                'driver' => 'unsupported_driver',
            ]))
            ->assertSessionHasErrors('driver');
        $this->post('/admin/suppliers', $this->accountPayload([
            'verify_tls' => '0',
        ]))->assertSessionHasErrors('verify_tls');
        $this->assertDatabaseCount('supplier_accounts', 0);

        $unsupported = $this->supplier(['driver' => 'unsupported_driver']);
        $this->get('/admin/suppliers')->assertDontSee($unsupported->name);
        $this->put('/admin/suppliers/'.$unsupported->id, $this->accountPayload())
            ->assertNotFound();
        $this->post('/admin/suppliers/'.$unsupported->id.'/catalog-sync')
            ->assertNotFound();
        $this->put('/admin/suppliers/'.$unsupported->id.'/mappings', [
            'mappings' => [],
        ])->assertNotFound();
    }

    public function test_create_encrypts_credentials_and_never_renders_or_audits_them(): void
    {
        $administrator = $this->administrator();
        $username = 'private-supplier-identity@example.test';
        $password = 'private-upstream-password';

        $this->actingAs($administrator)
            ->withHeader('User-Agent', "Supplier admin {$username} {$password} admin-password")
            ->post('/admin/suppliers', $this->accountPayload([
                'username' => $username,
                'password' => $password,
            ]))
            ->assertRedirect(route('admin.suppliers.index'))
            ->assertSessionHas('success');

        $supplier = SupplierAccount::query()->sole();
        $rawCredentials = (string) DB::table('supplier_accounts')
            ->where('id', $supplier->id)
            ->value('credentials');

        $this->assertSame(SupplierAccount::DRIVER_IDCSMART_FINANCE, $supplier->driver);
        $this->assertSame($username, $supplier->credentials['username']);
        $this->assertSame($password, $supplier->credentials['password']);
        $this->assertStringNotContainsString($username, $rawCredentials);
        $this->assertStringNotContainsString($password, $rawCredentials);
        $this->assertTrue((bool) $supplier->options['verify_tls']);
        $this->assertFalse($supplier->options['allow_legacy_unbounded_credit_payment']);

        $audit = AuditLog::query()->where('action', 'supplier.created')->sole();
        $auditPayload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($username, $auditPayload);
        $this->assertStringNotContainsString($password, $auditPayload);
        $this->assertSame('[REDACTED]', $audit->user_agent);

        $this->get('/admin/suppliers')
            ->assertOk()
            ->assertDontSee($username)
            ->assertDontSee($password)
            ->assertSee('已加密保存');
    }

    public function test_legacy_unbounded_credit_option_accepts_only_boolean_true(): void
    {
        foreach ([
            null,
            [],
            ['allow_legacy_unbounded_credit_payment' => false],
            ['allow_legacy_unbounded_credit_payment' => 1],
            ['allow_legacy_unbounded_credit_payment' => '1'],
        ] as $options) {
            $this->assertFalse($this->supplier(['options' => $options])
                ->allowsLegacyUnboundedCreditPayment());
        }

        $this->assertTrue($this->supplier(['options' => [
            'allow_legacy_unbounded_credit_payment' => true,
        ]])->allowsLegacyUnboundedCreditPayment());
    }

    public function test_legacy_unbounded_credit_option_updates_audit_safe_booleans(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $basePayload = $this->accountPayload([
            'code' => $supplier->code,
            'base_url' => $supplier->base_url,
            'username' => '',
            'password' => '',
        ]);
        $this->actingAs($administrator);

        $this->put('/admin/suppliers/'.$supplier->id, array_replace($basePayload, [
            'allow_legacy_unbounded_credit_payment' => '1',
        ]))->assertRedirect(route('admin.suppliers.index'))->assertSessionHas('success');
        $this->assertTrue($supplier->fresh()->allowsLegacyUnboundedCreditPayment());

        $this->put('/admin/suppliers/'.$supplier->id, array_replace($basePayload, [
            'allow_legacy_unbounded_credit_payment' => '0',
        ]))->assertRedirect(route('admin.suppliers.index'))->assertSessionHas('success');
        $this->assertFalse($supplier->fresh()->allowsLegacyUnboundedCreditPayment());

        $audits = AuditLog::query()
            ->where('action', 'supplier.updated')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $audits);
        $this->assertFalse($audits[0]->before['allow_legacy_unbounded_credit_payment']);
        $this->assertTrue($audits[0]->after['allow_legacy_unbounded_credit_payment']);
        $this->assertTrue($audits[0]->after['legacy_unbounded_credit_payment_changed']);
        $this->assertTrue($audits[1]->before['allow_legacy_unbounded_credit_payment']);
        $this->assertFalse($audits[1]->after['allow_legacy_unbounded_credit_payment']);
        $this->assertTrue($audits[1]->after['legacy_unbounded_credit_payment_changed']);
        $serialized = json_encode($audits->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"options"', $serialized);
        foreach ([
            'supplier-user',
            'supplier-password',
            'admin-password',
            'wrong-admin-password',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        $this->get('/admin/suppliers')
            ->assertOk()
            ->assertSee('/apply_credit')
            ->assertSee('冻结报价无法原子限制实际扣款')
            ->assertDontSee('supplier-user')
            ->assertDontSee('supplier-password');
    }

    public function test_first_save_requires_both_credentials_without_flashing_secrets(): void
    {
        $administrator = $this->administrator();
        $username = 'unflashed-supplier-identity';

        $response = $this->actingAs($administrator)->post('/admin/suppliers', $this->accountPayload([
            'username' => $username,
            'password' => '',
        ]));

        $response->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.username')
            ->assertSessionMissing('_old_input.password');
        $this->assertDatabaseCount('supplier_accounts', 0);

        $secret = 'unflashed-upstream-password';
        $response = $this->post('/admin/suppliers', $this->accountPayload([
            'username' => $username,
            'password' => $secret,
        ]));

        $response->assertRedirect(route('admin.suppliers.index'))
            ->assertSessionMissing('_old_input.username')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.current_password');
        $this->assertDatabaseCount('supplier_accounts', 1);
    }

    public function test_blank_credential_fields_preserve_the_existing_encrypted_values(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier([
            'credentials' => [
                'username' => 'saved-supplier-user',
                'password' => 'saved-supplier-password',
            ],
        ]);
        $ciphertext = DB::table('supplier_accounts')->where('id', $supplier->id)->value('credentials');

        $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
                'name' => 'Renamed supplier',
                'code' => $supplier->code,
                'base_url' => $supplier->base_url,
                'username' => '',
                'password' => '',
                'current_password' => '',
            ]))
            ->assertRedirect(route('admin.suppliers.index'))
            ->assertSessionHas('success');

        $updated = $supplier->fresh();
        $this->assertSame('Renamed supplier', $updated->name);
        $this->assertSame('saved-supplier-user', $updated->credentials['username']);
        $this->assertSame('saved-supplier-password', $updated->credentials['password']);
        $this->assertSame(
            $ciphertext,
            DB::table('supplier_accounts')->where('id', $supplier->id)->value('credentials'),
        );
    }

    public function test_base_url_and_credential_changes_do_not_require_current_password(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $newUsername = 'new-private-supplier-user';
        $newPassword = 'new-private-supplier-password';
        $newUrl = 'https://new-supplier.example.com';

        $response = $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
                'code' => $supplier->code,
                'base_url' => $newUrl,
                'username' => $newUsername,
                'password' => $newPassword,
            ]));

        $response->assertRedirect(route('admin.suppliers.index'))
            ->assertSessionMissing('_old_input.username')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.current_password');

        $updated = $supplier->fresh();
        $this->assertSame($newUrl, $updated->base_url);
        $this->assertSame($newUsername, $updated->credentials['username']);
        $this->assertSame($newPassword, $updated->credentials['password']);

        $audit = AuditLog::query()->where('action', 'supplier.updated')->sole();
        $auditPayload = json_encode([$audit->before, $audit->after], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($newUsername, $auditPayload);
        $this->assertStringNotContainsString($newPassword, $auditPayload);
    }

    public function test_account_reactivation_does_not_require_current_password(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier(['is_active' => false]);
        $payload = $this->accountPayload([
            'code' => $supplier->code,
            'base_url' => $supplier->base_url,
            'username' => '',
            'password' => '',
            'is_active' => '1',
        ]);

        $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id, $payload)
            ->assertRedirect(route('admin.suppliers.index'))->assertSessionHas('success');
        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_supplier_actions_do_not_render_or_validate_current_password(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();

        $mappingResponse = $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id.'/mappings', [
                'mapping_page' => 1,
                'mapping_page_token' => 'must-not-be-flashed',
                'mappings' => [[
                    'product_id' => 1,
                    'local_billing_cycle' => 'monthly',
                    'target' => '',
                ]],
            ]);
        $mappingResponse->assertSessionHasErrors('mappings')
            ->assertSessionMissing('_old_input.mapping_page_token');

        $this->actingAs($administrator)->get('/admin/suppliers')
            ->assertDontSee('name="current_password"', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_supplier_secrets_are_rejected_from_plaintext_fields_without_being_flashed(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();

        $response = $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
                'name' => 'Leaked supplier-password',
                'code' => $supplier->code,
                'base_url' => $supplier->base_url,
                'username' => '',
                'password' => '',
                'current_password' => '',
            ]));
        $response->assertSessionHasErrors('supplier')
            ->assertSessionMissing('_old_input.name')
            ->assertSessionMissing('_old_input.code')
            ->assertSessionMissing('_old_input.base_url');
        $this->assertSame('Primary supplier', $supplier->fresh()->name);

        $incomingSecret = 'incoming-upstream-secret';
        $response = $this->post('/admin/suppliers', $this->accountPayload([
            'name' => 'Safe display name',
            'code' => $incomingSecret,
            'password' => $incomingSecret,
        ]));
        $response->assertSessionHasErrors('supplier')
            ->assertSessionMissing('_old_input.name')
            ->assertSessionMissing('_old_input.code')
            ->assertSessionMissing('_old_input.base_url')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.current_password');

        $this->assertDatabaseCount('supplier_accounts', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_nonterminal_operations_block_connection_changes_but_allow_names(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $operation = SupplierOperation::createFor($supplier, [
            'action' => SupplierOperation::ACTION_PROVISION,
            'status' => SupplierOperation::STATUS_QUEUED,
            'idempotency_key' => 'account-config-guard',
        ]);
        $ciphertext = DB::table('supplier_accounts')->where('id', $supplier->id)->value('credentials');

        $this->actingAs($administrator)
            ->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
                'name' => 'Safe display name',
                'code' => $supplier->code,
                'base_url' => $supplier->base_url,
                'username' => '',
                'password' => '',
                'current_password' => '',
            ]))
            ->assertRedirect(route('admin.suppliers.index'))
            ->assertSessionHas('success');
        $this->assertSame('Safe display name', $supplier->fresh()->name);

        foreach ([
            ['base_url' => 'https://new-supplier.example.com'],
            ['code' => 'replacement-code'],
            ['is_active' => '0'],
            ['allow_legacy_unbounded_credit_payment' => '1'],
        ] as $changes) {
            $this->put('/admin/suppliers/'.$supplier->id, $this->accountPayload($changes + [
                'name' => 'Must not be saved',
                'code' => $supplier->code,
                'base_url' => $supplier->base_url,
                'username' => '',
                'password' => '',
                'current_password' => 'admin-password',
            ]))->assertSessionHasErrors('supplier');

            $unchanged = $supplier->fresh();
            $this->assertSame('Safe display name', $unchanged->name);
            $this->assertSame('https://supplier.example.com', $unchanged->base_url);
            $this->assertSame('supplier-user', $unchanged->credentials['username']);
            $this->assertSame('supplier-password', $unchanged->credentials['password']);
            $this->assertTrue($unchanged->is_active);
            $this->assertFalse($unchanged->allowsLegacyUnboundedCreditPayment());
            $this->assertSame(
                $ciphertext,
                DB::table('supplier_accounts')->where('id', $supplier->id)->value('credentials'),
            );
        }

        $operation->update([
            'status' => SupplierOperation::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        $this->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
            'name' => 'Terminal update',
            'code' => $supplier->code,
            'base_url' => 'https://terminal-supplier.example.com',
            'username' => 'terminal-user',
            'password' => 'terminal-password',
            'is_active' => '0',
        ]))->assertRedirect(route('admin.suppliers.index'))->assertSessionHas('success');

        $updated = $supplier->fresh();
        $this->assertSame('Terminal update', $updated->name);
        $this->assertSame('https://terminal-supplier.example.com', $updated->base_url);
        $this->assertSame('terminal-user', $updated->credentials['username']);
        $this->assertSame('terminal-password', $updated->credentials['password']);
        $this->assertFalse($updated->is_active);
    }

    public function test_nonterminal_operations_also_block_disabling_legacy_credit_compatibility(): void
    {
        $supplier = $this->supplier(['options' => [
            'verify_tls' => true,
            'allow_legacy_unbounded_credit_payment' => true,
        ]]);
        $operation = SupplierOperation::createFor($supplier, [
            'action' => SupplierOperation::ACTION_PROVISION,
            'status' => SupplierOperation::STATUS_QUEUED,
            'idempotency_key' => 'legacy-credit-disable-guard',
        ]);
        $payload = $this->accountPayload([
            'code' => $supplier->code,
            'base_url' => $supplier->base_url,
            'username' => '',
            'password' => '',
            'current_password' => 'admin-password',
            'allow_legacy_unbounded_credit_payment' => '0',
        ]);

        $this->actingAs($this->administrator())
            ->put('/admin/suppliers/'.$supplier->id, $payload)
            ->assertSessionHasErrors('supplier');

        $this->assertTrue($supplier->fresh()->allowsLegacyUnboundedCreditPayment());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'supplier.updated']);

        $operation->update([
            'status' => SupplierOperation::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        $this->put('/admin/suppliers/'.$supplier->id, $payload)
            ->assertRedirect(route('admin.suppliers.index'))
            ->assertSessionHas('success');

        $this->assertFalse($supplier->fresh()->allowsLegacyUnboundedCreditPayment());
    }

    public function test_active_account_credentials_can_rotate_with_nonterminal_operations_and_clear_jwt_cache(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier();
        $operation = SupplierOperation::createFor($supplier, [
            'action' => SupplierOperation::ACTION_PROVISION,
            'status' => SupplierOperation::STATUS_QUEUED,
            'idempotency_key' => 'credential-rotation-guard',
        ]);
        $oldCredentials = $supplier->credentials;
        $oldCacheKey = $this->jwtCacheKey($supplier, $oldCredentials);
        Cache::put($oldCacheKey, Crypt::encryptString('old-private-jwt'), 7200);
        $newCredentials = [
            'username' => 'rotated-private-user',
            'password' => 'rotated-private-password',
        ];
        $newCacheKey = $this->jwtCacheKey($supplier, $newCredentials);
        Cache::put($newCacheKey, Crypt::encryptString('new-private-jwt'), 7200);

        $this->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
            'code' => $supplier->code,
            'base_url' => $supplier->base_url,
            'username' => $newCredentials['username'],
            'password' => $newCredentials['password'],
            'current_password' => 'admin-password',
            'allow_legacy_unbounded_credit_payment' => '1',
        ]))->assertSessionHasErrors('supplier');
        $this->assertSame($oldCredentials, $supplier->fresh()->credentials);
        $this->assertFalse($supplier->fresh()->allowsLegacyUnboundedCreditPayment());
        $this->assertTrue(Cache::has($oldCacheKey));

        $response = $this->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
            'code' => $supplier->code,
            'base_url' => $supplier->base_url,
            'username' => $newCredentials['username'],
            'password' => $newCredentials['password'],
            'current_password' => 'admin-password',
            'allow_legacy_unbounded_credit_payment' => '0',
        ]));
        $response->assertRedirect(route('admin.suppliers.index'))->assertSessionHas('success');

        $updated = $supplier->fresh();
        $this->assertSame($newCredentials, $updated->credentials);
        $this->assertSame('https://supplier.example.com', $updated->base_url);
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $operation->fresh()->status);
        $this->assertFalse(Cache::has($oldCacheKey));
        $this->assertFalse(Cache::has($newCacheKey));

        $audit = AuditLog::query()->where('action', 'supplier.updated')->sole();
        $this->assertTrue($audit->after['credentials_changed']);
        $this->assertTrue($audit->after['credential_identifier_changed']);
        $this->assertTrue($audit->after['credential_password_changed']);
        $serialized = json_encode([
            $audit->toArray(),
            $response->getSession()->all(),
            DB::table('supplier_accounts')->where('id', $supplier->id)->value('credentials'),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            ...array_values($oldCredentials),
            ...array_values($newCredentials),
            'old-private-jwt',
            'new-private-jwt',
            'admin-password',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        $this->put('/admin/suppliers/'.$supplier->id, $this->accountPayload([
            'code' => $supplier->code,
            'base_url' => 'https://blocked-identity-change.example.com',
            'username' => '',
            'password' => '',
            'current_password' => 'admin-password',
            'allow_legacy_unbounded_credit_payment' => '0',
        ]))->assertSessionHasErrors('supplier');
        $this->assertSame('https://supplier.example.com', $supplier->fresh()->base_url);
    }

    public function test_connection_test_uses_saved_credentials_and_updates_safe_state(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier([
            'base_url' => 'https://8.8.8.8',
            'credentials' => [
                'username' => 'saved-http-user',
                'password' => 'saved-http-password',
            ],
            'last_error' => 'Previous failure',
        ]);
        Http::fake([
            '8.8.8.8/zjmf_api_login' => Http::response(
                ['status' => 200, 'jwt' => 'private-supplier-jwt'],
                200,
                ['Content-Type' => 'application/json'],
            ),
            '8.8.8.8/api/product/list*' => Http::response(
                ['status' => 200, 'msg' => 'ok', 'data' => ['list' => []]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->actingAs($administrator)
            ->post('/admin/suppliers/test-active')
            ->assertRedirect()
            ->assertSessionHas('success');

        $tested = $supplier->fresh();
        $this->assertNotNull($tested->last_tested_at);
        $this->assertNotNull($tested->last_connected_at);
        $this->assertNull($tested->last_error);
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/zjmf_api_login')
            && $request['username'] === 'saved-http-user'
            && $request['password'] === 'saved-http-password');

        $audit = AuditLog::query()->where('action', 'supplier.connection_tested')->sole();
        $auditPayload = json_encode([$audit->before, $audit->after], JSON_THROW_ON_ERROR);
        $this->assertSame(['success' => true], $audit->after);
        $this->assertStringNotContainsString('saved-http-user', $auditPayload);
        $this->assertStringNotContainsString('saved-http-password', $auditPayload);
        $this->assertStringNotContainsString('private-supplier-jwt', $auditPayload);
    }

    public function test_failed_connection_test_preserves_last_connection_and_sanitizes_every_surface(): void
    {
        $administrator = $this->administrator();
        $connectedAt = now()->subDay()->startOfSecond();
        $username = 'failure-private-user';
        $password = 'failure-private-password';
        $supplier = $this->supplier([
            'base_url' => 'https://8.8.4.4',
            'credentials' => ['username' => $username, 'password' => $password],
            'last_connected_at' => $connectedAt,
        ]);
        Http::fake([
            '8.8.4.4/zjmf_api_login' => Http::response([
                'status' => 400,
                'msg' => "Rejected {$username}; password={$password}; token=raw-private-jwt",
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->actingAs($administrator)
            ->post('/admin/suppliers/test-active');

        $response->assertRedirect()->assertSessionHasErrors('supplier');
        $tested = $supplier->fresh();
        $this->assertNotNull($tested->last_tested_at);
        $this->assertSame($connectedAt->toDateTimeString(), $tested->last_connected_at->toDateTimeString());
        $this->assertStringNotContainsString($username, $tested->last_error);
        $this->assertStringNotContainsString($password, $tested->last_error);
        $this->assertStringNotContainsString('raw-private-jwt', $tested->last_error);

        $sessionErrors = json_encode($response->getSession()->get('errors')->all(), JSON_THROW_ON_ERROR);
        $audit = AuditLog::query()->where('action', 'supplier.connection_tested')->sole();
        $auditPayload = json_encode([$audit->before, $audit->after], JSON_THROW_ON_ERROR);
        foreach ([$username, $password, 'raw-private-jwt'] as $secret) {
            $this->assertStringNotContainsString($secret, $sessionErrors);
            $this->assertStringNotContainsString($secret, $auditPayload);
            $this->get('/admin/suppliers')->assertDontSee($secret);
        }
        $this->assertSame(['success' => false], $audit->after);
    }

    public function test_connection_test_discards_a_result_if_connection_configuration_changes_in_flight(): void
    {
        $administrator = $this->administrator();
        $supplier = $this->supplier([
            'base_url' => 'https://8.8.8.8',
            'last_error' => 'Keep this newer state',
        ]);
        Http::fake(function (ClientRequest $request) use ($supplier) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/zjmf_api_login' => Http::response([
                    'status' => 200,
                    'jwt' => 'race-private-jwt',
                ], 200, ['Content-Type' => 'application/json']),
                '/api/product/list' => tap(
                    Http::response([
                        'status' => 200,
                        'data' => ['list' => []],
                    ], 200, ['Content-Type' => 'application/json']),
                    fn () => DB::table('supplier_accounts')->where('id', $supplier->id)->update([
                        'code' => 'connection-race-new-code',
                        'last_error' => 'Newer configuration state',
                        'updated_at' => now(),
                    ]),
                ),
                default => Http::response(['status' => 404], 200, [
                    'Content-Type' => 'application/json',
                ]),
            };
        });

        $response = $this->actingAs($administrator)
            ->post('/admin/suppliers/test-active');

        $response->assertRedirect()->assertSessionHasErrors('supplier');
        $current = $supplier->fresh();
        $this->assertSame('connection-race-new-code', $current->code);
        $this->assertSame('Newer configuration state', $current->last_error);
        $this->assertNull($current->last_tested_at);
        $this->assertNull($current->last_connected_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'supplier.connection_tested',
        ]);
        $audit = AuditLog::query()
            ->where('action', 'supplier.connection_test_discarded')
            ->sole();
        $this->assertSame(['configuration_conflict' => true], $audit->after);
    }

    private function administrator(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'Active',
            'password' => 'admin-password',
        ]);
    }

    private function supplier(array $overrides = []): SupplierAccount
    {
        return SupplierAccount::create(array_merge([
            'code' => 'supplier-'.bin2hex(random_bytes(4)),
            'name' => 'Primary supplier',
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier.example.com',
            'credentials' => [
                'username' => 'supplier-user',
                'password' => 'supplier-password',
            ],
            'options' => ['verify_tls' => true],
            'is_active' => true,
        ], $overrides));
    }

    private function accountPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Primary supplier',
            'code' => 'primary-supplier',
            'driver' => SupplierAccount::DRIVER_IDCSMART_FINANCE,
            'base_url' => 'https://supplier.example.com',
            'username' => 'supplier-user',
            'password' => 'supplier-password',
            'current_password' => 'admin-password',
            'is_active' => '1',
            'allow_legacy_unbounded_credit_payment' => '0',
        ], $overrides);
    }

    private function jwtCacheKey(SupplierAccount $account, array $credentials): string
    {
        return 'idcsmart_finance:jwt:'.hash('sha256', implode('|', [
            (string) $account->getKey(),
            rtrim((string) $account->base_url, '/'),
            (string) $credentials['username'],
            (string) $credentials['password'],
        ]));
    }
}
