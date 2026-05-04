<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function format_converts_integer_cents_to_dollar_string(): void
    {
        $this->assertSame('150000.00', Money::format(15000000));
        $this->assertSame('1.00', Money::format(100));
        $this->assertSame('0.99', Money::format(99));
        $this->assertSame('0.00', Money::format(0));
    }

    #[Test]
    public function format_returns_null_for_null_input(): void
    {
        $this->assertNull(Money::format(null));
    }

    #[Test]
    public function format_handles_large_values(): void
    {
        // $70 million = 7,000,000,000 cents
        $this->assertSame('70000000.00', Money::format(7_000_000_000));
    }

    #[Test]
    public function parse_converts_simple_dollar_strings_to_cents(): void
    {
        $this->assertSame(15000000, Money::parse('150000'));
        $this->assertSame(15000000, Money::parse('150000.00'));
        $this->assertSame(15050, Money::parse('150.50'));
        $this->assertSame(99, Money::parse('0.99'));
    }

    #[Test]
    public function parse_strips_currency_symbols(): void
    {
        $this->assertSame(5000000, Money::parse('$50000'));
        $this->assertSame(5000000, Money::parse('€50000'));
        $this->assertSame(5000000, Money::parse('£50000'));
    }

    #[Test]
    public function parse_strips_thousands_separators(): void
    {
        $this->assertSame(7_000_000_000, Money::parse('70,000,000'));
        $this->assertSame(7_000_000_000, Money::parse('$70,000,000'));
    }

    #[Test]
    public function parse_handles_whitespace(): void
    {
        $this->assertSame(5000000, Money::parse('  50000  '));
        $this->assertSame(5000000, Money::parse('$ 50000'));
    }

    #[Test]
    public function parse_returns_null_for_null_or_empty_input(): void
    {
        $this->assertNull(Money::parse(null));
        $this->assertNull(Money::parse(''));
        $this->assertNull(Money::parse('   '));
    }

    #[Test]
    public function parse_throws_on_unparseable_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('seventy million');
    }

    #[Test]
    public function parse_handles_floating_point_precision_edge_cases(): void
    {
        // 0.07 * 100 evaluates to 6.999... in raw float arithmetic.
        // The implementation uses round() to handle this correctly.
        $this->assertSame(7, Money::parse('0.07'));

        // Similar trap: 0.29 * 100 evaluates to 28.999...
        $this->assertSame(29, Money::parse('0.29'));
    }

    #[Test]
    public function format_and_parse_round_trip_for_typical_values(): void
    {
        $values = [
            0,
            99,
            100,
            15000000,
            7_000_000_000,
        ];

        foreach ($values as $original) {
            $formatted = Money::format($original);
            $parsed = Money::parse($formatted);

            $this->assertSame(
                $original,
                $parsed,
                "Round-trip failed for {$original}: formatted as '{$formatted}', parsed back as {$parsed}"
            );
        }
    }
}