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
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RenewalTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        $this->user = User::factory()->create(['credit' => '100.00']);
        $group = ProductGroup::create(['name' => 'Renewal products']);
        $this->product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Monthly renewal',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
            'is_active' => true,
        ]);
        $this->billing = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_renewal_invoice_rejects_missing_or_inactive_products_and_prices(): void
    {
        $missingProduct = $this->service(['product_id' => null, 'auto_renew' => false]);
        $this->assertRenewalValidation($missingProduct, 'monthly', 'service');

        $inactiveProduct = $this->service(['auto_renew' => false]);
        $this->product->update(['is_active' => false]);
        $this->assertRenewalValidation($inactiveProduct, 'monthly', 'service');
        $this->product->update(['is_active' => true]);

        $missingPrice = $this->service([
            'billing_cycle' => 'annually',
            'auto_renew' => false,
        ]);
        $this->assertRenewalValidation($missingPrice, 'annually', 'billing_cycle');

        $this->product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '250.00',
            'is_active' => false,
        ]);
        $inactivePrice = $this->service([
            'billing_cycle' => 'annually',
            'auto_renew' => false,
        ]);
        $this->assertRenewalValidation($inactivePrice, 'annually', 'billing_cycle');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_existing_generation_does_not_bypass_current_product_and_price_validation(): void
    {
        $monthlyService = $this->service(['auto_renew' => false]);
        $monthlyInvoice = $this->billing->createRenewalInvoice(
            $this->user,
            $monthlyService,
            'monthly',
        );
        $this->product->update(['is_active' => false]);
        $this->assertRenewalValidation($monthlyService, 'monthly', 'service');
        $this->product->update(['is_active' => true]);

        $annualPrice = $this->product->prices()->create([
            'billing_cycle' => 'annually',
            'price' => '250.00',
            'is_active' => true,
        ]);
        $annualService = $this->service([
            'billing_cycle' => 'annually',
            'auto_renew' => false,
            'next_due_at' => now()->addDay(),
        ]);
        $annualInvoice = $this->billing->createRenewalInvoice(
            $this->user,
            $annualService,
            'annually',
        );
        $annualPrice->update(['is_active' => false]);
        $this->assertRenewalValidation($annualService, 'annually', 'billing_cycle');

        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame('Unpaid', $monthlyInvoice->fresh()->status);
        $this->assertSame('Unpaid', $annualInvoice->fresh()->status);
    }

    public function test_scheduler_rejects_an_inactive_product_without_leaving_an_invoice(): void
    {
        $service = $this->service();
        $dueAt = $service->next_due_at->format('Y-m-d H:i:s');
        $this->product->update(['is_active' => false]);

        $this->assertSame(0, Artisan::call('kjaiu:auto-renew'));

        $this->assertStringContainsString(
            'Renewed 0 service(s); skipped 1.',
            Artisan::output(),
        );
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('100.00', $this->user->fresh()->credit);
        $this->assertSame($dueAt, $service->fresh()->next_due_at->format('Y-m-d H:i:s'));
    }

    public function test_scheduler_rechecks_stale_auto_renew_and_future_due_date(): void
    {
        $staleSelection = $this->service();
        Service::query()->whereKey($staleSelection->id)->update(['auto_renew' => false]);
        $this->assertAutoRenewValidation($staleSelection, 'service');

        $futureService = $this->service(['next_due_at' => now()->addMinute()]);
        $this->assertAutoRenewValidation($futureService, 'service');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('100.00', $this->user->fresh()->credit);
    }

    public function test_insufficient_credit_rolls_back_scheduler_invoice_creation(): void
    {
        $this->user->update(['credit' => '24.99']);
        $service = $this->service();
        $dueAt = $service->next_due_at->format('Y-m-d H:i:s');

        $this->assertSame(0, Artisan::call('kjaiu:auto-renew'));

        $this->assertStringContainsString(
            'Renewed 0 service(s); skipped 1.',
            Artisan::output(),
        );
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('24.99', $this->user->fresh()->credit);
        $this->assertSame($dueAt, $service->fresh()->next_due_at->format('Y-m-d H:i:s'));
    }

    public function test_scheduler_debits_and_invoices_each_due_generation_exactly_once(): void
    {
        $service = $this->service();
        $renewalDueAt = $service->next_due_at->copy();
        $expectedKey = hash('sha256', implode('|', [
            (string) $service->id,
            $renewalDueAt->copy()->utc()->format('Y-m-d H:i:s'),
        ]));

        $this->assertSame(0, Artisan::call('kjaiu:auto-renew'));
        $this->assertStringContainsString(
            'Renewed 1 service(s); skipped 0.',
            Artisan::output(),
        );
        $this->assertSame(0, Artisan::call('kjaiu:auto-renew'));

        $invoice = Invoice::query()->sole();
        $transaction = Transaction::query()->sole();
        $this->assertSame('Paid', $invoice->status);
        $this->assertSame($expectedKey, $invoice->renewal_key);
        $this->assertSame($invoice->id, $transaction->invoice_id);
        $this->assertSame('25.00', $transaction->amount_out);
        $this->assertSame('75.00', $this->user->fresh()->credit);
        $this->assertTrue($service->fresh()->next_due_at->isFuture());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_scheduler_skips_mapped_and_linked_services_but_renews_local_service(): void
    {
        $mappedService = $this->service();
        $mapping = $this->supplierMapping($this->product);
        $linkedProduct = Product::create([
            'product_group_id' => $this->product->product_group_id,
            'name' => 'Linked scheduled renewal',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
            'is_active' => true,
        ]);
        $linkedService = $this->service(['product_id' => $linkedProduct->id]);
        SupplierServiceLink::createFor($mapping->account, $linkedService, null, [
            'upstream_service_id' => 'scheduled-linked-service',
            'upstream_status' => 'Active',
        ]);

        $localProduct = Product::create([
            'product_group_id' => $this->product->product_group_id,
            'name' => 'Local scheduled renewal',
            'billing_cycle' => 'monthly',
            'price' => '25.00',
            'is_active' => true,
        ]);
        $localService = $this->service(['product_id' => $localProduct->id]);
        $mappedDueAt = $mappedService->next_due_at->copy();
        $linkedDueAt = $linkedService->next_due_at->copy();

        foreach ([$mappedService, $linkedService] as $supplierService) {
            try {
                $this->billing->createRenewalInvoice(
                    $this->user,
                    $supplierService,
                    'monthly',
                );
                $this->fail('Supplier-managed services must not create renewal invoices.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    '当前版本暂不支持上游供应商服务续费',
                    $exception->errors()['service'][0],
                );
            }
        }
        $this->assertDatabaseCount('invoices', 0);

        $this->assertSame(0, Artisan::call('kjaiu:auto-renew'));

        $this->assertStringContainsString(
            'Renewed 1 service(s); skipped 2.',
            Artisan::output(),
        );
        $invoice = Invoice::query()->sole();
        $this->assertSame($localService->id, $invoice->items()->where('type', 'renew')->value('service_id'));
        $this->assertSame('Paid', $invoice->status);
        $this->assertSame('75.00', $this->user->fresh()->credit);
        $this->assertTrue($localService->fresh()->next_due_at->isFuture());
        $this->assertTrue($mappedService->fresh()->next_due_at->equalTo($mappedDueAt));
        $this->assertTrue($linkedService->fresh()->next_due_at->equalTo($linkedDueAt));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_existing_paid_generation_is_idempotent_for_scheduler(): void
    {
        $service = $this->service();
        $renewalKey = hash('sha256', implode('|', [
            (string) $service->id,
            $service->next_due_at->copy()->utc()->format('Y-m-d H:i:s'),
        ]));
        $invoice = Invoice::create([
            'user_id' => $this->user->id,
            'number' => 'EXISTING-PAID-RENEWAL',
            'renewal_key' => $renewalKey,
            'renewal_due_at' => $service->next_due_at,
            'status' => 'Paid',
            'subtotal' => '25.00',
            'total' => '25.00',
            'credit' => '25.00',
            'paid_at' => now()->subMinute(),
            'payment_method' => 'Credit',
        ]);
        $invoice->items()->create([
            'service_id' => $service->id,
            'type' => 'renew',
            'rel_id' => $service->id,
            'billing_cycle' => 'monthly',
            'description' => 'Existing paid renewal',
            'amount' => '25.00',
        ]);

        $result = $this->billing->autoRenewDueService($service);

        $this->assertSame($invoice->id, $result->id);
        $this->assertSame('100.00', $this->user->fresh()->credit);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    private function service(array $attributes = []): Service
    {
        return Service::create(array_merge([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'name' => 'Renewable service',
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'billing_anchor_day' => 26,
            'renew_amount' => '25.00',
            'next_due_at' => now(),
            'auto_renew' => true,
        ], $attributes));
    }

    private function assertRenewalValidation(Service $service, string $cycle, string $key): void
    {
        try {
            $this->billing->createRenewalInvoice($this->user, $service, $cycle);
            $this->fail('Expected renewal invoice validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function assertAutoRenewValidation(Service $service, string $key): void
    {
        try {
            $this->billing->autoRenewDueService($service);
            $this->fail('Expected automatic renewal validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function supplierMapping(Product $product): SupplierProductMapping
    {
        $account = SupplierAccount::create([
            'code' => 'scheduled-renewal-supplier',
            'name' => 'Scheduled renewal supplier',
            'base_url' => 'https://supplier.example.test',
            'credentials' => ['username' => 'api-user', 'password' => 'api-secret'],
        ]);
        $catalog = SupplierCatalogProduct::createForAccount($account, [
            'upstream_product_id' => 'scheduled-renewal-product',
            'name' => 'Scheduled upstream product',
            'billing_cycles' => ['month'],
        ]);

        return SupplierProductMapping::createFor($account, $catalog, $product, [
            'local_billing_cycle' => 'monthly',
            'upstream_billing_cycle' => 'month',
        ]);
    }
}
