<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductGroup $group;

    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kjaiu.jwt.secret', str_repeat('test-secret-', 4));
        config()->set('kjaiu.jwt.issuer', 'https://kjaiu.test');

        $this->user = User::factory()->create(['credit' => '500.00']);
        $root = ProductGroup::create(['name' => 'Infrastructure']);
        $this->group = ProductGroup::create(['name' => 'Cloud', 'parent_id' => $root->id]);
        PaymentGateway::create(['name' => 'BankTransfer', 'title' => 'Bank transfer']);
        $this->billing = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_partial_checkout_is_idempotent_and_credit_payment_creates_real_service_links(): void
    {
        $first = $this->product('Compute', '10.00', 5, true);
        $second = $this->product('Storage', '20.00', 8);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $first->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $second->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
        ]);

        $payload = [
            'position' => [0],
            'payment' => 'Credit',
            'idempotency_key' => 'checkout-001',
        ];
        $firstCheckout = $this->postJson('/v1/cart/checkout', $payload, $this->headers())
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.total', '10.00');
        $invoiceId = $firstCheckout->json('data.invoiceid');

        $this->postJson('/v1/cart/checkout', $payload, $this->headers())
            ->assertJsonPath('data.invoiceid', $invoiceId);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(4, $first->fresh()->quantity);
        $this->assertSame(8, $second->fresh()->quantity);
        $this->assertDatabaseHas('cart_items', ['product_id' => $second->id, 'quantity' => 2]);

        $this->postJson('/v1/credit', ['invoiceid' => $invoiceId], $this->headers())
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.status', 'Paid');
        $this->postJson('/v1/credit', ['invoiceid' => $invoiceId], $this->headers())
            ->assertJsonPath('data.status', 'Paid');

        $service = Service::query()->sole();
        $this->assertSame('490.00', $this->user->fresh()->credit);
        $this->assertSame(1, Transaction::count());
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoiceId,
            'service_id' => $service->id,
            'rel_id' => $service->id,
        ]);
    }

    public function test_cancellation_restores_only_snapshotted_inventory_once(): void
    {
        $product = $this->product('Reserved compute', '15.00', 5);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
        ]);
        $invoice = $this->billing->checkout($this->user, null, 'cancel-001', 'BankTransfer');
        $this->assertSame(3, $product->fresh()->quantity);

        $product->update(['stock_control' => false]);
        $changed = false;
        $this->billing->cancelInvoice($invoice, $changed);
        $this->assertTrue($changed);
        $this->assertSame(5, $product->fresh()->quantity);

        $changed = true;
        $this->billing->cancelInvoice($invoice, $changed);
        $this->assertFalse($changed);
        $this->assertSame(5, $product->fresh()->quantity);
    }

    public function test_pending_reservations_cannot_overflow_product_stock(): void
    {
        $product = $this->product('Reserved stock limit', '1.00', 1);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);
        $invoice = $this->billing->checkout($this->user, null, 'stock-limit', 'BankTransfer');
        $administrator = User::factory()->create(['role' => 'admin', 'status' => 'Active']);

        $this->actingAs($administrator)->put(route('admin.products.update', $product), [
            'product_group_id' => $this->group->id,
            'name' => $product->name,
            'type' => $product->type,
            'billing_cycle' => $product->billing_cycle,
            'price' => $product->price,
            'setup_fee' => $product->setup_fee,
            'stock_control' => '1',
            'quantity' => (string) Product::MAX_STOCK,
            'auto_setup' => '0',
            'is_active' => '1',
        ])->assertSessionHasErrors('quantity');
        $this->assertSame(0, $product->fresh()->quantity);

        $product->update(['quantity' => Product::MAX_STOCK]);
        try {
            $this->billing->cancelInvoice($invoice);
            $this->fail('Cancelling must not overflow the unsigned stock column.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->order->items()->sole()->reserved_quantity);
    }

    public function test_month_end_renewals_keep_the_original_billing_anchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 00:00:00'));
        $product = $this->product('Monthly service', '25.00', null, true, false);
        $service = Service::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'name' => 'Anchored service',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'billing_anchor_day' => 31,
            'next_due_at' => Carbon::parse('2027-01-31 12:00:00'),
        ]);

        $januaryInvoice = $this->billing->createRenewalInvoice($this->user, $service, 'monthly');
        $this->assertSame(
            $januaryInvoice->id,
            $this->billing->createRenewalInvoice($this->user, $service, 'monthly')->id,
        );
        $product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '250.00',
            'is_active' => true,
        ]);
        try {
            $this->billing->createRenewalInvoice($this->user, $service, 'annually');
            $this->fail('A different cycle must not reuse the current renewal invoice.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('billing_cycle', $exception->errors());
        }
        $this->assertSame(
            '2027-01-31 12:00:00',
            $januaryInvoice->renewal_due_at->format('Y-m-d H:i:s'),
        );
        $this->billing->payWithCredit($this->user, $januaryInvoice);
        $this->assertSame('2027-02-28 12:00:00', $service->fresh()->next_due_at->format('Y-m-d H:i:s'));

        $februaryInvoice = $this->billing->createRenewalInvoice($this->user->fresh(), $service->fresh(), 'monthly');
        $this->billing->payWithCredit($this->user->fresh(), $februaryInvoice);
        $this->assertSame('2027-03-31 12:00:00', $service->fresh()->next_due_at->format('Y-m-d H:i:s'));
    }

    public function test_recharge_requests_are_idempotent_and_cannot_be_paid_with_credit(): void
    {
        $first = $this->billing->createRechargeInvoice(
            $this->user,
            '50.00',
            'BankTransfer',
            'funds-001',
        );
        $second = $this->billing->createRechargeInvoice(
            $this->user,
            '50.00',
            'BankTransfer',
            'funds-001',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());

        $this->postJson('/v1/credit', ['invoiceid' => $first->id], $this->headers())
            ->assertJsonPath('status', 400)
            ->assertJsonPath('msg', '充值账单不能使用余额支付');
    }

    public function test_verified_recharge_callbacks_are_idempotent(): void
    {
        $invoice = $this->billing->createRechargeInvoice(
            $this->user,
            '50.00',
            'BankTransfer',
            'callback-funds-001',
        );

        $changed = false;
        $this->billing->recordPayment($invoice, 'BankTransfer', 'provider-transaction-001', $changed);
        $this->assertTrue($changed);
        $this->assertSame('550.00', $this->user->fresh()->credit);

        $changed = true;
        $this->billing->recordPayment($invoice, 'BankTransfer', 'provider-transaction-001', $changed);
        $this->assertFalse($changed);
        $this->assertSame('550.00', $this->user->fresh()->credit);
        $this->assertSame(1, Transaction::count());
    }

    public function test_verified_payments_reject_oversized_external_transaction_numbers(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->user->id,
            'number' => 'KJ-LENGTH-1',
            'status' => 'Unpaid',
            'total' => '1.00',
        ]);

        try {
            $this->billing->recordPayment($invoice, 'BankTransfer', str_repeat('x', 192));
            $this->fail('An oversized external transaction number must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_number', $exception->errors());
        }

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_settlement_bounds_generated_service_names(): void
    {
        $productName = str_repeat('x', 255);
        $product = $this->product($productName, '1.00', null, false, false);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);

        $invoice = $this->billing->checkout($this->user, null, 'long-product-name', 'Credit');
        $this->billing->payWithCredit($this->user, $invoice);

        $service = Service::query()->sole();
        $this->assertSame(255, mb_strlen($service->name));
        $this->assertStringStartsWith(str_repeat('x', 244).' - ', $service->name);
    }

    public function test_checkout_rejects_totals_outside_the_database_decimal_range(): void
    {
        $product = $this->product('Maximum price', '999999999999.99', null, false, false);
        $product->update(['setup_fee' => '999999999999.99']);
        for ($index = 0; $index < 51; $index++) {
            CartItem::create([
                'user_id' => $this->user->id,
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 100,
            ]);
        }

        try {
            $this->billing->checkout($this->user, null, 'oversized-total', 'Credit');
            $this->fail('A total outside DECIMAL(18,2) must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total', $exception->errors());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_v1_catalog_and_invoice_status_aliases_are_available(): void
    {
        $product = $this->product('Alias product', '9.00');
        $this->getJson('/v1/productsconfig?product_id='.$product->id)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.product.id', $product->id);

        $invoice = Invoice::create([
            'user_id' => $this->user->id,
            'number' => 'KJ-ALIAS-1',
            'status' => 'Unpaid',
            'total' => '1.00',
        ]);
        $this->getJson('/v1/invoices/'.$invoice->id.'/status', $this->headers())
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.invoiceid', $invoice->id);
    }

    public function test_selecting_an_external_gateway_does_not_settle_the_invoice(): void
    {
        $product = $this->product('External payment product', '19.00', null, true, false);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);
        $invoice = $this->billing->checkout($this->user, null, 'gateway-001', 'BankTransfer');

        $this->postJson('/v1/pay', [
            'invoiceid' => $invoice->id,
            'payment' => 'BankTransfer',
        ], $this->headers())
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.requires_gateway', true);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Service::count());
    }

    private function product(
        string $name,
        string $price,
        ?int $quantity = null,
        bool $autoSetup = false,
        bool $stockControl = true,
    ): Product {
        return Product::create([
            'product_group_id' => $this->group->id,
            'name' => $name,
            'type' => 'cloud',
            'billing_cycle' => 'monthly',
            'price' => $price,
            'stock_control' => $stockControl,
            'quantity' => $stockControl ? $quantity : null,
            'auto_setup' => $autoSetup,
            'is_active' => true,
        ]);
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'JWT '.app(JwtService::class)->issue($this->user->fresh()),
        ];
    }
}
