<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierOperation;
use App\Models\SupplierServiceLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_supplier_service_link_fences_manual_status_changes(): void
    {
        $service = $this->service();
        SupplierServiceLink::createFor($this->account('linked'), $service, null, [
            'upstream_service_id' => 'host-linked',
            'upstream_status' => 'Pending',
        ]);

        $this->actingAs($this->administrator())
            ->put('/admin/services/'.$service->id, [
                '_service_id' => $service->id,
                'status' => 'Active',
                'dedicated_ip' => '203.0.113.10',
                'internal_notes' => 'Must not be written with a rejected status.',
            ])
            ->assertSessionHasErrors('status');

        $service->refresh();
        $this->assertSame('Pending', $service->status);
        $this->assertNull($service->activated_at);
        $this->assertNull($service->dedicated_ip);
        $this->assertNull($service->internal_notes);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'service.updated']);
    }

    public function test_a_queued_supplier_operation_fences_manual_status_changes_without_a_link(): void
    {
        $service = $this->service();
        $this->operation($this->account('queued'), $service, SupplierOperation::STATUS_QUEUED);

        $this->actingAs($this->administrator())
            ->put('/admin/services/'.$service->id, [
                '_service_id' => $service->id,
                'status' => 'Active',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('Pending', $service->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'service.updated']);
    }

    public function test_safe_local_fields_can_update_for_a_supplier_managed_service(): void
    {
        $service = $this->service();
        $account = $this->account('safe-fields');
        SupplierServiceLink::createFor($account, $service, null, [
            'upstream_service_id' => 'host-safe-fields',
        ]);

        $this->actingAs($this->administrator())
            ->withHeader('User-Agent', 'supplier-password-safe-fields')
            ->put('/admin/services/'.$service->id, [
                '_service_id' => $service->id,
                'status' => 'Pending',
                'dedicated_ip' => '203.0.113.20',
                'internal_notes' => 'Rack assignment confirmed locally.',
                'request_payload' => ['password' => 'ignored-request-secret'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame('Pending', $service->status);
        $this->assertNull($service->activated_at);
        $this->assertSame('203.0.113.20', $service->dedicated_ip);
        $this->assertSame('Rack assignment confirmed locally.', $service->internal_notes);

        $audit = AuditLog::query()->where('action', 'service.updated')->sole();
        $auditJson = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ignored-request-secret', $auditJson);
        $this->assertStringNotContainsString('supplier-password-safe-fields', $auditJson);
        $this->assertStringNotContainsString('host-safe-fields', $auditJson);
        $this->assertSame('[REDACTED]', $audit->user_agent);
        $this->assertSame('Pending', $audit->before['status']);
        $this->assertSame('Pending', $audit->after['status']);
    }

    public function test_a_local_service_retains_status_editing(): void
    {
        $localService = $this->service();
        $this->operation(
            $this->account('terminal-operation'),
            $localService,
            SupplierOperation::STATUS_FAILED,
        );
        $otherService = $this->service(['name' => 'Other supplier service']);
        $this->operation(
            $this->account('other-service'),
            $otherService,
            SupplierOperation::STATUS_QUEUED,
        );

        $this->actingAs($this->administrator())
            ->put('/admin/services/'.$localService->id, [
                '_service_id' => $localService->id,
                'status' => 'Active',
                'dedicated_ip' => '203.0.113.30',
                'internal_notes' => 'Local activation.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $localService->refresh();
        $this->assertSame('Active', $localService->status);
        $this->assertNotNull($localService->activated_at);
        $this->assertSame('203.0.113.30', $localService->dedicated_ip);
    }

    public function test_supplier_managed_service_ui_is_read_only_and_renders_no_supplier_secrets(): void
    {
        $service = $this->service();
        $credentialSecret = 'credential-secret-never-render';
        $payloadSecret = 'payload-secret-never-render';
        $account = $this->account('secret-ui', [
            'credentials' => [
                'username' => 'supplier-user',
                'password' => $credentialSecret,
            ],
        ]);
        $link = SupplierServiceLink::createFor($account, $service, null, [
            'upstream_service_id' => 'host-secret-ui',
            'metadata' => ['private' => $payloadSecret],
        ]);
        SupplierOperation::createFor($account, [
            'action' => SupplierOperation::ACTION_PROVISION,
            'status' => SupplierOperation::STATUS_QUEUED,
            'idempotency_key' => 'admin-service:secret-ui',
            'request_payload' => ['password' => $payloadSecret],
            'response_payload' => ['token' => $credentialSecret],
        ], service: $service, serviceLink: $link);

        $response = $this->actingAs($this->administrator())->get('/admin/services');

        $response->assertOk()
            ->assertSee('该服务由供应商上游状态与对账流程控制')
            ->assertSee(route('admin.supplier-operations.index'))
            ->assertDontSee($credentialSecret)
            ->assertDontSee($payloadSecret)
            ->assertDontSee('host-secret-ui');
        $this->assertSame(1, preg_match(
            '/<dialog[^>]*id="service-'.$service->id.'"[^>]*>.*?<\/dialog>/s',
            $response->getContent(),
            $matches,
        ));
        $this->assertStringContainsString('readonly', $matches[0]);
        $this->assertStringNotContainsString('name="status"', $matches[0]);
    }

    private function administrator(): User
    {
        return User::factory()->administrator()->create(['status' => 'Active']);
    }

    private function service(array $attributes = []): Service
    {
        return Service::create(array_replace([
            'user_id' => User::factory()->create()->id,
            'name' => 'Admin managed service',
            'status' => 'Pending',
            'billing_cycle' => 'monthly',
        ], $attributes));
    }

    private function account(string $suffix, array $attributes = []): SupplierAccount
    {
        return SupplierAccount::create(array_replace([
            'code' => 'admin-service-'.$suffix,
            'name' => 'Admin Service Supplier '.$suffix,
            'base_url' => 'https://supplier-'.$suffix.'.test',
            'credentials' => [
                'username' => 'supplier-user-'.$suffix,
                'password' => 'supplier-password-'.$suffix,
            ],
            'is_active' => true,
        ], $attributes));
    }

    private function operation(
        SupplierAccount $account,
        Service $service,
        string $status,
    ): SupplierOperation {
        return SupplierOperation::createFor($account, [
            'action' => SupplierOperation::ACTION_PROVISION,
            'status' => $status,
            'idempotency_key' => 'admin-service:'.$account->code,
            'request_payload' => ['service_id' => $service->id],
        ], service: $service);
    }
}
