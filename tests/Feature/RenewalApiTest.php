<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductGroup $group;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kjaiu.jwt.secret', str_repeat('test-secret-', 4));
        config()->set('kjaiu.jwt.issuer', 'https://kjaiu.test');

        $this->user = User::factory()->create();
        $this->group = ProductGroup::create(['name' => 'API renewal products']);
        $this->product = $this->product([
            'name' => 'API monthly renewal',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
        ]);
    }

    public function test_manual_renewal_api_rejects_an_inactive_product(): void
    {
        $service = $this->service();
        $this->product->update(['is_active' => false]);

        $this->postJson(
            '/v1/hosts/'.$service->id.'/renew',
            ['billingcycle' => 'monthly'],
            $this->headers(),
        )->assertOk()
            ->assertJsonPath('status', 400)
            ->assertJsonPath('data.errors.service.0', '当前产品不存在或已停用，无法续费');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_auto_renew_api_enablement_matches_portal_eligibility(): void
    {
        $valid = $this->service();
        $this->putJson(
            '/v1/hosts/'.$valid->id.'/renew/auto',
            ['status' => true],
            $this->headers(),
        )->assertOk()->assertJsonPath('status', 200);
        $this->assertTrue($valid->fresh()->auto_renew);

        $annualPrice = $this->product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '250.00',
            'is_active' => false,
        ]);
        $inactiveProduct = $this->product([
            'name' => 'Inactive API renewal',
            'is_active' => false,
        ]);
        $oneTimeProduct = $this->product([
            'name' => 'One-time API product',
            'billing_cycle' => 'onetime',
        ]);

        $invalidServices = [
            $this->service(['status' => 'Pending']),
            $this->service(['status' => 'Suspended']),
            $this->service(['next_due_at' => null]),
            $this->service(['product_id' => null]),
            $this->service(['billing_cycle' => 'quarterly']),
            $this->service(['billing_cycle' => 'annually']),
            $this->service(['product_id' => $inactiveProduct->id]),
            $this->service([
                'product_id' => $oneTimeProduct->id,
                'billing_cycle' => 'onetime',
            ]),
        ];

        foreach ($invalidServices as $service) {
            $this->putJson(
                '/v1/hosts/'.$service->id.'/renew/auto',
                ['initiative_renew' => true],
                $this->headers(),
            )->assertOk()
                ->assertJsonPath('status', 400)
                ->assertJsonStructure(['data' => ['errors' => ['auto_renew']]]);
            $this->assertFalse($service->fresh()->auto_renew);
        }

        $annualPrice->update(['is_active' => true]);
        $annualService = $invalidServices[5];
        $this->putJson(
            '/v1/hosts/'.$annualService->id.'/renew/auto',
            ['status' => true],
            $this->headers(),
        )->assertOk()->assertJsonPath('status', 200);
        $this->assertTrue($annualService->fresh()->auto_renew);
    }

    public function test_auto_renew_api_can_disable_an_owned_ineligible_service(): void
    {
        $service = $this->service([
            'status' => 'Suspended',
            'product_id' => null,
            'next_due_at' => null,
            'auto_renew' => true,
        ]);

        $this->putJson(
            '/v1/hosts/'.$service->id.'/renew/auto',
            ['status' => false],
            $this->headers(),
        )->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '修改成功');

        $this->assertFalse($service->fresh()->auto_renew);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'product_group_id' => $this->group->id,
            'name' => 'API renewal product',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
            'is_active' => true,
        ], $attributes));
    }

    private function service(array $attributes = []): Service
    {
        return Service::create(array_merge([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'name' => 'API renewable service',
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
