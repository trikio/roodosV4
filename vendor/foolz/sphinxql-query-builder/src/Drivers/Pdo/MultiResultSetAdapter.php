<?php

namespace Foolz\SphinxQL\Drivers\Pdo;

use Foolz\SphinxQL\Drivers\MultiResultSetAdapterInterface;
use Foolz\SphinxQL\Drivers\ResultSet;
use PDOStatement;

class MultiResultSetAdapter implements MultiResultSetAdapterInterface
{
    /**
     * @var bool
     */
    protected bool $valid = true;

    /**
     * @var PDOStatement
     */
    protected PDOStatement $statement;

    /**
     * @param PDOStatement $statement
     */
    public function __construct(PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    /**
     * @inheritdoc
     */
    public function getNext(): void
    {
        if (
            !$this->valid() ||
            !$this->statement->nextRowset()
        ) {
            $this->valid = false;
        }
    }

    /**
     * @inheritdoc
     */
    public function current(): ResultSet
    {
        return new ResultSet(new ResultSetAdapter($this->statement));
    }

    /**
     * @inheritdoc
     */
    public function valid(): bool
    {
        return $this->valid;
    }
}
