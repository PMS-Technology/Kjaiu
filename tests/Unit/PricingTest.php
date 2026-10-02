<?php

namespace Tests\Unit;

use App\Models\Pricing;
use App\Services\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * Pure pricing rules, exercised without the database.
 */
class PricingTest extends TestCase
{
    public function test_cycle_day_counts_cover_every_cycle_the_schema_defines(): void
    {
        $service = new PricingService();

        // Every named cycle except the non-recurring ones must resolve to a
        // day count, otherwise renewals would silently produce no due date.
        foreach (Pricing::CYCLES as $cycle) {
            $days = $service->cycleDays($cycle);

            if (in_array($cycle, ['onetime', 'ontrial'], true)) {
                $this->assertNull($days, "{$cycle} should not be recurring");

                continue;
            }

            $this->assertNotNull($days, "{$cycle} must resolve to a day count");
            $this->assertGreaterThanOrEqual(0, $days);
        }
    }

    public function test_multi_year_cycles_scale_with_the_year_count(): void
    {
        $service = new PricingService();

        $this->assertSame(365, $service->cycleDays('annually'));
        $this->assertSame(730, $service->cycleDays('biennially'));
        $this->assertSame(1095, $service->cycleDays('triennially'));
        $this->assertSame(3650, $service->cycleDays('tenly'));
    }

    public function test_money_rounds_half_up_to_two_decimals(): void
    {
        $this->assertSame(10.13, PricingService::money(10.125));
        $this->assertSame(0.01, PricingService::money(0.005));
        $this->assertSame(-5.0, PricingService::money(-5.0));
    }

    public function test_unknown_cycles_are_refused(): void
    {
        $pricing = new Pricing(['monthly' => '10.00']);

        $this->assertNull($pricing->priceFor('fortnightly'));
        $this->assertSame(0.0, $pricing->setupFeeFor('fortnightly'));
    }

    public function test_json_helper_falls_back_for_malformed_values(): void
    {
        $this->assertSame(['a' => 1], PricingService::decodeJson('{"a":1}', []));
        $this->assertSame([], PricingService::decodeJson('not-json', []));
        $this->assertSame([], PricingService::decodeJson('', []));
        $this->assertSame([], PricingService::decodeJson(null, []));
    }
}
