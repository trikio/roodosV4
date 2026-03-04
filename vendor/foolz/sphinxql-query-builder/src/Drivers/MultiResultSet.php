<?php

namespace Foolz\SphinxQL\Drivers;

use Foolz\SphinxQL\Exception\DatabaseException;

class MultiResultSet implements MultiResultSetInterface
{
    /**
     * @var null|array
     */
    protected ?array $stored = null;

    /**
     * @var int
     */
    protected int $cursor = 0;

    /**
     * @var int
     */
    protected int $next_cursor = 0;

    /**
     * @var ResultSetInterface|null
     */
    protected ResultSetInterface|false|null $rowSet = null;

    /**
     * @var MultiResultSetAdapterInterface
     */
    protected MultiResultSetAdapterInterface $adapter;

    /**
     * @var bool
     */
    protected bool $valid = true;

    /**
     * @param MultiResultSetAdapterInterface $adapter
     */
    public function __construct(MultiResultSetAdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @inheritdoc
     * @throws DatabaseException
     */
    public function getStored(): ?array
    {
        $this->store();

        return $this->stored;
    }

    /**
     * @inheritdoc
     * @throws DatabaseException
     */
    public function offsetExists(mixed $offset): bool
    {
        $this->store();

        return is_int($offset) && $this->storedValid($offset);
    }

    /**
     * @inheritdoc
     * @throws DatabaseException
     */
    public function offsetGet(mixed $offset): mixed
    {
        $this->store();

        if (!is_int($offset) || !$this->storedValid($offset)) {
            return null;
        }

        return $this->stored[$offset];
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
    public function next(): void
    {
        $this->rowSet = $this->getNext();
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
    public function rewind(): void
    {
        // we actually can't roll this back unless it was stored first
        $this->cursor = 0;
        $this->next_cursor = 0;
        $this->rowSet = $this->getNext();
    }

    /**
     * @inheritdoc
     * @throws DatabaseException
     */
    public function count(): int
    {
        $this->store();

        return count($this->stored);
    }

    /**
     * @inheritdoc
     */
    public function valid(): bool
    {
        if ($this->stored !== null) {
            return $this->storedValid();
        }

        return $this->adapter->valid();
    }

    /**
     * @inheritdoc
     */
    public function current(): mixed
    {
        $rowSet = $this->rowSet;
        unset($this->rowSet);

        return $rowSet;
    }

    /**
     * @param null|int $cursor
     *
     * @return bool
     */
    protected function storedValid(?int $cursor = null): bool
    {
        $cursor = (!is_null($cursor) ? $cursor : $this->cursor);

        return $cursor >= 0 && $this->stored !== null && $cursor < count($this->stored);
    }

    /**
     * @inheritdoc
     */
    public function getNext(): ResultSetInterface|false
    {
        $this->cursor = $this->next_cursor;

        if ($this->stored !== null) {
            $resultSet = !$this->storedValid() ? false : $this->stored[$this->cursor];
        } else {
            if ($this->next_cursor > 0) {
                $this->adapter->getNext();
            }

            $resultSet = !$this->adapter->valid() ? false : $this->adapter->current();
        }

        $this->next_cursor++;

        return $resultSet;
    }

    /**
     * @inheritdoc
     */
    public function store(): self
    {
        if ($this->stored !== null) {
            return $this;
        }

        // don't let users mix storage and driver cursors
        if ($this->next_cursor > 0) {
            throw new DatabaseException('The MultiResultSet is using the driver cursors, store() can\'t fetch all the data');
        }

        $store = array();
        while ($set = $this->getNext()) {
            // this relies on stored being null!
            $store[] = $set->store();
        }

        $this->cursor = 0;
        $this->next_cursor = 0;

        // if we write the array straight to $this->stored it won't be null anymore and functions relying on null will break
        $this->stored = $store;

        return $this;
    }
}
