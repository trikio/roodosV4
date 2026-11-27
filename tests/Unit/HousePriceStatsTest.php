<?php

namespace Tests\Unit;

use App\Support\HousePriceStats;
use PHPUnit\Framework\TestCase;

class HousePriceStatsTest extends TestCase
{
    public function test_medians_ignore_absurd_prices_and_sizes(): void
    {
        $rows = [];
        foreach (range(1, 11) as $i) {
            $rows[] = ['price' => 100000 + $i * 10000, 'size' => 100];
        }
        $rows[] = ['price' => 3, 'size' => 100];            // precio basura
        $rows[] = ['price' => 150000, 'size' => 720000];    // hectáreas escritas como m²
        $rows[] = ['price' => 0, 'size' => 80];             // sin precio

        $stats = HousePriceStats::from($rows);

        $this->assertSame(12, $stats['total']);
        $this->assertSame(155000, $stats['price']);
        $this->assertSame(100, $stats['size']);
        $this->assertSame(['median' => 1600, 'low' => 1350, 'high' => 1850], $stats['per_m2']);
    }

    public function test_too_few_ads_say_nothing(): void
    {
        $this->assertNull(HousePriceStats::from(array_fill(0, 9, ['price' => 100, 'size' => 50])));
    }

    public function test_verdict_skips_prices_that_look_like_another_currency(): void
    {
        $this->assertSame('below', HousePriceStats::verdict(800, 1000));
        $this->assertSame('average', HousePriceStats::verdict(1050, 1000));
        $this->assertSame('above', HousePriceStats::verdict(1200, 1000));
        $this->assertNull(HousePriceStats::verdict(3500, 1000));
        $this->assertNull(HousePriceStats::verdict(200, 1000));
    }
}
