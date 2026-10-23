<?php

namespace App\Services;

use Foolz\SphinxQL\SphinxQL;
use Foolz\SphinxQL\Helper;
use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Exception;

class ManticoreSearchService
{
    protected $connection;
    const RESULTS_PER_PAGE = 24;
    const CAR_MATCH_COLUMNS = ['title', 'details', 'make_name', 'model_name', 'city_name', 'state_name', 'year_string', 'fuel_name', 'transmission_name', 'body_name'];
    const LANDING_FILTER_ATTRIBUTES = ['make' => 'id_car_make', 'model' => 'id_car_model', 'state' => 'id_state', 'city' => 'id_city'];

    public function __construct()
    {
        $this->connect();
    }

    protected function connect()
    {
        try {
            $this->connection = new Connection();
            $this->connection->setParams([
                'host' => config('services.manticore.host'),
                'port' => config('services.manticore.port'),
            ]);
        } catch (Exception $e) {
            throw new Exception("Error connecting to ManticoreSearch: " . $e->getMessage());
        }
    }

    public function listIndices()
    {
        try {
            $query = (new SphinxQL($this->connection))->query('SHOW TABLES');
            $result = $query->execute();
            return $result;
        } catch (Exception $e) {
            throw new Exception("Error listing indices: " . $e->getMessage());
        }
    }

    public function carSearch($searchIndex, $options)
    {
        try {
            $page = $options['page'] ?? 1;
            $searchQuery = $options['q'] ?? '';

            $columns = self::CAR_MATCH_COLUMNS;

            // Main results query
            $query = (new SphinxQL($this->connection))
                ->select('*')
                ->from($searchIndex);

            $this->applyQuorumMatch($query, $columns, $searchQuery);

            $query = $this->applyCarFilters($query, $options);
            $query->limit(((int)$page - 1) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE);

            // Apply ordering
            $query = $this->applyOrdering($query, $options['order'] ?? '');
            $query->option('max_matches', 500000);
            $query->option('cutoff', 0);

            // Enqueue main query and meta
            $query = $query->enqueue((new Helper($this->connection))->showMeta());
            $query = $query->enqueue();

            // Facets queries
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_car_make', 'make_name', 'name');
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_car_model', 'model_name', 'name');
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_state', 'state_name', 'name');
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_city', 'city_name', 'name');
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_fuel', 'fuel_name', 'name');
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_transmission', 'transmission_name', 'name');
            $query = $this->enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_body', 'body_name', 'name');

            // Execute batch
            $sphinxRes = $query->executeBatch();

            return [
                'items' => $sphinxRes->getNext()->fetchAllAssoc(),
                'info' => $sphinxRes->getNext()->fetchAllAssoc(),
                'make' => $sphinxRes->getNext()->fetchAllAssoc(),
                'model' => $sphinxRes->getNext()->fetchAllAssoc(),
                'state' => $sphinxRes->getNext()->fetchAllAssoc(),
                'city' => $sphinxRes->getNext()->fetchAllAssoc(),
                'fuel' => $sphinxRes->getNext()->fetchAllAssoc(),
                'transmission' => $sphinxRes->getNext()->fetchAllAssoc(),
                'body' => $sphinxRes->getNext()->fetchAllAssoc(),
            ];
        } catch (Exception $e) {
            throw new Exception("Error searching cars: " . $e->getMessage());
        }
    }

    /**
     * Total of cars matching $options, without fetching rows or facets.
     * With $allTerms every word of $options['q'] must match, instead of at least one.
     */
    public function countCars($searchIndex, array $options, bool $allTerms = false): int
    {
        try {
            $query = (new SphinxQL($this->connection))
                ->select(SphinxQL::expr('COUNT(*) AS total'))
                ->from($searchIndex);

            $searchQuery = trim((string) ($options['q'] ?? ''));
            if ($allTerms && $searchQuery !== '') {
                $query->match(self::CAR_MATCH_COLUMNS, $searchQuery);
            } else {
                $this->applyQuorumMatch($query, self::CAR_MATCH_COLUMNS, $searchQuery);
            }
            $this->applyCarFilters($query, $options);
            $query->option('cutoff', 0);

            $result = $query->execute()->fetchAllAssoc();

            return (int) ($result[0]['total'] ?? 0);
        } catch (Exception $e) {
            throw new Exception("Error counting cars: " . $e->getMessage());
        }
    }

    public function getCarById($searchIndex, int $id): ?array
    {
        try {
            $result = (new SphinxQL($this->connection))
                ->select('*')
                ->from($searchIndex)
                ->where('id', $id)
                ->limit(0, 1)
                ->execute();

            if (empty($result)) {
                return null;
            }

            return $result[0];
        } catch (Exception $e) {
            throw new Exception("Error fetching car by id: " . $e->getMessage());
        }
    }

    public function getCarMakeBySlug(string $slug): ?array
    {
        try {
            $result = (new SphinxQL($this->connection))
                ->select('*')
                ->from('car_make')
                ->where('slug', $slug)
                ->limit(0, 1)
                ->execute();

            if (empty($result)) {
                return null;
            }

            return $result[0];
        } catch (Exception $e) {
            return null;
        }
    }

    public function getCarModelBySlug(string $slug): ?array
    {
        try {
            $result = (new SphinxQL($this->connection))
                ->select('*')
                ->from('car_model')
                ->where('slug', $slug)
                ->limit(0, 1)
                ->execute();

            if (empty($result)) {
                return null;
            }

            return $result[0];
        } catch (Exception $e) {
            return null;
        }
    }

    public function houseSearch($searchIndex, $options)
    {
        $attempts = 0;
        $maxAttempts = 3;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                return $this->runHouseSearch($searchIndex, $options);
            } catch (Exception $e) {
                $lastException = $e;
                $attempts++;

                if (!str_contains($e->getMessage(), '[2006]') || $attempts >= $maxAttempts) {
                    throw $e;
                }

                $this->connect();
                usleep(120000);
            }
        }

        throw $lastException ?? new Exception('Unknown houses search error');
    }

    protected function runHouseSearch($searchIndex, $options)
    {
        try {
            $page = $options['page'] ?? 1;
            $searchQuery = $options['q'] ?? '';

            $columns = ['title', 'details', 'house_operation_name', 'house_type_name', 'city_name', 'state_name'];

            $query = (new SphinxQL($this->connection))
                ->select('*')
                ->from($searchIndex);

            $this->applyQuorumMatch($query, $columns, $searchQuery);

            $query = $this->applyHouseFilters($query, $options);
            $query->limit(((int)$page - 1) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE);

            $query = $this->applyHouseOrdering($query, $options['order'] ?? '');
            $query->option('max_matches', 500000);
            $query->option('cutoff', 0);

            // Houses queries are executed separately to avoid multi-query disconnects.
            $itemsResult = $query->execute();
            $metaResult = (new Helper($this->connection))->showMeta()->execute();

            $operationResult = $this->fetchHouseFacet($searchIndex, $columns, $searchQuery, $options, 'id_house_operation', 'house_operation_name', 'name');
            $typeResult = $this->fetchHouseFacet($searchIndex, $columns, $searchQuery, $options, 'id_house_type', 'house_type_name', 'name');
            $stateResult = $this->fetchHouseFacet($searchIndex, $columns, $searchQuery, $options, 'id_state', 'state_name', 'name');
            $cityResult = $this->fetchHouseFacet($searchIndex, $columns, $searchQuery, $options, 'id_city', 'city_name', 'name');

            return [
                'items' => is_iterable($itemsResult) ? iterator_to_array($itemsResult) : (array) $itemsResult,
                'info' => is_iterable($metaResult) ? iterator_to_array($metaResult) : (array) $metaResult,
                'operation' => $operationResult,
                'type' => $typeResult,
                'state' => $stateResult,
                'city' => $cityResult,
            ];
        } catch (Exception $e) {
            throw new Exception("Error searching houses: " . $e->getMessage());
        }
    }

    public function getHouseById($searchIndex, int $id): ?array
    {
        try {
            $result = (new SphinxQL($this->connection))
                ->select('*')
                ->from($searchIndex)
                ->where('id', $id)
                ->limit(0, 1)
                ->execute();

            if (empty($result)) {
                return null;
            }

            return $result[0];
        } catch (Exception $e) {
            throw new Exception("Error fetching house by id: " . $e->getMessage());
        }
    }

    public function getLandingBySlug(string $countryCode, string $slug): ?array
    {
        $indexName = 'car_landings_' . $countryCode;

        try {
            $result = (new SphinxQL($this->connection))
                ->select('*')
                ->from($indexName)
                ->where('slug', $slug)
                ->limit(0, 1)
                ->execute();

            if (empty($result)) {
                return null;
            }

            return $result[0];
        } catch (Exception $e) {
            // If index does not exist or query fails, treat it as "not found" for landing routing.
            return null;
        }
    }

    /**
     * Make and model slugs (shared by all countries) and the city/state names with ads in one country.
     *
     * @return array{makes: string[], models: string[], places: string[]}
     */
    public function carVocabulary(string $countryCode): array
    {
        $column = fn (string $sql, string $field) => array_column(
            (new SphinxQL($this->connection))->query($sql)->execute()->fetchAllAssoc(),
            $field
        );

        $places = [];
        foreach (['city_name', 'state_name'] as $field) {
            $places = array_merge($places, $column("SELECT {$field} FROM car_{$countryCode} GROUP BY {$field} LIMIT 20000 OPTION max_matches=20000", $field));
        }

        return [
            'makes' => $column('SELECT slug FROM car_make LIMIT 10000 OPTION max_matches=10000', 'slug'),
            'models' => $column('SELECT slug FROM car_model LIMIT 200000 OPTION max_matches=200000', 'slug'),
            'places' => array_values(array_filter($places)),
        ];
    }

    /**
     * Which of $slugs are active, non-redirecting car landings of the country.
     */
    public function existingCarLandingSlugs(string $countryCode, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        try {
            $rows = (new SphinxQL($this->connection))
                ->select('slug')
                ->from('car_landings_' . $countryCode)
                ->where('slug', 'IN', array_values($slugs))
                ->where('redirect', '')
                ->limit(0, count($slugs))
                ->execute()
                ->fetchAllAssoc();

            return array_column($rows, 'slug');
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Every car landing of a country, in id order, fetched in batches to keep memory flat.
     */
    public function iterateCarLandings(string $countryCode, int $batchSize = 20000): \Generator
    {
        $lastId = 0;

        do {
            $rows = (new SphinxQL($this->connection))
                ->select('id', 'slug', 'title', 'content', 'redirect', 'id_car_make', 'id_car_model')
                ->from('car_landings_' . $countryCode)
                ->where('id', '>', $lastId)
                ->orderBy('id', 'ASC')
                ->limit(0, $batchSize)
                ->option('max_matches', $batchSize)
                ->execute()
                ->fetchAllAssoc();

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield $row;
            }
        } while (count($rows) === $batchSize);
    }

    public function getHouseLandingBySlug(string $countryCode, string $slug): ?array
    {
        $indexName = 'house_landings_' . $countryCode;

        try {
            $result = (new SphinxQL($this->connection))
                ->select('*')
                ->from($indexName)
                ->where('slug', $slug)
                ->limit(0, 1)
                ->execute();

            if (empty($result)) {
                return null;
            }

            return $result[0];
        } catch (Exception $e) {
            return null;
        }
    }

    protected function enqueueFacet($query, $searchIndex, $columns, $searchQuery, $options, $idField, $nameField, $alias)
    {
        $query->select($idField . ', ' . $nameField . ' as ' . $alias . ', COUNT(*) as total')
            ->from($searchIndex);

        $this->applyQuorumMatch($query, $columns, $searchQuery);

        $query = $this->applyCarFilters($query, $options);
        $query->groupBy($idField)
            ->orderBy('total', 'DESC')
            ->limit(0, 50);

        return $query->enqueue();
    }

    protected function enqueueHouseFacet($query, $searchIndex, $columns, $searchQuery, $options, $idField, $nameField, $alias)
    {
        $query->select($idField . ', ' . $nameField . ' as ' . $alias . ', COUNT(*) as total')
            ->from($searchIndex);

        $this->applyQuorumMatch($query, $columns, $searchQuery);

        $query = $this->applyHouseFilters($query, $options);
        $query->groupBy($idField)
            ->orderBy('total', 'DESC')
            ->limit(0, 50);

        return $query->enqueue();
    }

    protected function fetchHouseFacet($searchIndex, $columns, $searchQuery, $options, $idField, $nameField, $alias): array
    {
        $query = (new SphinxQL($this->connection))
            ->select($idField . ', ' . $nameField . ' as ' . $alias . ', COUNT(*) as total')
            ->from($searchIndex);

        $this->applyQuorumMatch($query, $columns, $searchQuery);

        $query = $this->applyHouseFilters($query, $options);
        $query->groupBy($idField)
            ->orderBy('total', 'DESC')
            ->limit(0, 50);

        $result = $query->execute();

        return is_iterable($result) ? iterator_to_array($result) : (array) $result;
    }

    /**
     * Matches documents containing at least one of the words of $searchQuery
     * (quorum operator). User input is escaped so characters like " or @
     * cannot break or alter the full-text expression.
     */
    protected function applyQuorumMatch(SphinxQL $query, array $columns, ?string $searchQuery): SphinxQL
    {
        $searchQuery = trim((string) $searchQuery);
        if ($searchQuery === '') {
            return $query;
        }

        return $query->match($columns, SphinxQL::expr('"' . $query->escapeMatch($searchQuery) . '"/1'));
    }

    protected function applyCarFilters($query, $options)
    {
        if (!empty($options['make'])) {
            $query->where('id_car_make', (int)$options['make']);
        }
        if (!empty($options['model'])) {
            $query->where('id_car_model', (int)$options['model']);
        }
        if (!empty($options['state'])) {
            $query->where('id_state', (int)$options['state']);
        }
        if (!empty($options['city'])) {
            $query->where('id_city', (int)$options['city']);
        }
        if (!empty($options['fuel'])) {
            $query->where('id_fuel', (int)$options['fuel']);
        }
        if (!empty($options['transmission'])) {
            $query->where('id_transmission', (int)$options['transmission']);
        }
        if (!empty($options['body'])) {
            $query->where('id_body', (int)$options['body']);
        }

        // Price ranges
        if (!empty($options['min_price']) && !empty($options['max_price'])) {
            $query->where('price', 'BETWEEN', [(int)$options['min_price'], (int)$options['max_price']]);
        } elseif (!empty($options['min_price'])) {
            $query->where('price', '>=', (int)$options['min_price']);
        } elseif (!empty($options['max_price'])) {
            $query->where('price', '<=', (int)$options['max_price']);
        }

        // Year ranges
        if (!empty($options['min_year']) && !empty($options['max_year'])) {
            $query->where('year_int', 'BETWEEN', [(int)$options['min_year'], (int)$options['max_year']]);
        } elseif (!empty($options['min_year'])) {
            $query->where('year_int', '>=', (int)$options['min_year']);
        } elseif (!empty($options['max_year'])) {
            $query->where('year_int', '<=', (int)$options['max_year']);
        }

        // Km ranges
        if (!empty($options['min_km']) && !empty($options['max_km'])) {
            $query->where('km', 'BETWEEN', [(int)$options['min_km'], (int)$options['max_km']]);
        } elseif (!empty($options['min_km'])) {
            $query->where('km', '>=', (int)$options['min_km']);
        } elseif (!empty($options['max_km'])) {
            $query->where('km', '<=', (int)$options['max_km']);
        }

        return $query;
    }

    protected function applyHouseFilters($query, $options)
    {
        if (!empty($options['operation'])) {
            $query->where('id_house_operation', (int)$options['operation']);
        }
        if (!empty($options['type'])) {
            $query->where('id_house_type', (int)$options['type']);
        }
        if (!empty($options['state'])) {
            $query->where('id_state', (int)$options['state']);
        }
        if (!empty($options['city'])) {
            $query->where('id_city', (int)$options['city']);
        }

        if (!empty($options['min_price']) && !empty($options['max_price'])) {
            $query->where('price', 'BETWEEN', [(int)$options['min_price'], (int)$options['max_price']]);
        } elseif (!empty($options['min_price'])) {
            $query->where('price', '>=', (int)$options['min_price']);
        } elseif (!empty($options['max_price'])) {
            $query->where('price', '<=', (int)$options['max_price']);
        }

        if (!empty($options['rooms'])) {
            $query->where('rooms', '>=', (int)$options['rooms']);
        }
        if (!empty($options['bath'])) {
            $query->where('bath', '>=', (int)$options['bath']);
        }

        if (!empty($options['min_size']) && !empty($options['max_size'])) {
            $query->where('size', 'BETWEEN', [(int)$options['min_size'], (int)$options['max_size']]);
        } elseif (!empty($options['min_size'])) {
            $query->where('size', '>=', (int)$options['min_size']);
        } elseif (!empty($options['max_size'])) {
            $query->where('size', '<=', (int)$options['max_size']);
        }

        return $query;
    }

    protected function applyOrdering($query, $order)
    {
        switch ($order) {
            case 'priceasc':
                $query->orderBy('price', 'ASC');
                break;
            case 'pricedesc':
                $query->orderBy('price', 'DESC');
                break;
            case 'yearasc':
                $query->orderBy('year_int', 'ASC');
                break;
            case 'yeardesc':
                $query->orderBy('year_int', 'DESC');
                break;
            case 'kmasc':
                $query->orderBy('km', 'ASC');
                break;
            case 'kmdesc':
                $query->orderBy('km', 'DESC');
                break;
        }
        return $query;
    }

    protected function applyHouseOrdering($query, $order)
    {
        switch ($order) {
            case 'priceasc':
                $query->orderBy('price', 'ASC');
                break;
            case 'pricedesc':
                $query->orderBy('price', 'DESC');
                break;
            case 'sizeasc':
                $query->orderBy('size', 'ASC');
                break;
            case 'sizedesc':
                $query->orderBy('size', 'DESC');
                break;
        }

        return $query;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}
