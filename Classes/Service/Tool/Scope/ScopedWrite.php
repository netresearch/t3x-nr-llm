<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

/**
 * What a write tool call will touch, stated from its arguments before it runs
 * (ADR-295): the existing rows it changes, moves, copies, deletes, translates
 * or publishes, and the pages it puts new rows on.
 *
 * Every anchor a call names (an "insert after this record" argument) is a
 * record of the claim as well as the page it is on: the tool places the new row
 * where the anchor is, so the anchor has to be in scope whichever page the
 * tool ends up using.
 *
 * @api
 */
final readonly class ScopedWrite
{
    /**
     * @param string                  $operation       update, create, move, copy, delete, translate, publish or attach
     * @param list<RecordReference>   $records         rows that exist and that the call acts on
     * @param list<ScopedDestination> $destinations    places the call puts rows
     * @param bool                    $reachesSubpages the call acts on a page and everything below it (delete, copy)
     * @param int|null                $language        the language the call writes in, when it names one
     * @param bool                    $writesFiles     the call changes files in a FAL storage, which a rollback cannot take back
     */
    public function __construct(
        public string $operation,
        public array $records = [],
        public array $destinations = [],
        public bool $reachesSubpages = false,
        public ?int $language = null,
        public bool $writesFiles = false,
    ) {}

    /**
     * The claim of a call that touches no record at all (a cache flush).
     */
    public static function nothing(string $operation = 'update'): self
    {
        return new self($operation);
    }

    /**
     * A uid from a tool argument: an integer, or a string of digits as a model
     * writes it. Null for anything else, so a claim built from it is refused
     * rather than guessed.
     */
    public static function uid(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * A page uid from a tool argument: 0 (the root) or more, an integer or a
     * string of digits. Null for anything else.
     */
    public static function pid(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,18})\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * The record an "insert after this one" argument names, as a list: empty
     * when the argument is absent, null when it is present and cannot be read.
     * An anchor is a record of the claim (see the class comment).
     *
     * @return list<RecordReference>|null
     */
    public static function anchor(string $table, mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return [];
        }

        $record = self::record($table, $value);

        return $record instanceof RecordReference ? [$record] : null;
    }

    /**
     * A record reference from table and uid arguments, or null.
     */
    public static function record(mixed $table, mixed $uid): ?RecordReference
    {
        $number = self::uid($uid);
        if (!is_string($table) || $number === null || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1) {
            return null;
        }

        return new RecordReference($table, $number);
    }
}
