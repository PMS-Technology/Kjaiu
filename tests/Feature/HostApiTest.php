<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierProductMapping;
use App\Models\SupplierServiceLink;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private SupplierAccount $account;

    private SupplierProductMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kjaiu.jwt.secret', str_repeat('test-secret-', 4));
        config()->set('kjaiu.jwt.issuer', 'https://kjaiu.test');

        $this->user = User::factory()->create();
        $group = ProductGroup::create(['name' => 'API products']);
        $this->product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Supplier API product',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
        ]);
        $this->account = SupplierAccount::create([
            'code' => 'host-api-supplier',
            'name' => 'Host API supplier',
            'base_url' => 'https://supplier.example.test',
            'credentials' => ['username' => 'api-user', 'password' => 'api-secret'],
        ]);
        $catalog = SupplierCatalogProduct::createForAccount($this->account, [
            'upstream_product_id' => 'host-api-product',
            'name' => 'Host API upstream product',
            'billing_cycles' => ['month'],
        ]);
        $this->mapping = SupplierProductMapping::createFor(
            $this->account,
            $catalog,
            $this->product,
            [
                'local_billing_cycle' => 'monthly',
                'upstream_billing_cycle' => 'month',
            ],
        );
    }

    public function test_supplier_mapped_or_linked_service_cannot_enable_auto_renew(): void
    {
        $mappedService = $this->service($this->user);

        $this->putJson(
            '/v1/hosts/'.$mappedService->id.'/renew/auto',
            ['status' => true],
            $this->headers(),
        )->assertOk()
            ->assertExactJson([
                'status' => 400,
                'msg' => '当前版本暂不支持上游供应商服务自动续费',
                'data' => [
                    'errors' => [
                        'auto_renew' => ['当前版本暂不支持上游供应商服务自动续费'],
                    ],
                ],
            ]);
        $this->assertFalse($mappedService->fresh()->auto_renew);

        $linkedService = $this->service($this->user);
        SupplierServiceLink::createFor($this->account, $linkedService, $this->mapping, [
            'upstream_service_id' => 'active-host-api-service',
            'upstream_status' => 'Active',
        ]);
        $this->mapping->update(['is_active' => false]);

        $this->putJson(
            '/v1/hosts/'.$linkedService->id.'/renew',
            ['initiative_renew' => true],
            $this->headers(),
        )->assertOk()
            ->assertExactJson([
                'status' => 400,
                'msg' => '当前版本暂不支持上游供应商服务自动续费',
                'data' => [
                    'errors' => [
                        'auto_renew' => ['当前版本暂不支持上游供应商服务自动续费'],
                    ],
                ],
            ]);
        $this->assertFalse($linkedService->fresh()->auto_renew);
    }

    public function test_supplier_mapped_or_linked_service_cannot_advertise_or_create_renewal(): void
    {
        $mappedService = $this->service($this->user, ['auto_renew' => true]);

        $this->getJson('/v1/hosts/'.$mappedService->id, $this->headers())
            ->assertOk()
            ->assertJsonPath('data.host.initiative_renew', 0);
        $this->getJson('/v1/hosts', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.host.0.initiative_renew', 0);
        $this->getJson('/v1/hosts/'.$mappedService->id.'/renew', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'status' => 400,
                'msg' => '当前版本暂不支持上游供应商服务续费',
            ]);
        $this->postJson(
            '/v1/hosts/'.$mappedService->id.'/renew',
            ['billingcycle' => 'monthly'],
            $this->headers(),
        )->assertOk()
            ->assertJsonPath('status', 400)
            ->assertJsonPath('data.errors.service.0', '当前版本暂不支持上游供应商服务续费');

        $linkedService = $this->service($this->user, ['auto_renew' => true]);
        SupplierServiceLink::createFor($this->account, $linkedService, $this->mapping, [
            'upstream_service_id' => 'linked-renewal-host-api-service',
            'upstream_status' => 'Active',
        ]);
        $this->mapping->update(['is_active' => false]);

        $this->getJson('/v1/hosts/'.$linkedService->id, $this->headers())
            ->assertOk()
            ->assertJsonPath('data.host.initiative_renew', 0);
        $this->getJson('/v1/hosts/'.$linkedService->id.'/renew', $this->headers())
            ->assertOk()
            ->assertJsonMissingPath('data.cycle')
            ->assertJsonPath('status', 400)
            ->assertJsonPath('msg', '当前版本暂不支持上游供应商服务续费');
        $this->postJson(
            '/v1/hosts/'.$linkedService->id.'/renew',
            ['billingcycle' => 'monthly'],
            $this->headers(),
        )->assertOk()
            ->assertJsonPath('status', 400)
            ->assertJsonPath('data.errors.service.0', '当前版本暂不支持上游供应商服务续费');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_normal_local_service_renewal_and_auto_renew_remain_available(): void
    {
        $localProduct = Product::create([
            'product_group_id' => $this->product->product_group_id,
            'name' => 'Local API product',
            'billing_cycle' => 'monthly',
            'price' => '19.00',
        ]);
        $service = $this->service($this->user, ['product_id' => $localProduct->id]);

        $this->getJson('/v1/hosts/'.$service->id.'/renew', $this->headers())
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.cycle.0.billingcycle', 'monthly');
        $this->putJson(
            '/v1/hosts/'.$service->id.'/renew/auto',
            ['status' => true],
            $this->headers(),
        )->assertOk()->assertJsonPath('status', 200);
        $this->assertTrue($service->fresh()->auto_renew);

        $response = $this->postJson(
            '/v1/hosts/'.$service->id.'/renew',
            ['billingcycle' => 'monthly'],
            $this->headers(),
        )->assertOk()->assertJsonPath('status', 200);

        $invoice = Invoice::query()->sole();
        $this->assertSame($invoice->id, $response->json('data.invoice_id'));
        $this->assertSame($service->id, $invoice->items()->where('type', 'renew')->value('service_id'));
    }

    public function test_supplier_linked_service_can_disable_auto_renew(): void
    {
        $service = $this->service($this->user, ['auto_renew' => true]);
        SupplierServiceLink::createFor($this->account, $service, $this->mapping, [
            'upstream_service_id' => 'disable-host-api-service',
            'upstream_status' => 'Active',
        ]);

        $this->putJson(
            '/v1/hosts/'.$service->id.'/renew/auto',
            ['initiative_renew' => false],
            $this->headers(),
        )->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '修改成功');

        $this->assertFalse($service->fresh()->auto_renew);
    }

    public function test_auto_renew_cannot_modify_a_foreign_service(): void
    {
        $otherUser = User::factory()->create();
        $service = $this->service($otherUser, ['auto_renew' => true]);

        $this->putJson(
            '/v1/hosts/'.$service->id.'/renew/auto',
            ['status' => false],
            $this->headers(),
        )->assertOk()
            ->assertExactJson([
                'status' => 404,
                'msg' => '产品不存在',
            ]);

        $this->assertTrue($service->fresh()->auto_renew);
    }

    private function service(User $user, array $attributes = []): Service
    {
        return Service::create(array_merge([
            'user_id' => $user->id,
            'product_id' => $this->product->id,
            'name' => 'API service',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'next_due_at' => now()->addMonth(),
            'auto_renew' => false,
        ], $attributes));
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'JWT '.app(JwtService::class)->issue($this->user),
        ];
    }
}
