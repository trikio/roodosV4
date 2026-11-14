<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Price tables inside guides. A paragraph with only "[[precios:{model-slug}]]" becomes a table of
 * that model's prices per year from the country's index, so the numbers in an article are never
 * frozen at the day it was written.
 */
class GuidePriceTables
{
    private const PATTERN = '#<p>\[\[precios:([a-z0-9]+(?:-[a-z0-9]+)*)\]\]</p>#';

    public function __construct(private ManticoreSearchService $manticore)
    {
    }

    public function render(string $country, string $html): string
    {
        return preg_replace_callback(self::PATTERN, fn ($match) => $this->table($country, $match[1]), $html);
    }

    private function table(string $country, string $modelSlug): string
    {
        $minPrice = config('countries.guide_price_floor.' . strtoupper($country));
        if ($minPrice === null) {
            return '';
        }

        $data = Cache::remember("guide-prices:{$country}:{$modelSlug}", now()->addHours(6), function () use ($country, $modelSlug, $minPrice) {
            $model = $this->manticore->getCarModelBySlug($modelSlug);
            if (!$model || empty($model['id'])) {
                return null;
            }
            $make = $this->manticore->getCarMakeById((int) ($model['id_car_make'] ?? 0));

            return [
                'name' => trim(($make['name'] ?? '') . ' ' . ($model['model_name'] ?? '')),
                'rows' => $this->manticore->carModelPricesByYear('car_' . $country, (int) $model['id'], (int) $minPrice),
            ];
        });

        if ($data === null) {
            return '';
        }

        return view('guides._price_table', [
            'name' => $data['name'],
            'rows' => $data['rows'],
            'modelUrl' => url('/modelo/' . $modelSlug),
            'currency' => config('countries.currency.' . strtoupper($country), '$'),
        ])->render();
    }
}
