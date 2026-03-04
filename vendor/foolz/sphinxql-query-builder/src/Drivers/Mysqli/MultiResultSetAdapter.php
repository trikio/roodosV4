<?php

namespace Foolz\SphinxQL\Drivers\Mysqli;

use Foolz\SphinxQL\Drivers\MultiResultSetAdapterInterface;
use Foolz\SphinxQL\Drivers\ResultSet;
use Foolz\SphinxQL\Exception\ConnectionException;

class MultiResultSetAdapter implements MultiResultSetAdapterInterface
{
    /**
     * @var bool
     */
    protected bool $valid = true;

    /**
     * @var Connection
     */
    protected Connection $connection;

    /**
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @inheritdoc
     * @throws ConnectionException
     */
    public function getNext(): void
    {
        if (
            !$this->valid() ||
            !$this->connection->getConnection()->more_results()
        ) {
            $this->valid = false;
        } else {
            $this->connection->getConnection()->next_result();
        }
    }

    /**
     * @inheritdoc
     * @throws ConnectionException
     */
    public function current(): ResultSet
    {
        $adapter = new ResultSetAdapter($this->connection, $this->connection->getConnection()->store_result());
        return new ResultSet($adapter);
    }

    /**
     * @inheritdoc
     * @throws ConnectionException
     */
    public function valid(): bool
    {
        return $this->connection->getConnection()->errno === 0 && $this->valid;
    }
}
