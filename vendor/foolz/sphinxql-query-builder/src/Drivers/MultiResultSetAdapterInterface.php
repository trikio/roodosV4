<?php

namespace Foolz\SphinxQL\Drivers;

interface MultiResultSetAdapterInterface
{
    /**
     * Advances to the next rowset
     */
    public function getNext(): void;

    /**
     * @return ResultSetInterface
     */
    public function current(): ResultSetInterface;

    /**
     * @return bool
     */
    public function valid(): bool;
}
