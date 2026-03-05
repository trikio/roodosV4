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

    public function __construct()
    {
        $this->connect();
    }

    protected function connect()
    {
        try {
            $this->connection = new Connection();
            $this->connection->setParams([
                'host' => env('MANTICORE_HOST', '127.0.0.1'),
                'port' => env('MANTICORE_PORT', 9306)
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

            $columns = ['title', 'details', 'make_name', 'model_name', 'city_name', 'state_name', 'year_string', 'fuel_name', 'transmission_name', 'body_name'];

            // Main results query
            $query = (new SphinxQL($this->connection))
                ->select('*')
                ->from($searchIndex);

            if (!empty($searchQuery)) {
                $query->match($columns, SphinxQL::expr('"' . $searchQuery . '"/1'));
            }

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

    public function houseSearch($searchIndex, $options)
    {
        try {
            $page = $options['page'] ?? 1;
            $searchQuery = $options['q'] ?? '';

            $columns = ['title', 'details', 'house_operation_name', 'house_type_name', 'city_name', 'state_name'];

            $query = (new SphinxQL($this->connection))
                ->select('*')
                ->from($searchIndex);

            if (!empty($searchQuery)) {
                $query->match($columns, SphinxQL::expr('"' . $searchQuery . '"/1'));
            }

            $query = $this->applyHouseFilters($query, $options);
            $query->limit(((int)$page - 1) * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE);

            $query = $this->applyHouseOrdering($query, $options['order'] ?? '');
            $query->option('max_matches', 500000);
            $query->option('cutoff', 0);

            $query = $query->enqueue((new Helper($this->connection))->showMeta());
            $query = $query->enqueue();

            $query = $this->enqueueHouseFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_house_operation', 'house_operation_name', 'name');
            $query = $this->enqueueHouseFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_house_type', 'house_type_name', 'name');
            $query = $this->enqueueHouseFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_state', 'state_name', 'name');
            $query = $this->enqueueHouseFacet($query, $searchIndex, $columns, $searchQuery, $options, 'id_city', 'city_name', 'name');

            $sphinxRes = $query->executeBatch();

            return [
                'items' => $sphinxRes->getNext()->fetchAllAssoc(),
                'info' => $sphinxRes->getNext()->fetchAllAssoc(),
                'operation' => $sphinxRes->getNext()->fetchAllAssoc(),
                'type' => $sphinxRes->getNext()->fetchAllAssoc(),
                'state' => $sphinxRes->getNext()->fetchAllAssoc(),
                'city' => $sphinxRes->getNext()->fetchAllAssoc(),
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

        if (!empty($searchQuery)) {
            $query->match($columns, SphinxQL::expr('"' . $searchQuery . '"/1'));
        }

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

        if (!empty($searchQuery)) {
            $query->match($columns, SphinxQL::expr('"' . $searchQuery . '"/1'));
        }

        $query = $this->applyHouseFilters($query, $options);
        $query->groupBy($idField)
            ->orderBy('total', 'DESC')
            ->limit(0, 50);

        return $query->enqueue();
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
