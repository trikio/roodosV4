<?php

namespace Foolz\SphinxQL\Drivers;

use Foolz\SphinxQL\Drivers\Pdo\ResultSetAdapter as PdoResultSetAdapter;
use Foolz\SphinxQL\Exception\ResultSetException;

class ResultSet implements ResultSetInterface
{
    /**
     * @var int
     */
    protected int $num_rows = 0;

    /**
     * @var int
     */
    protected int $cursor = 0;

    /**
     * @var int
     */
    protected int $next_cursor = 0;

    /**
     * @var int
     */
    protected int $affected_rows = 0; // leave to 0 so SELECT etc. will be coherent

    /**
     * @var array
     */
    protected array $fields = array();

    /**
     * @var null|array
     */
    protected array|int|null $stored = null;

    /**
     * @var null|array
     */
    protected ?array $fetched = null;

    /**
     * @var ResultSetAdapterInterface
     */
    protected ResultSetAdapterInterface $adapter;

    /**
     * @param ResultSetAdapterInterface $adapter
     */
    public function __construct(ResultSetAdapterInterface $adapter)
    {
        $this->adapter = $adapter;
        $this->init();

        if ($adapter instanceof PdoResultSetAdapter) { //only for pdo for some reason
            $this->store();
        }
    }

    /**
     * @inheritdoc
     */
    public function hasRow(int $num): bool
    {
        return $num >= 0 && $num < $this->num_rows;
    }

    /**
     * @inheritdoc
     */
    public function hasNextRow(): bool
    {
        return $this->cursor + 1 < $this->num_rows;
    }

    /**
     * @inheritdoc
     */
    public function getAffectedRows(): int
    {
        return $this->affected_rows;
    }

    /**
     * @inheritdoc
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $this->hasRow($offset);
    }

    /**
     * @inheritdoc
     * @throws ResultSetException
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!is_int($offset)) {
            throw new ResultSetException('The row does not exist.');
        }

        return $this->toRow($offset)->fetchAssoc();
    }

    /**
     * @inheritdoc
     * @codeCoverageIgnore
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     * @codeCoverageIgnore
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function current(): mixed
    {
        $row = $this->fetched;
        unset($this->fetched);

        return $row;
    }

    /**
     * @inheritdoc
     */
    public function next(): void
    {
        $this->fetched = $this->fetch(true);
    }

    /**
     * @inheritdoc
     */
    public function key(): int
    {
        return (int)$this->cursor;
    }

    /**
     * @inheritdoc
     */
    public function valid(): bool
    {
        if ($this->stored !== null) {
            return $this->hasRow($this->cursor);
        }

        return $this->adapter->valid();
    }

    /**
     * @inheritdoc
     */
    public function rewind(): void
    {
        if ($this->stored === null) {
            $this->adapter->rewind();
        }

        $this->next_cursor = 0;

        $this->fetched = $this->fetch(true);
    }

    /**
     * Returns the number of rows in the result set
     * @inheritdoc
     */
    public function count(): int
    {
        return $this->num_rows;
    }

    protected function init(): void
    {
        if ($this->adapter->isDml()) {
            $this->affected_rows = $this->adapter->getAffectedRows();
        } else {
            $this->num_rows = $this->adapter->getNumRows();
            $this->fields = $this->adapter->getFields();
        }
    }

    /**
     * @param array $numeric_array
     *
     * @return array
     */
    protected function makeAssoc(array $numeric_array): array
    {
        $assoc_array = array();
        foreach ($numeric_array as $col_key => $col_value) {
            $assoc_array[$this->fields[$col_key]->name] = $col_value;
        }

        return $assoc_array;
    }

    /**
     * @param bool $assoc
     *
     * @return array|bool|null
     */
    protected function fetchFromStore(bool $assoc = true): array|false|null
    {
        if ($this->stored === null || !is_array($this->stored)) {
            return false;
        }

        $row = isset($this->stored[$this->cursor]) ? $this->stored[$this->cursor] : null;

        if ($row !== null) {
            $row = $assoc ? $this->makeAssoc($row) : $row;
        }

        return $row;
    }

    /**
     * @param bool $assoc
     *
     * @return array|bool
     */
    protected function fetchAllFromStore(bool $assoc): array|false
    {
        if ($this->stored === null || !is_array($this->stored)) {
            return false;
        }

        $result_from_store = array();

        $this->cursor = $this->next_cursor;
        while ($row = $this->fetchFromStore($assoc)) {
            $result_from_store[] = $row;
            $this->cursor = ++$this->next_cursor;
        }

        return $result_from_store;
    }

    /**
     * @param bool $assoc
     *
     * @return array
     */
    protected function fetchAll(bool $assoc = true): array
    {
        $fetch_all_result = $this->fetchAllFromStore($assoc);

        if ($fetch_all_result === false) {
            $fetch_all_result = $this->adapter->fetchAll($assoc);
        }

        $this->cursor = $this->num_rows;
        $this->next_cursor = $this->cursor + 1;

        return $fetch_all_result;
    }

    /**
     * @inheritdoc
     */
    public function store(): self
    {
        if ($this->stored !== null) {
            return $this;
        }

        if ($this->adapter->isDml()) {
            $this->stored = $this->affected_rows;
        } else {
            $this->stored = $this->adapter->store();
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getStored(): array|int
    {
        $this->store();
        if ($this->adapter->isDml()) {
            return $this->getAffectedRows();
        }

        return $this->fetchAllAssoc();
    }

    /**
     * @inheritdoc
     */
    public function toRow(int $num): self
    {
        if (!$this->hasRow($num)) {
            throw new ResultSetException('The row does not exist.');
        }

        $this->cursor = $num;
        $this->next_cursor = $num;

        if ($this->stored === null) {
            $this->adapter->toRow($this->cursor);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function toNextRow(): self
    {
        $this->toRow(++$this->cursor);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function fetchAllAssoc(): array
    {
        return $this->fetchAll(true);
    }

    /**
     * @inheritdoc
     */
    public function fetchAllNum(): array
    {
        return $this->fetchAll(false);
    }

    /**
     * @inheritdoc
     */
    public function fetchAssoc(): ?array
    {
        return $this->fetch(true);
    }

    /**
     * @inheritdoc
     */
    public function fetchNum(): ?array
    {
        return $this->fetch(false);
    }

    /**
     * @param bool $assoc
     *
     * @return array|null
     */
    protected function fetch(bool $assoc = true): ?array
    {
        $this->cursor = $this->next_cursor;

        $row = $this->fetchFromStore($assoc);

        if ($row === false) {
            $row = $this->adapter->fetch($assoc);
        }

        $this->next_cursor++;

        return $row;
    }

    /**
     * @inheritdoc
     */
    public function freeResult(): self
    {
        $this->adapter->freeResult();

        return $this;
    }
}
