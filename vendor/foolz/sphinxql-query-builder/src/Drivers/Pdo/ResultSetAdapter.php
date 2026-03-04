<?php

namespace Foolz\SphinxQL\Drivers\Pdo;

use Foolz\SphinxQL\Drivers\ResultSetAdapterInterface;
use PDO;
use PDOStatement;

class ResultSetAdapter implements ResultSetAdapterInterface
{
    /**
     * @var PDOStatement
     */
    protected PDOStatement $statement;

    /**
     * @var bool
     */
    protected bool $valid = true;

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
    public function getAffectedRows(): int
    {
        return $this->statement->rowCount();
    }

    /**
     * @inheritdoc
     */
    public function getNumRows(): int
    {
        return $this->statement->rowCount();
    }

    /**
     * @inheritdoc
     */
    public function getFields(): array
    {
        $fields = array();

        for ($i = 0; $i < $this->statement->columnCount(); $i++) {
            $fields[] = (object)$this->statement->getColumnMeta($i);
        }

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function isDml(): bool
    {
        return $this->statement->columnCount() === 0;
    }

    /**
     * @inheritdoc
     */
    public function store(): array
    {
        return $this->normalizeRows($this->statement->fetchAll(PDO::FETCH_NUM));
    }

    /**
     * @inheritdoc
     */
    public function toRow(int $num): void
    {
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function freeResult(): void
    {
        $this->statement->closeCursor();
    }

    /**
     * @inheritdoc
     */
    public function rewind(): void
    {

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
            $row = $this->statement->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->statement->fetch(PDO::FETCH_NUM);
        }

        if (!$row) {
            $this->valid = false;
            $row = null;
        } else {
            $row = $this->normalizeRow($row);
        }

        return $row;
    }

    /**
     * @inheritdoc
     */
    public function fetchAll(bool $assoc = true): array
    {
        if ($assoc) {
            $row = $this->statement->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row = $this->statement->fetchAll(PDO::FETCH_NUM);
        }

        if (empty($row)) {
            $this->valid = false;
        }

        return $this->normalizeRows($row);
    }

    /**
     * Cast scalar non-string values to string to keep PDO and MySQLi
     * result typing aligned across PHP versions.
     *
     * @param array $row
     * @return array
     */
    protected function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_bool($value)) {
                $row[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value) && !is_string($value)) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    /**
     * @param array $rows
     * @return array
     */
    protected function normalizeRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->normalizeRow($row);
        }

        return $rows;
    }
}
