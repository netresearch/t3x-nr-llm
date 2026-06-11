<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Support;

use ArrayIterator;
use LogicException;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Array-backed QueryResultInterface double for unit tests.
 *
 * Lets a mocked repository hand back a fixed, already-ordered list of
 * domain objects without touching Extbase persistence. Iteration is
 * delegated to an ArrayIterator; query access and mutation are not
 * supported — unit tests assert on the consumed objects, not on the
 * query that produced them.
 *
 * @template T of object
 *
 * @implements QueryResultInterface<int, T>
 */
final readonly class InMemoryQueryResult implements QueryResultInterface
{
    /** @var ArrayIterator<int, T> */
    private ArrayIterator $iterator;

    /**
     * @param list<T> $items already in the order the simulated query would return
     */
    public function __construct(private array $items)
    {
        $this->iterator = new ArrayIterator($items);
    }

    public function setQuery(QueryInterface $query): void
    {
        throw new LogicException('InMemoryQueryResult carries no query', 4039312101);
    }

    public function getQuery(): QueryInterface
    {
        throw new LogicException('InMemoryQueryResult carries no query', 4039312102);
    }

    public function getFirst(): ?object
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return list<T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function current(): mixed
    {
        return $this->iterator->current();
    }

    public function key(): mixed
    {
        return $this->iterator->key();
    }

    public function next(): void
    {
        $this->iterator->next();
    }

    public function rewind(): void
    {
        $this->iterator->rewind();
    }

    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_int($offset) ? ($this->items[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('InMemoryQueryResult is immutable', 4039312103);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('InMemoryQueryResult is immutable', 4039312104);
    }
}
