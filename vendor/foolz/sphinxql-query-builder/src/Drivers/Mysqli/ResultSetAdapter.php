<?php

namespace Foolz\SphinxQL\Drivers\Mysqli;

use Foolz\SphinxQL\Drivers\ResultSetAdapterInterface;
use Foolz\SphinxQL\Exception\ConnectionException;
use mysqli_result;

class ResultSetAdapter implements ResultSetAdapterInterface
{
    /**
     * @var Connection
     */
    protected Connection $connection;

    /**
     * @var mysqli_result|bool
     */
    protected mysqli_result|bool $result;

    /**
     * @var bool
     */
    protected bool $valid = true;

    /**
     * @param Connection         $connection
     * @param mysqli_result|bool $result
     */
    public function __construct(Connection $connection, mysqli_result|bool $result)
    {
        $this->connection = $connection;
        $this->result = $result;
    }

    /**
     * @inheritdoc
     * @throws ConnectionException
     */
    public function getAffectedRows(): int
    {
        return $this->connection->getConnection()->affected_rows;
    }

    /**
     * @inheritdoc
     */
    public function getNumRows(): int
    {
        return $this->result->num_rows;
    }

    /**
     * @inheritdoc
     */
    public function getFields(): array
    {
        return $this->result->fetch_fields();
    }

    /**
     * @inheritdoc
     */
    public function isDml(): bool
    {
        return !($this->result instanceof mysqli_result);
    }

    /**
     * @inheritdoc
     */
    public function store(): array
    {
        $this->result->data_seek(0);

        return $this->result->fetch_all(MYSQLI_NUM);
    }

    /**
     * @inheritdoc
     */
    public function toRow(int $num): void
    {
        $this->result->data_seek($num);
    }

    /**
     * @inheritdoc
     */
    public function freeResult(): void
    {
        $this->result->free_result();
    }

    /**
     * @inheritdoc
     */
    public function rewind(): void
    {
        $this->valid = true;
        $this->result->data_seek(0);
    }

    /**
     * @inheritdoc
     */
    public function valid(): bool
    {
        return $this->valid;
    }

    /**
     * @inheritdoc
     */
    public function fetch(bool $assoc = true): ?array
    {
        if ($assoc) {
            $row = $this->result->fetch_assoc();
        } else {
            $row = $this->result->fetch_row();
        }

        if (!$row) {
            $this->valid = false;
        }

        return $row === false ? null : $row;
    }

    /**
     * @inheritdoc
     */
    public function fetchAll(bool $assoc = true): array
    {
        if ($assoc) {
            $row = $this->result->fetch_all(MYSQLI_ASSOC);
        } else {
            $row = $this->result->fetch_all(MYSQLI_NUM);
        }

        if (empty($row)) {
            $this->valid = false;
        }

        return $row;
    }
}
