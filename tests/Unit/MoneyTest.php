<?php

namespace Tests\Unit;

use App\Services\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_converts_decimal_amounts_without_floating_point_math(
        string|int|float $amount,
        int $minor,
        string $formatted,
    ): void {
        $this->assertSame($minor, Money::toMinor($amount));
        $this->assertSame($formatted, Money::format($minor));
    }

    public static function amounts(): array
    {
        return [
            'zero' => ['0', 0, '0.00'],
            'integer' => [12, 1200, '12.00'],
            'one decimal' => ['12.3', 1230, '12.30'],
            'two decimals' => ['12.34', 1234, '12.34'],
            'negative' => ['-0.05', -5, '-0.05'],
            'float boundary' => [1.25, 125, '1.25'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_ambiguous_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor($amount);
    }

    public static function invalidAmounts(): array
    {
        return [
            ['1.001'],
            ['1e3'],
            ['1,000.00'],
            ['not-money'],
            [''],
        ];
    }
}
