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
 * @api
 */
final readonly class RecordReference implements Stringable
{
    /**
     * @param string $table the database table the record lives in
     * @param int    $uid   the record's uid, always positive
     *
     * @throws InvalidArgumentException when the table is blank or the uid is not positive
     */
    public function __construct(
        public string $table,
        public int $uid,
    ) {
        if (trim($table) === '') {
            throw new InvalidArgumentException('A record reference needs a table name.', 1788154801);
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
