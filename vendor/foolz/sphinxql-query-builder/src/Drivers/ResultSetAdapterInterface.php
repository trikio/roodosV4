<?php

namespace Foolz\SphinxQL\Drivers;

interface ResultSetAdapterInterface
{
    /**
     * @return int
     */
    public function getAffectedRows(): int;

    /**
     * @return int
     */
    public function getNumRows(): int;

    /**
     * @return array
     */
    public function getFields(): array;

    /**
     * @return bool
     */
    public function isDml(): bool;

    /**
     * @return array
     */
    public function store(): array;

    /**
     * @param int $num
     */
    public function toRow(int $num): void;

    /**
     * Free a result set/Closes the cursor, enabling the statement to be executed again.
     */
    public function freeResult(): void;

    /**
     * Rewind to the first element
     */
    public function rewind(): void;

    /**
     * @return bool
     */
    public function valid(): bool;

    /**
     * @param bool $assoc
     *
     * @return array|null
     */
    public function fetch(bool $assoc = true): ?array;

    /**
     * @param bool $assoc
     *
     * @return array
     */
    public function fetchAll(bool $assoc = true): array;
}
