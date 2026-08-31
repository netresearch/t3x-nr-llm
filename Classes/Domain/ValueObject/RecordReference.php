<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Stringable;

/**
 * The record a tool created or changed — a table name and a uid, and nothing
 * else (ADR-182).
 *
 * Two scalars, because that is what the `sys_history` join behind the observed
 * outcome needs. Language and workspace are deliberately absent: ADR-182 adds
 * them when a consumer asks for them, not on the chance that one will.
 *
 * This is an IDENTITY, never a payload. It names a record so a later reader can
 * look it up under its own authorisation; it carries no field the record holds,
 * which is what keeps the persisted trace inside ADR-064's privacy rule.
 *
 * It is also the ONE channel of {@see ToolResult} that the tool loop does not
 * bound on the way out — `content` and `artifacts` are UTF-8-coerced and
 * byte-capped there, and a third unbounded string would have been a hole in that
 * rule. It is bounded HERE instead, by construction: a table name is a database
 * identifier, so anything that is not one is refused rather than truncated. That
 * removes the length question and the encoding question together, and it means
 * a reference that exists at all names something a query could actually reach.
 *
 * @api
 */
final readonly class RecordReference implements Stringable
{
    /**
     * The shape of a database identifier: letters, digits and underscores.
     *
     * Wide enough for every table TYPO3 and its extensions declare, and narrow
     * enough that the value carries no whitespace, no invalid UTF-8 and no
     * unbounded length into the persisted audit row.
     *
     * `\A` and `\z`, not `^` and `$`: `$` also matches BEFORE a trailing
     * newline, so `"pages\n"` satisfied the anchored-looking pattern and was
     * accepted — caught by the newline case in the refusal test, which is why
     * that case exists.
     */
    private const TABLE_PATTERN = '/\A[A-Za-z0-9_]{1,64}\z/';

    /**
     * @param string $table the database table the record lives in
     * @param int    $uid   the record's uid, always positive
     *
     * @throws InvalidArgumentException when the table is not a database identifier, or the uid is not positive
     */
    public function __construct(
        public string $table,
        public int $uid,
    ) {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new InvalidArgumentException(
                'A record reference needs a table name that is a database identifier.',
                1788154801,
            );
        }

        if ($uid < 1) {
            throw new InvalidArgumentException(
                sprintf('A record reference needs a positive uid, got %d.', $uid),
                1788154802,
            );
        }
    }

    /**
     * The canonical `table:uid` form used in trace steps and log lines.
     */
    public function __toString(): string
    {
        return $this->table . ':' . $this->uid;
    }

    /**
     * @return array{table: string, uid: int}
     */
    public function toArray(): array
    {
        return ['table' => $this->table, 'uid' => $this->uid];
    }
}
