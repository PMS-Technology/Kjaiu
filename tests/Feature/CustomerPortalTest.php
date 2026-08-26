<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveUser;
use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierProductMapping;
use App\Models\SupplierServiceLink;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $otherClient;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->client = User::factory()->create([
            'email' => 'portal@example.test',
            'password' => 'old-password',
            'credit' => '100.00',
            'token_version' => 3,
        ]);
        $this->otherClient = User::factory()->create();
        $root = ProductGroup::create([
            'name' => 'Infrastructure',
            'is_active' => true,
        ]);
        $group = ProductGroup::create([
            'parent_id' => $root->id,
            'name' => 'Cloud',
            'headline' => 'Elastic compute',
            'is_active' => true,
        ]);
        $this->product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Portal Compute',
            'type' => 'cloud',
            'description' => 'Customer-visible compute plan',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
            'setup_fee' => '5.00',
            'stock_control' => true,
            'quantity' => 10,
            'auto_setup' => false,
            'is_active' => true,
        ]);
        $this->product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '250.00',
            'setup_fee' => '0.00',
            'is_active' => true,
        ]);
        PaymentGateway::create([
            'name' => 'BankTransfer',
            'title' => 'Bank transfer',
            'is_active' => true,
        ]);
    }

    public function test_portal_pages_render_only_owned_records_and_hide_internal_notes(): void
    {
        $ownOrder = Order::create([
            'user_id' => $this->client->id,
            'status' => 'Pending',
            'subtotal' => '30.00',
            'total' => '30.00',
        ]);
        $ownOrder->items()->create([
            'product_id' => $this->product->id,
            'product_name' => 'OWN-ORDER-SNAPSHOT',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => '25.00',
            'setup_fee' => '5.00',
            'amount' => '30.00',
        ]);
        $foreignOrder = Order::create([
            'user_id' => $this->otherClient->id,
            'status' => 'Pending',
            'subtotal' => '99.00',
            'total' => '99.00',
        ]);
        $ownInvoice = Invoice::create([
            'user_id' => $this->client->id,
            'order_id' => $ownOrder->id,
            'number' => 'OWN-INVOICE',
            'status' => 'Unpaid',
            'subtotal' => '30.00',
            'total' => '30.00',
            'notes' => 'PRIVATE-INVOICE-NOTE',
        ]);
        $ownInvoice->items()->create([
            'type' => 'custom',
            'description' => 'Visible invoice line',
            'amount' => '30.00',
        ]);
        $foreignInvoice = Invoice::create([
            'user_id' => $this->otherClient->id,
            'number' => 'FOREIGN-INVOICE',
            'status' => 'Unpaid',
            'total' => '99.00',
        ]);
        $ownService = Service::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'name' => 'OWN-SERVICE',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'renew_amount' => '25.00',
            'notes' => 'Visible customer note',
            'internal_notes' => 'PRIVATE-SERVICE-NOTE',
        ]);
        $foreignService = Service::create([
            'user_id' => $this->otherClient->id,
            'product_id' => $this->product->id,
            'name' => 'FOREIGN-SERVICE',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
        ]);
        $foreignCartItem = CartItem::create([
            'user_id' => $this->otherClient->id,
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);

        foreach (['/portal', '/portal/products', '/portal/cart', '/portal/orders', '/portal/invoices', '/portal/services', '/portal/profile'] as $path) {
            $this->actingAs($this->client)->get($path)->assertOk();
        }

        $this->get('/portal/orders/'.$ownOrder->id)->assertOk()->assertSee('OWN-ORDER-SNAPSHOT');
        $this->get('/portal/invoices/'.$ownInvoice->id)
            ->assertOk()
            ->assertSee('Visible invoice line')
            ->assertDontSee('PRIVATE-INVOICE-NOTE');
        $this->get('/portal/services/'.$ownService->id)
            ->assertOk()
            ->assertSee('Visible customer note')
            ->assertDontSee('PRIVATE-SERVICE-NOTE');
        $this->get('/portal/orders/'.$foreignOrder->id)->assertNotFound();
        $this->get('/portal/invoices/'.$foreignInvoice->id)->assertNotFound();
        $this->get('/portal/services/'.$foreignService->id)->assertNotFound();
        $this->delete('/portal/cart/'.$foreignCartItem->id)->assertNotFound();
        $this->assertDatabaseHas('cart_items', ['id' => $foreignCartItem->id]);
    }

    public function test_whole_cart_checkout_uses_stable_rows_and_is_idempotent(): void
    {
        $cartItem = CartItem::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);
        $this->actingAs($this->client)->patch('/portal/cart/'.$cartItem->id, [
            'quantity' => 2,
        ])->assertRedirect();
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id, 'quantity' => 2]);

        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'payment' => 'BankTransfer',
            'idempotency_key' => $idempotencyKey,
        ];
        $this->post('/portal/cart/checkout', $payload)->assertSessionHas('pending');

        $invoice = Invoice::query()->sole();
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertSame('BankTransfer', $invoice->payment_method);
        $this->assertSame('60.00', $invoice->total);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('services', 0);

        $this->post('/portal/cart/checkout', $payload)
            ->assertRedirect('/portal/invoices/'.$invoice->id)
            ->assertSessionHas('pending');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_checkout_idempotency_uses_the_original_submitted_gateway_identity(): void
    {
        CartItem::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($this->client)->post('/portal/cart/checkout', [
            'payment' => 'BankTransfer',
            'idempotency_key' => $idempotencyKey,
        ])->assertSessionHas('pending');

        $invoice = Invoice::query()->sole();
        $order = Order::query()->sole();
        $invoiceState = $invoice->getRawOriginal();
        $orderState = $order->getRawOriginal();

        $this->post('/portal/cart/checkout', [
            'payment' => 'Credit',
            'idempotency_key' => $idempotencyKey,
        ])->assertSessionHasErrors('idempotency_key');

        $this->assertSame($invoiceState, $invoice->fresh()->getRawOriginal());
        $this->assertSame($orderState, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('services', 0);

        $invoice->update(['payment_method' => 'Credit']);
        $this->post('/portal/cart/checkout', [
            'payment' => 'BankTransfer',
            'idempotency_key' => $idempotencyKey,
        ])->assertRedirect('/portal/invoices/'.$invoice->id)
            ->assertSessionHas('pending');
        $this->assertSame('BankTransfer', $invoice->fresh()->payment_method);

        $invoice->update(['payment_method' => 'Credit']);
        $invoiceState = $invoice->fresh()->getRawOriginal();
        $this->post('/portal/cart/checkout', [
            'payment' => 'Credit',
            'idempotency_key' => $idempotencyKey,
        ])->assertSessionHasErrors('idempotency_key');
        $this->assertSame($invoiceState, $invoice->fresh()->getRawOriginal());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    #[DataProvider('unavailableCartStates')]
    public function test_unavailable_cart_lines_show_the_known_amount_and_block_checkout(
        string $state,
        string $warning,
        ?string $amount,
    ): void {
        $cycle = 'monthly';
        if ($state === 'product') {
            $this->product->update(['is_active' => false]);
        } elseif ($state === 'child-group') {
            ProductGroup::query()->whereKey($this->product->product_group_id)->update(['is_active' => false]);
        } elseif ($state === 'parent-group') {
            $group = ProductGroup::query()->findOrFail($this->product->product_group_id);
            ProductGroup::query()->whereKey($group->parent_id)->update(['is_active' => false]);
        } elseif ($state === 'price') {
            $cycle = 'annually';
            $this->product->prices()->where('billing_cycle', $cycle)->update(['is_active' => false]);
        } elseif ($state === 'missing-price') {
            $cycle = 'quarterly';
        } elseif ($state === 'stock') {
            $this->product->update(['quantity' => 0]);
        }

        CartItem::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'billing_cycle' => $cycle,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->client)->get('/portal/cart')
            ->assertOk()
            ->assertSee($warning)
            ->assertDontSee('¥0.00')
            ->assertSee('请先移除不可用项目')
            ->assertSee('disabled', false);
        if ($amount === null) {
            $response->assertSee('无法计价')->assertSee('无法计算');
        } else {
            $response->assertSee('¥'.$amount);
        }

        $this->post('/portal/cart/checkout', [
            'payment' => 'Credit',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public static function unavailableCartStates(): array
    {
        return [
            'inactive product' => ['product', '该商品已下架', '30.00'],
            'inactive child group' => ['child-group', '该商品分组已停用', '30.00'],
            'inactive parent group' => ['parent-group', '上级分组已停用', '30.00'],
            'inactive cycle price' => ['price', '该付款周期已不可用', '250.00'],
            'missing cycle price' => ['missing-price', '该付款周期已不可用', null],
            'insufficient stock' => ['stock', '当前库存不足', '30.00'],
        ];
    }

    public function test_credit_payment_settles_owned_invoice_and_external_payment_remains_pending(): void
    {
        $creditInvoice = $this->invoice('PORTAL-CREDIT', '30.00');
        $this->actingAs($this->client)->post('/portal/invoices/'.$creditInvoice->id.'/payment', [
            'payment' => 'Credit',
        ])->assertRedirect('/portal/invoices/'.$creditInvoice->id);

        $this->assertSame('Paid', $creditInvoice->fresh()->status);
        $this->assertSame('70.00', $this->client->fresh()->credit);
        $this->assertSame(1, Transaction::count());

        $externalInvoice = $this->invoice('PORTAL-EXTERNAL', '20.00');
        $this->post('/portal/invoices/'.$externalInvoice->id.'/payment', [
            'payment' => 'BankTransfer',
        ])->assertRedirect('/portal/invoices/'.$externalInvoice->id)
            ->assertSessionHas('pending', fn (string $message) => str_contains($message, 'PORTAL-EXTERNAL')
                && str_contains($message, (string) config('kjaiu.company_email')));

        $this->assertSame('Unpaid', $externalInvoice->fresh()->status);
        $this->assertSame('BankTransfer', $externalInvoice->fresh()->payment_method);
        $this->assertSame(1, Transaction::count());
        $this->assertDatabaseCount('services', 0);
        $this->get('/portal/invoices/'.$externalInvoice->id)
            ->assertOk()
            ->assertSee('PORTAL-EXTERNAL')
            ->assertSee((string) config('kjaiu.company_email'))
            ->assertSee('到账确认前状态保持待支付');

        $foreignInvoice = Invoice::create([
            'user_id' => $this->otherClient->id,
            'number' => 'FOREIGN-PAYMENT',
            'status' => 'Unpaid',
            'total' => '1.00',
        ]);
        $this->post('/portal/invoices/'.$foreignInvoice->id.'/payment', [
            'payment' => 'Credit',
        ])->assertNotFound();
    }

    public function test_service_renewal_and_auto_renew_are_scoped_and_validated(): void
    {
        $service = Service::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'name' => 'Renewable service',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'billing_anchor_day' => 15,
            'renew_amount' => '25.00',
            'next_due_at' => now()->addMonth(),
            'auto_renew' => false,
        ]);

        $this->actingAs($this->client)->get('/portal/services/'.$service->id)
            ->assertOk()
            ->assertSee('name="billing_cycle"', false)
            ->assertSee('name="auto_renew" value="1"', false);

        $this->patch('/portal/services/'.$service->id.'/auto-renew', [
            'auto_renew' => true,
        ])->assertRedirect();
        $this->assertTrue($service->fresh()->auto_renew);

        $this->post('/portal/services/'.$service->id.'/renewal', [
            'billing_cycle' => 'annually',
        ])->assertRedirect();
        $renewal = Invoice::query()->sole();
        $this->assertSame($this->client->id, $renewal->user_id);
        $this->assertSame('250.00', $renewal->total);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $renewal->id,
            'service_id' => $service->id,
            'type' => 'renew',
            'billing_cycle' => 'annually',
        ]);
        $this->get('/portal/invoices/'.$renewal->id)
            ->assertOk()
            ->assertSee('取消续费账单')
            ->assertSee('确认取消这张续费账单吗', false);

        $this->post('/portal/invoices/'.$renewal->id.'/cancel-renewal')
            ->assertRedirect('/portal/invoices/'.$renewal->id)
            ->assertSessionHas('success');
        $this->assertSame('Cancelled', $renewal->fresh()->status);
        $this->assertNull($renewal->fresh()->renewal_key);

        $this->post('/portal/services/'.$service->id.'/renewal', [
            'billing_cycle' => 'monthly',
        ])->assertRedirect();
        $replacement = Invoice::query()->where('status', 'Unpaid')->sole();
        $this->assertSame('monthly', $replacement->items()->where('type', 'renew')->value('billing_cycle'));

        $foreignService = Service::create([
            'user_id' => $this->otherClient->id,
            'product_id' => $this->product->id,
            'name' => 'Foreign service',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
        ]);
        $this->post('/portal/services/'.$foreignService->id.'/renewal', [
            'billing_cycle' => 'monthly',
        ])->assertNotFound();
        $this->patch('/portal/services/'.$foreignService->id.'/auto-renew', [
            'auto_renew' => true,
        ])->assertNotFound();
    }

    public function test_auto_renew_enablement_requires_due_product_and_supported_active_price(): void
    {
        $this->actingAs($this->client);
        $service = fn (array $attributes) => Service::create(array_merge([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'name' => 'Auto-renew validation',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'next_due_at' => now()->addMonth(),
            'auto_renew' => false,
        ], $attributes));

        $invalidServices = [
            $service(['status' => 'Pending']),
            $service(['status' => 'Suspended']),
            $service(['billing_cycle' => 'free']),
            $service(['next_due_at' => null]),
            $service(['product_id' => null]),
            $service(['billing_cycle' => 'quarterly']),
        ];
        $inactivePrice = $service(['billing_cycle' => 'annually']);
        $this->product->prices()->where('billing_cycle', 'annually')->update(['is_active' => false]);

        foreach ([...$invalidServices, $inactivePrice] as $invalidService) {
            $this->patch('/portal/services/'.$invalidService->id.'/auto-renew', [
                'auto_renew' => true,
            ])->assertSessionHasErrors('auto_renew');
            $this->assertFalse($invalidService->fresh()->auto_renew);
        }

        $inactiveProduct = $service([]);
        $this->product->update(['is_active' => false]);
        $this->patch('/portal/services/'.$inactiveProduct->id.'/auto-renew', [
            'auto_renew' => true,
        ])->assertSessionHasErrors('auto_renew');
        $this->assertFalse($inactiveProduct->fresh()->auto_renew);

        $invalidServices[4]->update(['auto_renew' => true]);
        $this->patch('/portal/services/'.$invalidServices[4]->id.'/auto-renew', [
            'auto_renew' => false,
        ])->assertSessionHas('success');
        $this->assertFalse($invalidServices[4]->fresh()->auto_renew);
    }

    public function test_manual_renewal_rejects_an_inactive_product(): void
    {
        $service = Service::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'name' => 'Archived product service',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'next_due_at' => now()->addMonth(),
        ]);
        $this->product->update(['is_active' => false]);

        $this->actingAs($this->client)->post('/portal/services/'.$service->id.'/renewal', [
            'billing_cycle' => 'monthly',
        ])->assertSessionHasErrors('service');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_customer_can_cancel_only_owned_unpaid_renewal_invoices(): void
    {
        $order = Order::create([
            'user_id' => $this->client->id,
            'status' => 'Pending',
            'subtotal' => '10.00',
            'total' => '10.00',
        ]);
        $purchase = $this->invoice('PURCHASE-NO-CANCEL', '10.00');
        $purchase->update(['order_id' => $order->id]);
        $recharge = $this->invoice('RECHARGE-NO-CANCEL', '10.00');
        $recharge->items()->delete();
        $recharge->items()->create(['type' => 'recharge', 'description' => 'Recharge', 'amount' => '10.00']);
        $malformed = $this->invoice('CUSTOM-NO-CANCEL', '10.00');
        $malformed->update(['renewal_key' => hash('sha256', 'custom-renewal-key')]);
        $paid = $this->invoice('PAID-RENEWAL-NO-CANCEL', '10.00');
        $paid->update([
            'status' => 'Paid',
            'renewal_key' => hash('sha256', 'paid-renewal-key'),
            'paid_at' => now(),
        ]);
        $paid->items()->delete();
        $paid->items()->create(['type' => 'renew', 'description' => 'Renew', 'amount' => '10.00']);
        $foreign = Invoice::create([
            'user_id' => $this->otherClient->id,
            'number' => 'FOREIGN-RENEWAL-NO-CANCEL',
            'renewal_key' => hash('sha256', 'foreign-renewal-key'),
            'status' => 'Unpaid',
            'total' => '10.00',
        ]);
        $foreign->items()->create(['type' => 'renew', 'description' => 'Renew', 'amount' => '10.00']);

        $this->actingAs($this->client);
        foreach ([$purchase, $recharge, $malformed, $paid] as $invoice) {
            $this->get('/portal/invoices/'.$invoice->id)
                ->assertOk()
                ->assertDontSee('取消续费账单');
            $this->post('/portal/invoices/'.$invoice->id.'/cancel-renewal')
                ->assertSessionHasErrors('invoice');
            $this->assertSame($invoice->status, $invoice->fresh()->status);
        }
        $this->post('/portal/invoices/'.$foreign->id.'/cancel-renewal')->assertNotFound();
    }

    public function test_supplier_mapped_or_linked_service_cannot_renew_or_enable_auto_renew(): void
    {
        $service = fn (string $name) => Service::create([
            'user_id' => $this->client->id,
            'product_id' => $this->product->id,
            'name' => $name,
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'billing_anchor_day' => 26,
            'activated_at' => now(),
            'next_due_at' => now()->addMonth(),
            'auto_renew' => false,
        ]);
        $mappedService = $service('Supplier-mapped service');
        $linkedService = $service('Supplier-linked service');
        $account = SupplierAccount::create([
            'code' => 'portal-renewal-supplier',
            'name' => 'Portal renewal supplier',
            'base_url' => 'https://supplier.example.test',
            'credentials' => ['username' => 'api-user', 'password' => 'api-secret'],
        ]);
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'portal-renewal-product',
            'name' => 'Portal upstream product',
            'billing_cycles' => ['month'],
        ]);
        $mapping = SupplierProductMapping::createFor($account, $catalog, $this->product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'month',
        ]);
        SupplierServiceLink::createFor($account, $linkedService, $mapping, [
            'upstream_service_id' => 'portal-upstream-service',
            'upstream_status' => 'Active',
        ]);

        $this->actingAs($this->client)->get('/portal/services/'.$mappedService->id)
            ->assertOk()
            ->assertSee('当前版本暂不支持上游供应商服务自动续费')
            ->assertDontSee('name="billing_cycle"', false)
            ->assertDontSee('name="auto_renew" value="1"', false);
        $this->post('/portal/services/'.$mappedService->id.'/renewal', [
            'billing_cycle' => 'monthly',
        ])->assertSessionHasErrors([
            'service' => '当前版本暂不支持上游供应商服务续费',
        ]);
        $this->patch('/portal/services/'.$mappedService->id.'/auto-renew', [
            'auto_renew' => true,
        ])->assertSessionHasErrors([
            'auto_renew' => '当前版本暂不支持上游供应商服务自动续费',
        ]);
        $this->assertFalse($mappedService->fresh()->auto_renew);

        $mappedService->update(['auto_renew' => true]);
        $this->get('/portal/services')
            ->assertOk()
            ->assertSee('当前服务不支持自动续费')
            ->assertDontSee('余额自动续费已开启');
        $this->get('/portal/services/'.$mappedService->id)
            ->assertOk()
            ->assertSee('关闭原有自动续费设置')
            ->assertDontSee('name="auto_renew" value="1"', false);
        $this->patch('/portal/services/'.$mappedService->id.'/auto-renew', [
            'auto_renew' => false,
        ])->assertSessionHas('success');
        $this->assertFalse($mappedService->fresh()->auto_renew);

        $mapping->update(['is_active' => false]);
        $this->get('/portal/services/'.$linkedService->id)
            ->assertOk()
            ->assertSee('当前版本暂不支持上游供应商服务自动续费')
            ->assertDontSee('name="billing_cycle"', false)
            ->assertDontSee('name="auto_renew" value="1"', false);
        $this->post('/portal/services/'.$linkedService->id.'/renewal', [
            'billing_cycle' => 'monthly',
        ])->assertSessionHasErrors([
            'service' => '当前版本暂不支持上游供应商服务续费',
        ]);
        $this->patch('/portal/services/'.$linkedService->id.'/auto-renew', [
            'auto_renew' => true,
        ])->assertSessionHasErrors([
            'auto_renew' => '当前版本暂不支持上游供应商服务自动续费',
        ]);

        $this->assertFalse($linkedService->fresh()->auto_renew);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame('100.00', $this->client->fresh()->credit);
    }

    public function test_profile_updates_only_safe_fields_and_password_change_preserves_current_session(): void
    {
        $verifiedAt = $this->client->email_verified_at?->getTimestamp();
        $this->actingAs($this->client)->patch('/portal/profile', [
            'name' => 'Updated Portal Name',
            'email' => $this->client->email,
            'phone_code' => '+86',
            'phone' => '13800138000',
            'real_name' => 'Portal Client',
            'company_name' => 'Portal Company',
            'locale' => 'zh_CN',
            'role' => 'admin',
            'status' => 'Suspended',
            'credit' => '999999.00',
            'token_version' => 999,
        ])->assertRedirect();

        $updated = $this->client->fresh();
        $this->assertSame('Updated Portal Name', $updated->name);
        $this->assertSame('client', $updated->role);
        $this->assertSame('Active', $updated->status);
        $this->assertSame('100.00', $updated->credit);
        $this->assertSame(3, $updated->token_version);
        $this->assertSame($verifiedAt, $updated->email_verified_at?->getTimestamp());

        $this->put('/portal/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('current_password');
        $this->assertSame(3, $this->client->fresh()->token_version);

        $this->put('/portal/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect()->assertSessionHas('success');

        $changed = $this->client->fresh();
        $this->assertTrue(Hash::check('new-password', $changed->password));
        $this->assertSame(4, $changed->token_version);
        $this->assertAuthenticatedAs($changed);
        $this->assertSame(
            4,
            session(EnsureActiveUser::CREDENTIAL_VERSION_SESSION_KEY),
        );
        $this->get('/portal')->assertOk();
    }

    public function test_profile_email_is_read_only_and_submitted_email_is_ignored(): void
    {
        $profile = $this->client->fresh();
        $verifiedAt = $profile->email_verified_at?->getTimestamp();
        $this->actingAs($this->client)->get('/portal/profile')
            ->assertOk()
            ->assertSee('登录邮箱')
            ->assertSee('readonly', false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('重新完成邮箱验证');

        $this->patch('/portal/profile', [
            'name' => 'Read-only Email Profile',
            'email' => 'changed-email@example.test',
            'phone_code' => $profile->phone_code,
            'phone' => $profile->phone,
            'real_name' => $profile->real_name,
            'company_name' => $profile->company_name,
            'locale' => $profile->locale,
        ])->assertSessionHas('success');

        $updated = $this->client->fresh();
        $this->assertSame('Read-only Email Profile', $updated->name);
        $this->assertSame('portal@example.test', $updated->email);
        $this->assertSame($verifiedAt, $updated->email_verified_at?->getTimestamp());
    }

    public function test_password_change_revokes_other_database_sessions_and_rotates_the_current_session(): void
    {
        config(['session.driver' => 'database']);
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->make('auth')->forgetGuards();

        $oldRememberToken = Str::random(60);
        $this->client->forceFill(['remember_token' => $oldRememberToken])->save();
        $cookieName = (string) config('session.cookie');
        $login = $this->post('/login', [
            'email' => $this->client->email,
            'password' => 'old-password',
        ])->assertRedirect('/portal')->assertCookie($cookieName);
        $oldSessionId = (string) $login->getCookie($cookieName)?->getValue();
        $this->assertNotSame('', $oldSessionId);
        $this->assertDatabaseHas('sessions', [
            'id' => $oldSessionId,
            'user_id' => $this->client->id,
        ]);

        $otherSessionIds = [Str::random(40), Str::random(40)];
        $foreignSessionId = Str::random(40);
        $payload = base64_encode(serialize([]));
        foreach ($otherSessionIds as $sessionId) {
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $this->client->id,
                'payload' => $payload,
                'last_activity' => now()->timestamp,
            ]);
        }
        DB::table('sessions')->insert([
            'id' => $foreignSessionId,
            'user_id' => $this->otherClient->id,
            'payload' => $payload,
            'last_activity' => now()->timestamp,
        ]);

        $this->withCookie($cookieName, $oldSessionId);
        $response = $this->put('/portal/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect()->assertSessionHas('success')->assertCookie($cookieName);
        $newSessionId = (string) $response->getCookie($cookieName)?->getValue();
        $this->assertNotSame($oldSessionId, $newSessionId);

        foreach ([$oldSessionId, ...$otherSessionIds] as $sessionId) {
            $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        }
        $this->assertDatabaseHas('sessions', [
            'id' => $newSessionId,
            'user_id' => $this->client->id,
        ]);
        $this->assertDatabaseHas('sessions', [
            'id' => $foreignSessionId,
            'user_id' => $this->otherClient->id,
        ]);

        $changed = $this->client->fresh();
        $this->assertTrue(Hash::check('new-password', $changed->password));
        $this->assertSame(4, $changed->token_version);
        $this->assertNotSame($oldRememberToken, $changed->remember_token);
        $this->assertAuthenticatedAs($changed);
        $this->withCookie($cookieName, $newSessionId)->get('/portal')->assertOk();
    }

    public function test_only_administrators_see_the_management_link_in_the_portal(): void
    {
        $this->actingAs($this->client)
            ->get('/portal')
            ->assertOk()
            ->assertDontSee('进入管理后台');

        $administrator = User::factory()->administrator()->create();
        $this->actingAs($administrator)
            ->get('/portal')
            ->assertOk()
            ->assertSee('进入管理后台');
    }

    private function invoice(string $number, string $total): Invoice
    {
        $invoice = Invoice::create([
            'user_id' => $this->client->id,
            'number' => $number,
            'status' => 'Unpaid',
            'subtotal' => $total,
            'total' => $total,
        ]);
        $invoice->items()->create([
            'type' => 'custom',
            'description' => 'Portal invoice item',
            'amount' => $total,
        ]);

        return $invoice;
    }
}
