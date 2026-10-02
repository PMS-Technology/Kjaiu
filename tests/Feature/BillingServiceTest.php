<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Pricing;
use App\Models\PromoCode;
use App\Services\CartService;
use App\Services\InvoiceService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The pricing and invoicing rules are the part of the platform that loses real
 * money when they are wrong, so they are covered directly rather than only
 * through HTTP tests.
 */
class BillingServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected PricingService $pricing;
    protected InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = new PricingService();
        $this->invoices = new InvoiceService();
    }

    public function test_unavailable_cycles_are_reported_as_null_not_zero(): void
    {
        // The schema stores -1 for "cycle not offered"; treating that as a price
        // would let customers buy unsupported cycles for free.
        $cycles = array_fill_keys(Pricing::CYCLES, '-1.00');
        $pricing = new Pricing(array_merge($cycles, ['monthly' => '9.90', 'annually' => '-1.00', 'quarterly' => '0.00']));

        $this->assertSame(9.9, $pricing->priceFor('monthly'));
        $this->assertNull($pricing->priceFor('annually'));
        $this->assertSame(0.0, $pricing->priceFor('quarterly'));
        $this->assertSame(['monthly', 'quarterly'], $pricing->availableCycles());
    }

    public function test_setup_fees_map_to_the_right_cycle_column(): void
    {
        $pricing = new Pricing(['msetupfee' => '10.00', 'asetupfee' => '25.00']);

        $this->assertSame(10.0, $pricing->setupFeeFor('monthly'));
        $this->assertSame(25.0, $pricing->setupFeeFor('annually'));
        $this->assertSame(0.0, $pricing->setupFeeFor('quarterly'));
    }

    public function test_percentage_and_fixed_promotions_are_capped_at_the_subtotal(): void
    {
        $percent = new PromoCode(['type' => 'percentage', 'value' => '10.00']);
        $fixed = new PromoCode(['type' => 'fixed', 'value' => '25.00']);
        $over = new PromoCode(['type' => 'fixed', 'value' => '500.00']);

        $this->assertSame(10.0, $percent->discountFor(100.0));
        $this->assertSame(25.0, $fixed->discountFor(100.0));
        // A fixed discount larger than the cart must not produce a negative total.
        $this->assertSame(100.0, $over->discountFor(100.0));
    }

    public function test_expired_and_exhausted_promotions_are_refused(): void
    {
        $expired = new PromoCode(['expiration_time' => time() - 3600]);
        $exhausted = new PromoCode(['max_times' => 5, 'used' => 5]);
        $future = new PromoCode(['start_time' => time() + 3600]);
        $valid = new PromoCode(['expiration_time' => time() + 3600, 'max_times' => 5, 'used' => 1]);

        $this->assertFalse($expired->isUsable());
        $this->assertFalse($exhausted->isUsable());
        $this->assertFalse($future->isUsable());
        $this->assertTrue($valid->isUsable());
    }

    public function test_billing_cycles_map_to_the_expected_day_counts(): void
    {
        $this->assertSame(1, $this->pricing->cycleDays('day'));
        $this->assertSame(30, $this->pricing->cycleDays('monthly'));
        $this->assertSame(365, $this->pricing->cycleDays('annually'));
        $this->assertNull($this->pricing->cycleDays('onetime'));

        $from = strtotime('2026-01-01 00:00:00');
        $this->assertSame(strtotime('2026-01-31 00:00:00'), $this->pricing->nextDueDate('monthly', $from));
    }

    public function test_group_discount_reduces_the_net_amount(): void
    {
        $this->assertSame(90.0, $this->pricing->groupDiscount(100.0, 10));
        $this->assertSame(100.0, $this->pricing->groupDiscount(100.0, 0));
        $this->assertSame(100.0, $this->pricing->groupDiscount(100.0, null));
    }

    public function test_invoice_totals_are_derived_from_its_items(): void
    {
        $client = $this->makeClient();

        $invoice = $this->invoices->create($client, [
            ['type' => 'hosting', 'description' => 'Basic plan - monthly', 'amount' => 30.00],
            ['type' => 'hosting', 'description' => 'Extra IP - monthly', 'amount' => 20.00],
        ], time() + 86400, 'hosting', ['taxrate' => 10]);

        // subtotal 50.00, 10% tax, total 55.00
        $this->assertSame('50.00', (string) $invoice->subtotal);
        $this->assertSame('5.00', (string) $invoice->tax);
        $this->assertSame('55.00', (string) $invoice->total);
        $this->assertCount(2, $invoice->items);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
    }

    public function test_paying_with_insufficient_balance_changes_nothing(): void
    {
        $client = $this->makeClient(credit: 10.00);

        $invoice = $this->invoices->create($client, [
            ['type' => 'hosting', 'description' => 'Basic plan', 'amount' => 50.00],
        ]);

        $this->assertFalse($this->invoices->payWithCredit($invoice, $client));

        // Neither the balance nor the invoice may move on a failed settlement.
        $this->assertSame('10.00', (string) $client->fresh()->credit);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_paying_with_sufficient_balance_settles_the_invoice(): void
    {
        $client = $this->makeClient(credit: 100.00);

        $invoice = $this->invoices->create($client, [
            ['type' => 'hosting', 'description' => 'Basic plan', 'amount' => 50.00],
        ]);

        $this->assertTrue($this->invoices->payWithCredit($invoice, $client));

        $client->refresh();
        $invoice->refresh();

        $this->assertSame('50.00', (string) $client->credit);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertGreaterThan(0, (int) $invoice->paid_time);

        // The settlement must be recorded on the balance ledger as a debit.
        $this->assertDatabaseHas('credit', [
            'uid' => $client->id,
            'amount' => -50.00,
        ]);
    }

    public function test_outstanding_amount_is_zero_once_paid(): void
    {
        $client = $this->makeClient(credit: 100.00);

        $invoice = $this->invoices->create($client, [
            ['type' => 'hosting', 'description' => 'Basic plan', 'amount' => 40.00],
        ]);

        $this->assertSame(40.0, $this->invoices->outstanding($invoice));

        $this->invoices->payWithCredit($invoice, $client);

        $this->assertSame(0.0, $this->invoices->outstanding($invoice->fresh()));
    }

    public function test_config_option_totals_price_each_selected_choice(): void
    {
        $product = Product::create([
            'type' => 'cloud',
            'gid' => $this->makeGroup()->id,
            'name' => 'Config option product',
            'pay_type' => json_encode([]),
            'create_time' => time(),
        ]);

        $group = \App\Models\ProductConfigGroup::create(['name' => 'Resources']);
        \App\Models\ProductConfigLink::create(['gid' => $group->id, 'pid' => $product->id]);

        $option = \App\Models\ProductConfigOption::create([
            'gid' => $group->id,
            'option_name' => 'Memory',
            'option_type' => \App\Models\ProductConfigOption::TYPE_DROPDOWN,
            'order' => 1,
        ]);

        $small = \App\Models\ProductConfigOptionSub::create(['config_id' => $option->id, 'option_name' => '1G', 'sort_order' => 1]);
        $large = \App\Models\ProductConfigOptionSub::create(['config_id' => $option->id, 'option_name' => '4G', 'sort_order' => 2]);

        $currency = Currency::query()->first()
            ?? Currency::create(['code' => 'CNY', 'prefix' => '¥', 'suffix' => '元', 'format' => '2', 'rate' => 1, 'default' => 1]);

        Pricing::create([
            'type' => 'configoptions', 'currency' => $currency->id, 'relid' => $large->id, 'monthly' => '20.00',
        ]);

        $total = $this->pricing->configOptionsTotal($product, [
            ['option' => $option->id, 'value' => $large->id],
        ], 'monthly', $currency->id);

        $this->assertSame(20.0, $total);

        // A choice with no pricing row contributes nothing rather than failing.
        $this->assertSame(0.0, $this->pricing->configOptionsTotal($product, [
            ['option' => $option->id, 'value' => $small->id],
        ], 'monthly', $currency->id));
    }

    protected function makeGroup(): ProductGroup
    {
        return ProductGroup::create([
            'name' => 'Test group ' . uniqid(),
            'gid' => 1,
            'create_time' => time(),
            'order' => 0,
        ]);
    }

    protected function makeClient(float $credit = 0.0): \App\Models\Client
    {
        return \App\Models\Client::create([
            'username' => 'billing-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => '###' . md5('x'),
            'status' => \App\Models\Client::STATUS_ACTIVE,
            'credit' => $credit,
            'taxexempt' => 0,
            'create_time' => time(),
        ]);
    }
}
