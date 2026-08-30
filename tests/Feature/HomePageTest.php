<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_homepage_only_lists_products_in_active_catalog_groups(): void
    {
        $activeParent = ProductGroup::create(['name' => 'Cloud', 'is_active' => true]);
        $activeGroup = ProductGroup::create([
            'parent_id' => $activeParent->id,
            'name' => 'Compute',
            'is_active' => true,
        ]);
        $inactiveParent = ProductGroup::create(['name' => 'Retired', 'is_active' => false]);
        $hiddenGroup = ProductGroup::create([
            'parent_id' => $inactiveParent->id,
            'name' => 'Legacy',
            'is_active' => true,
        ]);

        $visible = Product::create([
            'product_group_id' => $activeGroup->id,
            'name' => 'Visible edge compute',
            'price' => '29.90',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        $visible->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '19.90',
            'setup_fee' => '0.00',
            'is_active' => true,
        ]);
        $visible->prices()->create([
            'billing_cycle' => 'daily',
            'price' => '1.00',
            'setup_fee' => '0.00',
            'is_active' => false,
        ]);
        Product::create([
            'product_group_id' => $activeGroup->id,
            'name' => 'Inactive compute',
            'price' => '10.00',
            'billing_cycle' => 'monthly',
            'is_active' => false,
        ]);
        Product::create([
            'product_group_id' => $hiddenGroup->id,
            'name' => 'Hidden legacy compute',
            'price' => '20.00',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Visible edge compute')
            ->assertSee('19.90')
            ->assertSee('/ 年')
            ->assertDontSee('29.90')
            ->assertDontSee('Inactive compute')
            ->assertDontSee('Hidden legacy compute');
    }

    public function test_homepage_links_authenticated_users_to_their_portal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee(route('portal.dashboard'), false)
            ->assertSee('客户中心');
    }

    public function test_homepage_rejects_a_suspended_authenticated_session(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);
        $user->update(['status' => 'Suspended']);

        $this->get('/')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
