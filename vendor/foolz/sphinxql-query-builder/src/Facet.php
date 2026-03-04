<?php

namespace Foolz\SphinxQL;

use Foolz\SphinxQL\Drivers\ConnectionInterface;
use Foolz\SphinxQL\Exception\SphinxQLException;

/**
 * Query Builder class for Facet statements.
 * @author Vicent Valls
 */
class Facet
{
    /**
     * A non-static connection for the current Facet object
     *
     * @var ConnectionInterface
     */
    protected ?ConnectionInterface $connection;

    /**
     * An SQL query that is not yet executed or "compiled"
     *
     * @var string
     */
    protected string $query = '';

    /**
     * Array of select elements that will be comma separated.
     *
     * @var array
     */
    protected array $facet = array();

    /**
     * BY array to be comma separated
     *
     * @var array
     */
    protected string $by = '';

    /**
     * ORDER BY array
     *
     * @var array
     */
    protected array $order_by = array();

    /**
     * When not null it adds an offset
     *
     * @var null|int
     */
    protected ?int $offset = null;

    /**
     * When not null it adds a limit
     *
     * @var null|int
     */
    protected ?int $limit = null;

    /**
     * @param ConnectionInterface|null $connection
     */
    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->connection = $connection;
    }

    /**
     * Returns the currently attached connection
     *
     * @returns ConnectionInterface|null
     */
    public function getConnection(): ?ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Sets the connection to be used
     *
     * @param ConnectionInterface $connection
     *
     * @return Facet
     */
    public function setConnection(?ConnectionInterface $connection = null): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Facet the columns
     *
     * Gets the arguments passed as $facet->facet('one', 'two')
     * Using it with array maps values as column names
     *
     * Examples:
     *    $query->facet('idCategory');
     *    // FACET idCategory
     *
     *    $query->facet('idCategory', 'year');
     *    // FACET idCategory, year
     *
     *    $query->facet(array('categories' => 'idCategory', 'year', 'type' => 'idType'));
     *    // FACET idCategory AS categories, year, idType AS type
     *
     * @param array|string $columns Array or multiple string arguments containing column names
     *
     * @return Facet
     */
    public function facet(array|string|null $columns = null): self
    {
        if ($columns === null) {
            throw new SphinxQLException('facet() requires at least one column or function.');
        }

        if (!is_array($columns)) {
            $columns = \func_get_args();
        }

        if (empty($columns)) {
            throw new SphinxQLException('facet() requires at least one column or function.');
        }

        foreach ($columns as $key => $column) {
            if (is_int($key)) {
                if (is_array($column)) {
                    $this->facet($column);
                } else {
                    if (!is_string($column) || trim($column) === '') {
                        throw new SphinxQLException('facet() columns must be non-empty strings.');
                    }
                    $this->facet[] = array($column, null);
                }
            } else {
                if (!is_string($key) || trim($key) === '' || !is_string($column) || trim($column) === '') {
                    throw new SphinxQLException('facet() aliases and columns must be non-empty strings.');
                }
                $this->facet[] = array($column, $key);
            }
        }

        return $this;
    }

    /**
     * Facet a function
     *
     * Gets the function passed as $facet->facetFunction('FUNCTION', array('param1', 'param2', ...))
     *
     * Examples:
     *    $query->facetFunction('category');
     *
     * @param string       $function Function name
     * @param array|string $params   Array or multiple string arguments containing column names
     *
     * @return Facet
     */
    public function facetFunction(string $function, array|string|null $params = null): self
    {
        if (!is_string($function) || trim($function) === '') {
            throw new SphinxQLException('facetFunction() function name must be a non-empty string.');
        }
        if ($params === null || (is_array($params) && count($params) === 0)) {
            throw new SphinxQLException('facetFunction() requires one or more parameters.');
        }

        if (is_array($params)) {
            $params = implode(',', $params);
        }

        $this->facet[] = new Expression($function.'('.$params.')');

        return $this;
    }

    /**
     * GROUP BY clause
     * Adds to the previously added columns
     *
     * @param string $column A column to group by
     *
     * @return Facet
     */
    public function by(string $column): self
    {
        if (!is_string($column) || trim($column) === '') {
            throw new SphinxQLException('by() column must be a non-empty string.');
        }

        $this->by = $column;

        return $this;
    }

    /**
     * ORDER BY clause
     * Adds to the previously added columns
     *
     * @param string $column    The column to order on
     * @param string $direction The ordering direction (asc/desc)
     *
     * @return Facet
     */
    public function orderBy(string $column, ?string $direction = null): self
    {
        if (!is_string($column) || trim($column) === '') {
            throw new SphinxQLException('orderBy() column must be a non-empty string.');
        }

        $this->order_by[] = array(
            'column' => $column,
            'direction' => $this->normalizeDirection($direction, 'orderBy')
        );

        return $this;
    }

    /**
     * Facet a function
     *
     * Gets the function passed as $facet->facetFunction('FUNCTION', array('param1', 'param2', ...))
     *
     * Examples:
     *    $query->facetFunction('category');
     *
     * @param string $function  Function name
     * @param array  $params    Array  string arguments containing column names
     * @param string $direction The ordering direction (asc/desc)
     *
     * @return Facet
     */
    public function orderByFunction(string $function, array|string|null $params = null, ?string $direction = null): self
    {
        if (!is_string($function) || trim($function) === '') {
            throw new SphinxQLException('orderByFunction() function name must be a non-empty string.');
        }
        if ($params === null || (is_array($params) && count($params) === 0)) {
            throw new SphinxQLException('orderByFunction() requires one or more parameters.');
        }

        if (is_array($params)) {
            $params = implode(',', $params);
        }

        $this->order_by[] = array(
            'column' => new Expression($function.'('.$params.')'),
            'direction' => $this->normalizeDirection($direction, 'orderByFunction')
        );

        return $this;
    }

    /**
     * LIMIT clause
     * Supports also LIMIT offset, limit
     *
     * @param int      $offset Offset if $limit is specified, else limit
     * @param null|int $limit  The limit to set, null for no limit
     *
     * @return Facet
     */
    public function limit(int|string $offset, int|string|null $limit = null): self
    {
        if ($limit === null) {
            if (filter_var($offset, FILTER_VALIDATE_INT) === false || (int) $offset < 0) {
                throw new SphinxQLException('limit() requires a non-negative integer.');
            }

            $this->limit = (int) $offset;

            return $this;
        }

        if (filter_var($offset, FILTER_VALIDATE_INT) === false || (int) $offset < 0) {
            throw new SphinxQLException('limit() offset must be a non-negative integer.');
        }
        if (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 0) {
            throw new SphinxQLException('limit() limit must be a non-negative integer.');
        }

        $this->offset($offset);
        $this->limit = (int) $limit;

        return $this;
    }

    /**
     * OFFSET clause
     *
     * @param int $offset The offset
     *
     * @return Facet
     */
    public function offset(int|string $offset): self
    {
        if (filter_var($offset, FILTER_VALIDATE_INT) === false || (int) $offset < 0) {
            throw new SphinxQLException('offset() requires a non-negative integer.');
        }

        $this->offset = (int) $offset;

        return $this;
    }

    /**
     * Compiles the statements for FACET
     *
     * @return Facet
     * @throws SphinxQLException In case no column in facet
     */
    public function compileFacet(): self
    {
        $query = 'FACET ';

        if (!empty($this->facet)) {
            $facets = array();
            foreach ($this->facet as $array) {
                if ($array instanceof Expression) {
                    $facets[] = $array;
                } elseif ($array[1] === null) {
                    $facets[] = $array[0];
                } else {
                    $facets[] = $array[0].' AS '.$array[1];
                }
            }
            $query .= implode(', ', $facets).' ';
        } else {
            throw new SphinxQLException('There is no column in facet.');
        }

        if (!empty($this->by)) {
            $query .= 'BY '.$this->by.' ';
        }

        if (!empty($this->order_by)) {
            $query .= 'ORDER BY ';

            $order_arr = array();

            foreach ($this->order_by as $order) {
                $order_sub = $order['column'].' ';
                if ($order['direction'] !== null) {
                    $order_sub .= ((strtolower($order['direction']) === 'desc') ? 'DESC' : 'ASC');
                } else {
                    $order_sub .= 'ASC';
                }

                $order_arr[] = $order_sub;
            }

            $query .= implode(', ', $order_arr).' ';
        }

        if ($this->limit !== null || $this->offset !== null) {
            if ($this->offset === null) {
                $this->offset = 0;
            }

            if ($this->limit === null) {
                $this->limit = 9999999999999;
            }

            $query .= 'LIMIT '.((int) $this->offset).', '.((int) $this->limit).' ';
        }

        $this->query = trim($query);

        return $this;
    }

    /**
     * Get String with SQL facet
     *
     * @return string
     * @throws SphinxQLException
     */
    public function getFacet(): string
    {
        return $this->compileFacet()->query;
    }

    /**
     * @param string|null $direction
     * @param string      $method
     *
     * @return string|null
     * @throws SphinxQLException
     */
    private function normalizeDirection(?string $direction, string $method): ?string
    {
        if ($direction === null) {
            return null;
        }

        if (!is_string($direction) || trim($direction) === '') {
            throw new SphinxQLException($method.'() direction must be one of: ASC, DESC, or null.');
        }

        $normalized = strtoupper(trim($direction));
        if (!in_array($normalized, array('ASC', 'DESC'), true)) {
            throw new SphinxQLException($method.'() direction must be one of: ASC, DESC, or null.');
        }

        return $normalized;
    }
}
