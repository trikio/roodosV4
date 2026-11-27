<?php

namespace App\Services;

use App\Support\HouseLandings;

/**
 * Internal links between the house landings created from the inventory, as Google reads a site:
 * from a city up to its region and the country, across to the other operation and the other types
 * in the same place, and down (or sideways) to the cities of the region. Only landings with an
 * exact search are linked; their ids follow the number of ads, so "first" means "biggest".
 */
class HouseLandingLinks
{
    private const MAX_PLACE_TYPES = 6;
    private const MAX_PLACES = 12;

    public function __construct(private ManticoreSearchService $manticore)
    {
    }

    /**
     * Links for a landing (or an ad) of this operation, type and place, without $excludeId.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function forSearch(string $country, int $operation, int $type, int $state, int $city, int $excludeId = 0): array
    {
        if ($operation <= 0 || $type <= 0) {
            return [];
        }
        $same = [['id_house_operation', '=', $operation], ['id_house_type', '=', $type]];
        $countryLevel = [['id_state', '=', 0], ['id_city', '=', 0]];

        if ($city > 0) {
            $place = [['id_city', '=', $city]];
            $kin = $state > 0 ? $this->generated($country, [...$same, ['id_state', '=', $state]], 60) : [];
            $parents = array_merge(
                array_filter($kin, fn ($row) => (int) $row['id_city'] === 0),
                $this->generated($country, [...$same, ...$countryLevel], 1)
            );
            $places = array_filter($kin, fn ($row) => (int) $row['id_city'] > 0);
        } elseif ($state > 0) {
            $place = [['id_state', '=', $state], ['id_city', '=', 0]];
            $parents = $this->generated($country, [...$same, ...$countryLevel], 1);
            $places = $this->generated($country, [...$same, ['id_state', '=', $state], ['id_city', '>', 0]], self::MAX_PLACES + 1);
        } else {
            $place = $countryLevel;
            $parents = [];
            $places = $this->generated($country, [...$same, ['id_state', '>', 0], ['id_city', '=', 0]], self::MAX_PLACES + 1);
        }
        $placeTypes = $this->generated($country, $place, 30);

        $links = [];
        $add = function (array $rows, int $max) use (&$links, $excludeId) {
            $added = 0;
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ($id === $excludeId || isset($links[$id]) || $added >= $max) {
                    continue;
                }
                $links[$id] = ['title' => (string) $row['title'], 'url' => url('/' . $row['slug'])];
                $added++;
            }
        };
        $add($parents, 2);
        $add($placeTypes, self::MAX_PLACE_TYPES);
        $add($places, self::MAX_PLACES);

        return array_values($links);
    }

    /**
     * The landing of an ad's search: its city's, or else its region's.
     *
     * @return array{title: string, url: string, id: int}|null
     */
    public function landingFor(string $country, int $operation, int $type, int $state, int $city): ?array
    {
        if ($operation <= 0 || $type <= 0) {
            return null;
        }
        $same = [['id_house_operation', '=', $operation], ['id_house_type', '=', $type]];
        $rows = $city > 0 ? $this->generated($country, [...$same, ['id_city', '=', $city]], 1) : [];
        if ($rows === [] && $state > 0) {
            $rows = $this->generated($country, [...$same, ['id_state', '=', $state], ['id_city', '=', 0]], 1);
        }
        $row = $rows[0] ?? null;

        return $row ? ['title' => (string) $row['title'], 'url' => url('/' . $row['slug']), 'id' => (int) $row['id']] : null;
    }

    /**
     * The biggest landings of the country: every operation and type, then their regions.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function popular(string $country, int $limit = 24): array
    {
        $rows = $this->generated($country, [['id_city', '=', 0]], 300);
        usort($rows, fn ($a, $b) => [(int) $a['id_state'] > 0, (int) $a['id']] <=> [(int) $b['id_state'] > 0, (int) $b['id']]);

        return array_map(
            fn ($row) => ['title' => (string) $row['title'], 'url' => url('/' . $row['slug'])],
            array_slice($rows, 0, $limit)
        );
    }

    private function generated(string $country, array $where, int $limit): array
    {
        // Legacy landings carry ids too, but list a text search: only exact ones are linked.
        $rows = $this->manticore->houseLandings($country, $where, $limit * 3);

        return array_slice(array_values(array_filter(
            $rows,
            fn ($row) => HouseLandings::generatedFilters($row['content'] ?? null) !== null
        )), 0, $limit);
    }
}
