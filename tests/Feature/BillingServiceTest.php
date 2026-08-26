<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\SupplierAccount;
use App\Models\SupplierCatalogProduct;
use App\Models\SupplierOperation;
use App\Models\SupplierProductMapping;
use App\Models\SupplierServiceLink;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\JwtService;
use App\Services\SupplierProvisioningOutbox;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        config()->set('app.url', 'https://billing.kjaiu.test');

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

    public function test_mapped_settlement_queues_one_snapshot_per_pending_service_without_http(): void
    {
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:30:00'));
        $product = $this->product('Mapped compute', '30.00', null, true, false);
        $mappingOptions = [
            'configoption' => ['image' => 'ubuntu', 'region' => 'upstream'],
            'promotion' => 'standard',
        ];
        $mapping = $this->supplierMapping($product, 'provision', $mappingOptions);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'configuration' => [],
        ]);

        $invoice = $this->billing->checkout($this->user, null, 'mapped-provision', 'Credit');
        $replayedInvoice = $this->billing->checkout(
            $this->user,
            null,
            'mapped-provision',
            'Credit',
        );
        $this->assertSame($invoice->id, $replayedInvoice->id);
        $this->assertDatabaseCount('supplier_order_item_routes', 1);
        try {
            $mapping->update([
                'upstream_billing_cycle' => 'year',
                'options' => ['configoption' => ['image' => 'changed']],
            ]);
            $this->fail('Expected frozen mapping identity to remain immutable.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Supplier mappings with historical references cannot change routing identity.',
                $exception->getMessage(),
            );
        }
        $mapping->refresh()->update(['is_active' => false]);
        try {
            $mapping->catalogProduct->update([
                'upstream_product_id' => 'changed-live-product',
            ]);
            $this->fail('Expected frozen catalog identity to remain immutable.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Upstream product IDs referenced by supplier history are immutable.',
                $exception->getMessage(),
            );
        }
        $mapping->catalogProduct->refresh()->update(['is_active' => false]);
        $this->billing->payWithCredit($this->user, $invoice);

        $services = Service::query()->orderBy('unit_index')->get();
        $operations = SupplierOperation::query()->orderBy('id')->get()->keyBy('service_id');
        $orderItem = $invoice->order->items->sole();
        $route = $orderItem->supplierRoute;
        $this->assertCount(2, $services);
        $this->assertCount(2, $operations);
        $this->assertNotNull($route);
        $this->assertSame(['Pending', 'Pending'], $services->pluck('status')->all());
        $this->assertTrue($services->every(fn (Service $service): bool => $service->activated_at === null));
        $this->assertTrue($services->every(fn (Service $service): bool => $service->billing_anchor_day === null));
        $this->assertTrue($services->every(fn (Service $service): bool => $service->next_due_at === null));

        $tokens = [];
        $downstreamIds = [];
        foreach ($services as $service) {
            $operation = $operations->get($service->id);
            $this->assertNotNull($operation);
            $this->assertSame(SupplierOperation::ACTION_PROVISION, $operation->action);
            $this->assertSame(SupplierOperation::STATUS_QUEUED, $operation->status);
            $this->assertSame('queued', $operation->step);
            $this->assertSame(0, $operation->attempts);
            $this->assertSame($mapping->supplier_account_id, $operation->supplier_account_id);
            $this->assertSame($mapping->id, $operation->supplier_product_mapping_id);
            $this->assertSame($route->id, $operation->supplier_order_item_route_id);
            $this->assertSame($invoice->order_id, $operation->order_id);
            $this->assertSame($orderItem->id, $operation->order_item_id);
            $this->assertSame($invoice->id, $operation->invoice_id);
            $this->assertSame($service->id, $operation->service_id);
            $this->assertNull($operation->supplier_service_link_id);
            $this->assertSame('provision:service:'.$service->id, $operation->idempotency_key);

            $payload = $operation->request_payload;
            $this->assertSame([
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'order_item_id' => $operation->order_item_id,
                'service_id' => $service->id,
                'unit_index' => $service->unit_index,
            ], $payload['local']);
            $this->assertSame(2, $payload['version']);
            $this->assertSame($mappingOptions, $payload['route']['mapping']['options']);
            $this->assertSame($product->id, $payload['route']['local']['product_id']);
            $this->assertSame('monthly', $payload['route']['local']['billing_cycle']);
            $this->assertSame('upstream-provision', $payload['route']['upstream']['product_id']);
            $this->assertSame('month', $payload['route']['upstream']['billing_cycle']);
            $this->assertSame(1, $payload['route']['upstream']['qty']);
            $this->assertSame($mappingOptions, $payload['route']['upstream']['options']);
            $this->assertSame([
                'image' => 'ubuntu',
                'region' => 'upstream',
            ], $payload['route']['upstream']['configoption']);
            $this->assertSame('30.00', $payload['route']['upstream']['expected_amount']);
            $this->assertSame('CNY', $payload['route']['upstream']['currency']);
            $this->assertArrayNotHasKey('configuration', $payload['route']['upstream']);
            $this->assertStringNotContainsString('customer', json_encode($payload, JSON_THROW_ON_ERROR));
            $this->assertSame('https://billing.kjaiu.test', $payload['correlation']['downstream_url']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $payload['correlation']['downstream_token']);
            $this->assertGreaterThanOrEqual(100_000_000_000_000, $payload['correlation']['downstream_id']);
            $this->assertLessThanOrEqual(999_999_999_999_999, $payload['correlation']['downstream_id']);
            $this->assertSame(hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )), $operation->request_hash);
            $tokens[] = $payload['correlation']['downstream_token'];
            $downstreamIds[] = $payload['correlation']['downstream_id'];
        }
        $this->assertCount(2, array_unique($tokens));
        $this->assertCount(2, array_unique($downstreamIds));

        $firstService = $services->first();
        $firstOperation = $operations->get($firstService->id);
        $reused = DB::transaction(fn () => app(SupplierProvisioningOutbox::class)->queueProvision(
            $invoice->fresh('order'),
            $orderItem,
            $firstService,
            $route,
        ));
        $this->assertSame($firstOperation->id, $reused->id);

        $this->billing->payWithCredit($this->user->fresh(), $invoice->fresh());
        $this->assertDatabaseCount('supplier_operations', 2);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_mapped_checkout_rejects_forged_configuration_before_invoice_or_stock_reservation(): void
    {
        $product = $this->product('Mapped configured compute', '45.00', 5, true);
        $this->supplierMapping($product, 'configured', [
            'configoption' => ['cpu' => 2, 'image' => 'ubuntu'],
        ]);
        $forgedConfiguration = [
            'cpu' => 128,
            'license' => 'expensive-enterprise-license',
        ];

        $this->postJson('/v1/cart', [
            'product_id' => $product->id,
            'billingcycle' => 'monthly',
            'qty' => 2,
            'configoption' => $forgedConfiguration,
        ], $this->headers())
            ->assertJsonPath('status', 400)
            ->assertJsonPath('msg', '当前上游映射商品暂不支持客户自定义配置');
        $this->assertDatabaseCount('cart_items', 0);

        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'configuration' => $forgedConfiguration,
        ]);

        try {
            $this->billing->checkout($this->user, null, 'forged-mapped-config', 'Credit');
            $this->fail('Mapped customer configuration must be rejected inside checkout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configoption', $exception->errors());
        }

        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('supplier_operations', 0);
        $this->assertDatabaseHas('cart_items', [
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_unmapped_auto_setup_remains_synchronous(): void
    {
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:30:00'));
        $product = $this->product('Local auto setup', '12.00', null, true, false);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);

        $invoice = $this->billing->checkout($this->user, null, 'local-auto-setup', 'Credit');
        $this->billing->payWithCredit($this->user, $invoice);

        $service = Service::query()->sole();
        $this->assertSame('Active', $service->status);
        $this->assertSame('2026-08-31 12:30:00', $service->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame(31, $service->billing_anchor_day);
        $this->assertSame('2026-09-30 12:30:00', $service->next_due_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('supplier_operations', 0);
        Http::assertNothingSent();
    }

    public function test_mapping_added_after_checkout_cannot_change_local_settlement(): void
    {
        Http::preventStrayRequests();
        $product = $this->product('Late mapped local service', '12.00', null, true, false);
        CartItem::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);

        $invoice = $this->billing->checkout($this->user, null, 'late-mapping', 'Credit');
        $this->assertDatabaseCount('supplier_order_item_routes', 0);
        $this->supplierMapping($product, 'late-mapping');

        $this->billing->payWithCredit($this->user, $invoice);

        $this->assertSame('Active', Service::query()->sole()->status);
        $this->assertDatabaseCount('supplier_order_item_routes', 0);
        $this->assertDatabaseCount('supplier_operations', 0);
        Http::assertNothingSent();
    }

    public function test_mapped_renewal_is_blocked_without_charging_or_consuming_existing_queue(): void
    {
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-08-26 12:30:00'));
        $product = $this->product('Mapped renewal', '25.00', null, true, false);
        $mapping = $this->supplierMapping($product, 'renewal');
        $service = Service::create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'name' => 'Mapped suspended service',
            'status' => 'Suspended',
            'billing_cycle' => 'monthly',
            'billing_anchor_day' => 31,
            'renew_amount' => '19.00',
            'next_due_at' => Carbon::parse('2026-09-30 12:00:00'),
        ]);
        $serviceLink = SupplierServiceLink::createFor(
            $mapping->account,
            $service,
            $mapping,
            ['upstream_service_id' => 'host-renewal-42', 'upstream_status' => 'Active'],
        );

        $paidInvoice = Invoice::create([
            'user_id' => $this->user->id,
            'number' => 'LEGACY-SUPPLIER-RENEWAL-PAID',
            'status' => 'Paid',
            'total' => '25.00',
            'paid_at' => now()->subMinute(),
        ]);
        $queuedRenewal = SupplierOperation::createFor(
            account: $mapping->account,
            attributes: [
                'action' => SupplierOperation::ACTION_RENEW,
                'status' => SupplierOperation::STATUS_QUEUED,
                'step' => 'queued',
                'idempotency_key' => 'legacy-renew:service:'.$service->id,
                'request_payload' => ['version' => 1, 'action' => SupplierOperation::ACTION_RENEW],
                'attempts' => 0,
                'available_at' => now(),
            ],
            productMapping: $mapping,
            invoice: $paidInvoice,
            service: $service,
            serviceLink: $serviceLink,
        );

        try {
            $this->billing->createRenewalInvoice($this->user, $service, 'monthly');
            $this->fail('Supplier-linked renewal invoice creation must be disabled.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                '当前版本暂不支持上游供应商服务续费',
                $exception->errors()['service'][0],
            );
        }

        $unpaidInvoice = Invoice::create([
            'user_id' => $this->user->id,
            'number' => 'LEGACY-SUPPLIER-RENEWAL-UNPAID',
            'renewal_key' => hash('sha256', 'legacy-supplier-renewal-unpaid'),
            'renewal_due_at' => $service->next_due_at,
            'status' => 'Unpaid',
            'subtotal' => '25.00',
            'total' => '25.00',
        ]);
        $unpaidInvoice->items()->create([
            'service_id' => $service->id,
            'type' => 'renew',
            'rel_id' => $service->id,
            'billing_cycle' => 'monthly',
            'description' => 'Legacy supplier renewal',
            'amount' => '25.00',
        ]);

        try {
            $this->billing->prepareGatewayPayment($this->user, $unpaidInvoice, 'BankTransfer');
            $this->fail('A supplier renewal invoice must not be sent to an external gateway.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                '当前版本暂不支持上游供应商服务续费',
                $exception->errors()['service'][0],
            );
        }
        $this->assertNull($unpaidInvoice->fresh()->payment_method);

        try {
            $this->billing->payWithCredit($this->user, $unpaidInvoice);
            $this->fail('A legacy supplier renewal invoice must not charge the customer.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                '当前版本暂不支持上游供应商服务续费',
                $exception->errors()['service'][0],
            );
        }

        try {
            $this->billing->recordPayment(
                $unpaidInvoice,
                'BankTransfer',
                'legacy-supplier-renewal-payment',
            );
            $this->fail('A supplier renewal invoice must not be recorded as externally paid.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                '当前版本暂不支持上游供应商服务续费',
                $exception->errors()['service'][0],
            );
        }

        $this->assertSame('500.00', $this->user->fresh()->credit);
        $this->assertSame('Unpaid', $unpaidInvoice->fresh()->status);
        $this->assertSame('0.00', $unpaidInvoice->fresh()->credit);
        $this->assertSame('Suspended', $service->fresh()->status);
        $this->assertSame('19.00', $service->fresh()->renew_amount);
        $this->assertSame(
            '2026-09-30 12:00:00',
            $service->fresh()->next_due_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(SupplierOperation::STATUS_QUEUED, $queuedRenewal->fresh()->status);
        $this->assertSame(0, $queuedRenewal->fresh()->attempts);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('supplier_operations', 1);
        Http::assertNothingSent();
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
        } catch (ValidationException $exception) {
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
            'setup_fee' => '0.00',
            'stock_control' => $stockControl,
            'quantity' => $stockControl ? $quantity : null,
            'auto_setup' => $autoSetup,
            'is_active' => true,
        ]);
    }

    private function supplierMapping(
        Product $product,
        string $suffix,
        array $options = [],
    ): SupplierProductMapping {
        $account = SupplierAccount::create([
            'code' => 'supplier-'.$suffix.'-'.bin2hex(random_bytes(4)),
            'name' => 'Supplier '.$suffix,
            'base_url' => 'https://supplier.test',
            'credentials' => ['username' => 'api-user', 'password' => 'api-secret'],
        ]);
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'upstream-'.$suffix,
            'name' => 'Upstream '.$suffix,
            'currency' => 'CNY',
            'billing_cycles' => ['month'],
            'metadata' => [
                'prices' => [
                    'month' => [
                        'price' => (string) $product->price,
                        'setup_fee' => (string) $product->setup_fee,
                    ],
                ],
            ],
        ]);

        return SupplierProductMapping::createFor($account, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'month',
            'options' => $options,
        ]);
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'JWT '.app(JwtService::class)->issue($this->user->fresh()),
        ];
    }
}
