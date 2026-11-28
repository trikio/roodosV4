<?php

namespace Tests\Unit;

use App\Support\CarPriceChart;
use PHPUnit\Framework\TestCase;

class CarPriceChartTest extends TestCase
{
    private function ads(int $count, int $year, int $basePrice): array
    {
        $ads = [];
        foreach (range(1, $count) as $i) {
            $ads[] = ['id' => $year * 1000 + $i, 'price' => $basePrice - $i * 50000, 'km' => $i * 7000, 'year' => $year];
        }

        return $ads;
    }

    public function test_groups_years_into_at_most_three_ranges(): void
    {
        $this->assertSame([[2018, 2018], [2019, 2019]], CarPriceChart::yearRanges([2018, 2019, 2019]));

        $years = array_merge(array_fill(0, 10, 2012), array_fill(0, 10, 2015), array_fill(0, 10, 2018),
            array_fill(0, 10, 2020), array_fill(0, 10, 2022), array_fill(0, 10, 2024));
        $this->assertSame([[2012, 2015], [2018, 2020], [2022, 2024]], CarPriceChart::yearRanges($years));
    }

    public function test_builds_groups_trends_and_highlight(): void
    {
        $ads = array_merge($this->ads(40, 2016, 9000000), $this->ads(40, 2020, 14000000));
        $chart = CarPriceChart::build($ads, ['id' => 99, 'price' => 10000000, 'km' => 50000, 'year' => 2018]);

        $this->assertSame(80, $chart['total']);
        $this->assertSame(['2016', '2020'], array_column($chart['groups'], 'label'));
        foreach ($chart['layouts'] as $layout) {
            $this->assertNotNull($layout['series'][0]['trend']);
            $this->assertNotNull($layout['highlight']);
            foreach ($layout['series'] as $series) {
                foreach ($series['dots'] as $dot) {
                    $this->assertGreaterThanOrEqual($layout['plot']['left'], $dot['x']);
                    $this->assertLessThanOrEqual($layout['plot']['right'], $dot['x']);
                    $this->assertGreaterThanOrEqual($layout['plot']['top'], $dot['y']);
                    $this->assertLessThanOrEqual($layout['plot']['bottom'], $dot['y']);
                }
            }
        }
    }

    public function test_cross_posted_ads_and_junk_prices_count_once_or_not_at_all(): void
    {
        $ads = $this->ads(20, 2019, 12000000);
        $copies = array_map(fn ($ad) => ['id' => $ad['id'] + 500] + $ad, array_slice($ads, 0, 5));
        $junk = [['id' => 1, 'price' => 350, 'km' => 40000, 'year' => 2019]]; // UF, no pesos
        $chart = CarPriceChart::build(array_merge($ads, $copies, $junk));

        $this->assertSame(20, $chart['total']);
    }

    public function test_too_few_ads_draw_nothing(): void
    {
        $this->assertNull(CarPriceChart::build($this->ads(10, 2019, 9000000)));
    }

    public function test_short_labels(): void
    {
        $this->assertSame('12,5 M', CarPriceChart::short(12500000));
        $this->assertSame('10 M', CarPriceChart::short(10000000));
        $this->assertSame('85 mil', CarPriceChart::short(85000));
        $this->assertSame('0', CarPriceChart::short(0));
    }
}
