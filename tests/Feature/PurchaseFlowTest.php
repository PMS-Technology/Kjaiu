<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Host;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\JwtService;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The purchase path — browse, configure, add to cart, check out — is the flow
 * the whole platform exists to serve, so it is asserted end to end rather than
 * only at the service layer.
 */
class PurchaseFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected Product $product;
    protected Client $client;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::query()->first()
            ?? Currency::create(['code' => 'CNY', 'prefix' => '¥', 'suffix' => '元', 'format' => '2', 'rate' => 1, 'default' => 1]);
        $currency->default = 1;
        $currency->save();

        // The storefront walks first-level groups down to products, so the
        // fixture must build the whole chain, not just the leaf group.
        $first = \App\Models\ProductFirstGroup::create([
            'name' => 'Flow category', 'hidden' => 0, 'order' => 0,
            'create_time' => time(), 'update_time' => time(),
        ]);

        $group = ProductGroup::create([
            'name' => 'Flow group', 'gid' => $first->id, 'order' => 0, 'hidden' => 0,
            'create_time' => time(), 'update_time' => time(),
        ]);

        $this->product = Product::create([
            'type' => 'cloud', 'gid' => $group->id, 'name' => 'Flow product',
            'pay_type' => json_encode([]), 'hidden' => 0, 'retired' => 0,
            'stock_control' => 0, 'auto_setup' => 'manual', 'create_time' => time(),
        ]);

        $cycles = array_fill_keys(Pricing::CYCLES, '-1.00');
        Pricing::create(array_merge($cycles, [
            'type' => 'product', 'currency' => $currency->id, 'relid' => $this->product->id,
            'monthly' => '50.00', 'annually' => '500.00', 'msetupfee' => '10.00',
        ]));

        $this->client = Client::create([
            'username' => 'flow-' . uniqid(), 'email' => uniqid() . '@example.com',
            'password' => PasswordHasher::client('secret-pass'), 'status' => Client::STATUS_ACTIVE,
            'create_time' => time(),
        ]);

        $this->token = (new JwtService())->issue($this->client);
    }

    protected function auth(): array
    {
        return ['authorization' => 'JWT ' . $this->token];
    }

    public function test_the_storefront_lists_the_product_with_its_prices(): void
    {
        $response = $this->getJson('/v1/products');

        $response->assertOk()->assertJsonPath('status', 200);
        $this->assertStringContainsString('Flow product', $response->getContent());
        $this->assertStringContainsString('50.00', $response->getContent());
    }

    public function test_an_unavailable_cycle_is_refused_when_pricing_a_configuration(): void
    {
        // quarterly was left at -1.00 (not offered) and must not be purchasable.
        $this->postJson('/v1/products/total', [
            'pid' => $this->product->id,
            'cycle' => 'quarterly',
        ])->assertJsonPath('status', 400);
    }

    public function test_pricing_a_configuration_includes_the_setup_fee(): void
    {
        $response = $this->postJson('/v1/products/total', [
            'pid' => $this->product->id,
            'cycle' => 'monthly',
            'qty' => 1,
        ]);

        $response->assertOk()->assertJsonPath('status', 200);

        // 50.00 monthly + 10.00 setup fee.
        $this->assertSame('60.00', $response->json('data.total'));
    }

    public function test_the_setup_fee_is_charged_once_regardless_of_quantity(): void
    {
        $response = $this->postJson('/v1/products/total', [
            'pid' => $this->product->id,
            'cycle' => 'monthly',
            'qty' => 3,
        ]);

        // 3 x 50.00 + one 10.00 setup fee.
        $this->assertSame('160.00', $response->json('data.total'));
    }

    public function test_adding_to_the_cart_and_checking_out_creates_an_order_and_invoice(): void
    {
        $this->postJson('/v1/cart/products', [
            'pid' => $this->product->id,
            'cycle' => 'monthly',
            'qty' => 1,
        ], $this->auth())->assertOk()->assertJsonPath('status', 200);

        $cart = $this->getJson('/v1/cart', $this->auth())->assertOk();
        $this->assertSame(1, $cart->json('data.count'));
        $this->assertSame('60.00', $cart->json('data.total.total'));

        $checkout = $this->postJson('/v1/cart/checkout', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 200);

        $invoiceId = $checkout->json('data.invoice_id');
        $this->assertNotNull($invoiceId);

        // The order produced an invoice for the configured amount.
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'uid' => $this->client->id, 'total' => 60.00]);

        // A Pending service is created so the order can be provisioned later.
        $this->assertDatabaseHas('host', [
            'uid' => $this->client->id,
            'productid' => $this->product->id,
            'domainstatus' => Host::STATUS_PENDING,
        ]);

        // The cart is emptied once the order is placed.
        $this->assertSame(0, $this->getJson('/v1/cart', $this->auth())->json('data.count'));
    }

    public function test_checking_out_an_empty_cart_is_refused(): void
    {
        $this->postJson('/v1/cart/checkout', [], $this->auth())
            ->assertJsonPath('status', 400);
    }

    public function test_a_line_item_can_be_removed_by_its_position(): void
    {
        foreach (['monthly', 'annually'] as $cycle) {
            $this->postJson('/v1/cart/products', [
                'pid' => $this->product->id, 'cycle' => $cycle, 'qty' => 1,
            ], $this->auth());
        }

        $this->assertSame(2, $this->getJson('/v1/cart', $this->auth())->json('data.count'));

        // Positions are 1-based, matching the documented API.
        $this->deleteJson('/v1/cart/products/1', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 200);

        $remaining = $this->getJson('/v1/cart', $this->auth());
        $this->assertSame(1, $remaining->json('data.count'));
        // The surviving item must be the one that was at position 2.
        $this->assertSame('annually', $remaining->json('data.list.0.cycle'));
    }

    public function test_modifying_quantity_recomputes_the_line_total(): void
    {
        $this->postJson('/v1/cart/products', [
            'pid' => $this->product->id, 'cycle' => 'monthly', 'qty' => 1,
        ], $this->auth());

        $this->putJson('/v1/cart/products/1/qty', ['qty' => 2], $this->auth())
            ->assertOk();

        $cart = $this->getJson('/v1/cart', $this->auth());
        // 2 x 50.00 + one 10.00 setup fee.
        $this->assertSame('110.00', $cart->json('data.total.total'));
    }

    public function test_a_hidden_product_cannot_be_added_to_the_cart(): void
    {
        $this->product->hidden = 1;
        $this->product->save();

        $this->postJson('/v1/cart/products', [
            'pid' => $this->product->id, 'cycle' => 'monthly',
        ], $this->auth())->assertJsonPath('status', 400);
    }

    public function test_the_cart_is_scoped_to_the_authenticated_client(): void
    {
        $this->postJson('/v1/cart/products', [
            'pid' => $this->product->id, 'cycle' => 'monthly',
        ], $this->auth());

        $other = Client::create([
            'username' => 'other-' . uniqid(), 'email' => uniqid() . '@example.com',
            'password' => PasswordHasher::client('x'), 'status' => Client::STATUS_ACTIVE,
            'create_time' => time(),
        ]);

        $otherToken = (new JwtService())->issue($other);

        // One client's cart must never leak into another's.
        $this->getJson('/v1/cart', ['authorization' => 'JWT ' . $otherToken])
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }
}
