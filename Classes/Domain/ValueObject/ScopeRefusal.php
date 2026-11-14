<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * One write the scope of an assignment refused (ADR-295): which operation, on
 * which table and record, and why. Identity only, like
 * {@see ObservedChange}: no title and no field value.
 *
 * The record is the one the write named. A write that creates a record has
 * none yet: its uid is 0 and its pid is the page it would have created it on.
 *
 * @api
 */
final readonly class ScopeRefusal
{
    /** The record, or the page a record would be created on, is not in scope. */
    public const OUTSIDE_SCOPE = 'outside_scope';

    /** The scope does not admit writes to this table. */
    public const TABLE_NOT_ALLOWED = 'table_not_allowed';

    /** The scope does not admit writes in this language. */
    public const LANGUAGE_NOT_ALLOWED = 'language_not_allowed';

    /** A page command reaches subpages that are not all in scope. */
    public const SUBTREE_OUTSIDE_SCOPE = 'subtree_outside_scope';

    /** The record does not exist, or the write could not be read. */
    public const UNRESOLVABLE = 'unresolvable';

    private const REASONS = [
        self::OUTSIDE_SCOPE,
        self::TABLE_NOT_ALLOWED,
        self::LANGUAGE_NOT_ALLOWED,
        self::SUBTREE_OUTSIDE_SCOPE,
        self::UNRESOLVABLE,
    ];

    public function __construct(
        public string $operation,
        public string $table,
        public int $uid,
        public int $pid,
        public string $reason,
    ) {
        if (preg_match('/\A[a-z][a-z_]{0,31}\z/', $operation) !== 1 || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1 || !in_array($reason, self::REASONS, true) || $uid < 0) {
            throw new InvalidArgumentException('A scope refusal needs an operation, a table, a uid of 0 or more and a known reason.', 1799990239);
        }
    }

    /**
     * The record as `table:uid`, or `table (on page N)` for a creation.
     */
    public function subject(): string
    {
        return $this->uid > 0 ? sprintf('%s:%d', $this->table, $this->uid) : sprintf('%s (on page %d)', $this->table, $this->pid);
    }

    /**
     * @return array{operation: string, table: string, uid: int, pid: int, reason: string}
     */
    public function toArray(): array
    {
        return ['operation' => $this->operation, 'table' => $this->table, 'uid' => $this->uid, 'pid' => $this->pid, 'reason' => $this->reason];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $operation = $data['operation'] ?? null;
        $table     = $data['table'] ?? null;
        $uid       = $data['uid'] ?? null;
        $pid       = $data['pid'] ?? null;
        $reason    = $data['reason'] ?? null;
        if (!is_string($operation) || !is_string($table) || !is_int($uid) || !is_int($pid) || !is_string($reason)) {
            return null;
        }

        try {
            return new self($operation, $table, $uid, $pid, $reason);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
