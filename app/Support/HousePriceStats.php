<?php

namespace App\Support;

/**
 * Typical price, size and price per m² of the ads comparable to one property.
 *
 * Medians and quartiles, not averages: the sources bring ads with 720.000 m², prices of 3 and
 * prices in another currency, and a handful of them is enough to make an average meaningless.
 */
class HousePriceStats
{
    /** Below this many ads the comparison says nothing and is not shown. */
    public const MIN_ADS = 10;

    /** Built or lot sizes outside this range are typos (or hectares written as m²). */
    private const MIN_SIZE = 15;
    private const MAX_SIZE = 20000;

    /**
     * @param array<int, array{price: int|string, size: int|string}> $rows
     * @return array{total: int, price: int, size: ?int, per_m2: ?array{median: int, low: int, high: int}}|null
     */
    public static function from(array $rows): ?array
    {
        $prices = [];
        $perM2 = [];
        $sizes = [];
        foreach ($rows as $row) {
            $price = (int) ($row['price'] ?? 0);
            $size = (int) ($row['size'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $prices[] = $price;
            if (self::validSize($size)) {
                $sizes[] = $size;
                $perM2[] = $price / $size;
            }
        }

        $prices = self::withoutOutliers($prices);
        if (count($prices) < self::MIN_ADS) {
            return null;
        }

        $perM2 = self::withoutOutliers($perM2);
        $sizes = self::withoutOutliers($sizes);

        return [
            'total' => count($prices),
            'price' => (int) round(self::percentile($prices, 0.5)),
            'size' => count($sizes) >= self::MIN_ADS ? (int) round(self::percentile($sizes, 0.5)) : null,
            'per_m2' => count($perM2) >= self::MIN_ADS ? [
                'median' => (int) round(self::percentile($perM2, 0.5)),
                'low' => (int) round(self::percentile($perM2, 0.25)),
                'high' => (int) round(self::percentile($perM2, 0.75)),
            ] : null,
        ];
    }

    public static function validSize(int $size): bool
    {
        return $size >= self::MIN_SIZE && $size <= self::MAX_SIZE;
    }

    /**
     * 'below', 'average' or 'above' the median, or null when the gap is so large that the ad is
     * more likely in another currency or mistyped than a bargain.
     */
    public static function verdict(float $value, float $median): ?string
    {
        if ($value <= 0 || $median <= 0) {
            return null;
        }

        $ratio = $value / $median;

        return match (true) {
            $ratio < 1 / 3 || $ratio > 3 => null,
            $ratio <= 0.9 => 'below',
            $ratio >= 1.1 => 'above',
            default => 'average',
        };
    }

    /** Drops what is more than ten times away from the median. */
    private static function withoutOutliers(array $values): array
    {
        if ($values === []) {
            return [];
        }
        sort($values);
        $median = self::percentile($values, 0.5);

        return array_values(array_filter($values, fn ($v) => $v >= $median / 10 && $v <= $median * 10));
    }

    /** Linear interpolation between the closest ranks; $values must be sorted. */
    private static function percentile(array $values, float $p): float
    {
        $position = (count($values) - 1) * $p;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower);
    }
}
