<?php

namespace App\Support;

/**
 * Price by km of a model's ads, coloured by year range, with the typical price of each range as a
 * line. Drawn as an SVG on the server (resources/views/cars/_price_chart.blade.php): this class
 * only turns the ads into coordinates.
 *
 * The same car is often listed on the dealer's site, MercadoLibre and autosusados: identical year,
 * km and price count once. At most three year ranges, so the colours stay distinguishable.
 */
final class CarPriceChart
{
    public const MIN_POINTS = 15;
    public const MAX_DOTS = 400;
    public const MAX_GROUPS = 3;

    /** Drawn twice, so the labels keep a readable size on a phone and on a wide screen. */
    public const LAYOUTS = [
        'narrow' => ['width' => 360, 'height' => 260, 'left' => 58, 'right' => 10, 'top' => 10, 'bottom' => 30],
        'wide' => ['width' => 760, 'height' => 340, 'left' => 72, 'right' => 16, 'top' => 12, 'bottom' => 36],
    ];

    /**
     * @param array<int, array{id?: int, price: int, km: int, year: int}> $points
     * @param array{id?: int, price: int, km: int, year: int}|null $highlight the car the page is about
     */
    public static function build(array $points, ?array $highlight = null, string $currency = '$'): ?array
    {
        $highlightId = (int) ($highlight['id'] ?? 0);
        $clean = [];
        foreach ($points as $point) {
            $price = (int) ($point['price'] ?? 0);
            $km = (int) ($point['km'] ?? 0);
            $year = (int) ($point['year'] ?? 0);
            if ($price <= 0 || $km < 100 || $km > 600000 || $year < 1950) {
                continue;
            }
            if ($highlightId > 0 && (int) ($point['id'] ?? 0) === $highlightId) {
                continue;
            }
            $clean["{$year}|{$km}|{$price}"] ??= ['price' => $price, 'km' => $km, 'year' => $year];
        }
        $clean = array_values($clean);
        if (count($clean) < self::MIN_POINTS) {
            return null;
        }

        // Prices in another currency (UF in Chile) or junk: far from the model's median.
        $median = self::median(array_column($clean, 'price'));
        $clean = array_values(array_filter($clean, fn ($p) => $p['price'] >= $median / 5 && $p['price'] <= $median * 5));

        // Very high km is a handful of ads stretching the axis for everyone else.
        $kms = array_column($clean, 'km');
        sort($kms);
        $kmCap = 1.5 * ($kms[(int) floor((count($kms) - 1) * 0.95)] ?? 0);
        $clean = array_values(array_filter($clean, fn ($p) => $p['km'] <= $kmCap));

        $groups = [];
        foreach (self::yearRanges(array_column($clean, 'year')) as $slot => [$from, $to]) {
            $members = array_values(array_filter($clean, fn ($p) => $p['year'] >= $from && $p['year'] <= $to));
            $members = self::withoutOutliers($members);
            if ($members === []) {
                continue;
            }
            $groups[] = [
                'label' => $from === $to ? (string) $from : "{$from}–{$to}",
                'from' => $from,
                'to' => $to,
                'slot' => $slot + 1,
                'points' => $members,
                'count' => count($members),
                'median' => self::median(array_column($members, 'price')),
            ];
        }

        $total = array_sum(array_column($groups, 'count'));
        if ($total < self::MIN_POINTS) {
            return null;
        }

        $all = array_merge(...array_column($groups, 'points'));
        $prices = array_column($all, 'price');
        $kmMax = max(array_column($all, 'km'));
        if ($highlight && (int) $highlight['price'] > 0 && (int) $highlight['km'] > 0) {
            $prices[] = (int) $highlight['price'];
            $kmMax = max($kmMax, (int) $highlight['km']);
        } else {
            $highlight = null;
        }

        // The axis ends just past the farthest ad: one car at 340 mil km left 400 mil drawn empty.
        $xMax = $kmMax * 1.03;
        $xTicks = array_values(array_filter(self::ticks(0, $kmMax), fn ($v) => $v <= $xMax));
        $yTicks = self::ticks(min($prices), max($prices));
        $title = fn (array $p) => $p['year'] . ' · ' . number_format($p['km'], 0, ',', '.') . ' km · '
            . $currency . ' ' . number_format($p['price'], 0, ',', '.');

        // Few enough dots to keep the page light; the lines are drawn from every ad.
        $step = max(1, $total / self::MAX_DOTS);
        foreach ($groups as &$group) {
            $group['trend'] = self::trend($group['points']);
            $group['shown'] = array_values(array_filter($group['points'], fn ($i) => fmod($i, $step) < 1, ARRAY_FILTER_USE_KEY));
        }
        unset($group);

        $layouts = [];
        foreach (self::LAYOUTS as $name => $box) {
            $layouts[$name] = self::layout($box, $groups, $xMax, $xTicks, $yTicks, $highlight, $title, $currency);
        }

        return [
            'groups' => array_map(fn ($g) => array_intersect_key($g, array_flip(['label', 'from', 'to', 'slot', 'count', 'median'])), $groups),
            'layouts' => $layouts,
            'total' => $total,
        ];
    }

    private static function layout(array $box, array $groups, float $xMax, array $xTicks, array $yTicks, ?array $highlight, callable $title, string $currency): array
    {
        [$yMin, $yMax] = [$yTicks[0], end($yTicks)];
        $plotW = $box['width'] - $box['left'] - $box['right'];
        $plotH = $box['height'] - $box['top'] - $box['bottom'];
        $x = fn (float $km) => round($box['left'] + $km / $xMax * $plotW, 1);
        $y = fn (float $price) => round($box['top'] + (1 - ($price - $yMin) / max(1, $yMax - $yMin)) * $plotH, 1);

        return [
            'width' => $box['width'],
            'height' => $box['height'],
            'plot' => ['left' => $box['left'], 'right' => $box['width'] - $box['right'], 'top' => $box['top'], 'bottom' => $box['height'] - $box['bottom']],
            'x_ticks' => array_map(fn ($v) => ['x' => $x($v), 'label' => self::short($v)], $xTicks),
            'y_ticks' => array_map(fn ($v) => ['y' => $y($v), 'label' => $currency . ' ' . self::short($v)], $yTicks),
            'series' => array_map(fn ($g) => [
                'slot' => $g['slot'],
                'dots' => array_map(fn ($p) => ['x' => $x($p['km']), 'y' => $y($p['price']), 'title' => $title($p)], $g['shown']),
                'trend' => count($g['trend']) >= 3
                    ? 'M' . implode(' L', array_map(fn ($t) => $x($t[0]) . ' ' . $y($t[1]), $g['trend']))
                    : null,
            ], $groups),
            'highlight' => $highlight ? ['x' => $x($highlight['km']), 'y' => $y($highlight['price']), 'title' => $title($highlight)] : null,
        ];
    }

    /**
     * Up to three contiguous year ranges with about the same number of ads each.
     *
     * @param int[] $years
     * @return array<int, array{0: int, 1: int}>
     */
    public static function yearRanges(array $years): array
    {
        $counts = array_count_values($years);
        ksort($counts);
        $distinct = array_keys($counts);
        if (count($distinct) <= self::MAX_GROUPS) {
            return array_map(fn ($year) => [$year, $year], $distinct);
        }

        $ranges = [];
        $start = null;
        $seen = 0;
        $total = count($years);
        foreach ($distinct as $i => $year) {
            $start ??= $year;
            $seen += $counts[$year];
            $groupsLeft = self::MAX_GROUPS - count($ranges) - 1;
            $yearsLeft = count($distinct) - $i - 1;
            $reached = $seen >= $total * (count($ranges) + 1) / self::MAX_GROUPS;
            if ($groupsLeft > 0 && $yearsLeft >= $groupsLeft && ($reached || $yearsLeft === $groupsLeft)) {
                $ranges[] = [$start, $year];
                $start = null;
            }
        }
        if ($start !== null) {
            $ranges[] = [$start, end($distinct)];
        }

        return $ranges;
    }

    /** Typical price along the km axis: medians of consecutive groups of ads sorted by km. */
    private static function trend(array $points): array
    {
        $n = count($points);
        // At least 12 ads per stretch: with 5 the line of a range with few ads zigzagged.
        $bins = min(7, intdiv($n, 12));
        if ($bins < 3) {
            return [];
        }
        usort($points, fn ($a, $b) => $a['km'] <=> $b['km']);
        $trend = [];
        for ($b = 0; $b < $bins; $b++) {
            $slice = array_slice($points, intdiv($b * $n, $bins), intdiv(($b + 1) * $n, $bins) - intdiv($b * $n, $bins));
            $trend[] = [self::median(array_column($slice, 'km')), self::median(array_column($slice, 'price'))];
        }

        return $trend;
    }

    private static function withoutOutliers(array $points): array
    {
        if (count($points) < 8) {
            return $points;
        }
        $prices = array_column($points, 'price');
        sort($prices);
        $q1 = $prices[(int) floor((count($prices) - 1) * 0.25)];
        $q3 = $prices[(int) floor((count($prices) - 1) * 0.75)];
        $fence = 2 * ($q3 - $q1);

        return array_values(array_filter($points, fn ($p) => $p['price'] >= $q1 - $fence && $p['price'] <= $q3 + $fence));
    }

    /** @return float[] about five round values covering [$min, $max] */
    private static function ticks(float $min, float $max): array
    {
        $range = max(1, $max - $min);
        $raw = $range / 4;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * (collect([1, 2, 2.5, 5, 10])->first(fn ($f) => $f * $magnitude >= $raw) ?? 10);
        $from = floor($min / $step) * $step;
        $ticks = [];
        for ($v = $from; $v < $max + $step - 1e-9; $v += $step) {
            $ticks[] = $v;
        }

        return count($ticks) >= 2 ? $ticks : [$from, $from + $step];
    }

    /** 12500000 → "12,5 M", 85000 → "85 mil". */
    public static function short(float $value): string
    {
        $format = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');

        return match (true) {
            $value >= 1_000_000 => $format($value / 1_000_000) . ' M',
            $value >= 1_000 => $format($value / 1_000) . ' mil',
            default => $format($value),
        };
    }

    private static function median(array $values): int
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0;
        }

        return (int) round($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
