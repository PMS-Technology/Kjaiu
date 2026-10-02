<?php

namespace App\Models;

/**
 * Pricing row (`shd_pricing`).
 *
 * One row per currency and related object. Cycle columns hold -1 when the
 * cycle is not offered, matching the original semantics.
 */
class Pricing extends ShdModel
{
    protected $table = 'pricing';

    /** Supported billing cycles in the order the admin panel presents them. */
    public const CYCLES = [
        'onetime', 'hour', 'day', 'ontrial', 'monthly', 'quarterly', 'semiannually',
        'annually', 'biennially', 'triennially', 'fourly', 'fively', 'sixly',
        'sevenly', 'eightly', 'ninely', 'tenly',
    ];

    /** Setup fee column suffix per cycle. */
    public const SETUP_COLUMNS = [
        'onetime' => 'osetupfee',
        'hour' => 'hsetupfee',
        'day' => 'dsetupfee',
        'ontrial' => 'ontrialfee',
        'monthly' => 'msetupfee',
        'quarterly' => 'qsetupfee',
        'semiannually' => 'ssetupfee',
        'annually' => 'asetupfee',
        'biennially' => 'bsetupfee',
        'triennially' => 'tsetupfee',
        'fourly' => 'foursetupfee',
        'fively' => 'fivesetupfee',
        'sixly' => 'sixsetupfee',
        'sevenly' => 'sevensetupfee',
        'eightly' => 'eightsetupfee',
        'ninely' => 'ninesetupfee',
        'tenly' => 'tensetupfee',
    ];

    protected $casts = [
        'currency' => 'integer',
        'relid' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'relid');
    }

    public function currencyModel()
    {
        return $this->belongsTo(Currency::class, 'currency');
    }

    /**
     * Price for a cycle, or null when the cycle is unavailable.
     */
    public function priceFor(string $cycle): ?float
    {
        if (! in_array($cycle, self::CYCLES, true)) {
            return null;
        }

        $value = (float) $this->getAttribute($cycle);

        return $value < 0 ? null : $value;
    }

    /**
     * Setup fee for a cycle.
     */
    public function setupFeeFor(string $cycle): float
    {
        $column = self::SETUP_COLUMNS[$cycle] ?? null;

        return $column === null ? 0.0 : max(0.0, (float) $this->getAttribute($column));
    }

    /**
     * Cycles this pricing row actually offers.
     */
    public function availableCycles(): array
    {
        $cycles = [];

        foreach (self::CYCLES as $cycle) {
            if ($this->priceFor($cycle) !== null) {
                $cycles[] = $cycle;
            }
        }

        return $cycles;
    }
}
