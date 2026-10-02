<?php

namespace App\Models;

/**
 * Currency (`shd_currencies`).
 */
class Currency extends ShdModel
{
    protected $table = 'currencies';

    protected $casts = [
        'rate' => 'decimal:5',
        'default' => 'integer',
    ];

    public static function default(): ?self
    {
        return static::query()->where('default', 1)->first()
            ?? static::query()->orderBy('id')->first();
    }

    /**
     * Format an amount using this currency's prefix/suffix configuration.
     */
    public function format(float $amount): string
    {
        $decimals = is_numeric($this->format) ? (int) $this->format : 2;

        return (string) $this->prefix . number_format($amount, $decimals, '.', '') . (string) $this->suffix;
    }
}
