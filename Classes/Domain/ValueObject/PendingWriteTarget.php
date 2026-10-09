<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * The record and the fields a pending write call names, as structured values
 * (ADR-214, item 9; amends ADR-136).
 *
 * The approval card of a process run keys an open point to it: a skipped
 * proposal is recorded under the record and the field names the card showed,
 * and only an applied write to the same record and fields closes it. Before
 * this, a consumer had the preview lines only, which are prose.
 *
 * It names what the call's ARGUMENTS name, before anything runs, and nothing
 * the record holds — an identity like {@see RecordReference}, never a value.
 * A write that names a record but no field, such as a move, a publish or a
 * delete, carries an empty field list. A call that creates its record has no
 * uid yet and has no target at all.
 *
 * Bounded by construction, like {@see RecordReference}: every field name is a
 * database identifier, and the list is sorted and free of duplicates, so two
 * cards for the same record and fields carry equal targets.
 *
 * @api
 */
final readonly class PendingWriteTarget
{
    private const IDENTIFIER_PATTERN = '/\A[A-Za-z0-9_]{1,64}\z/';

    /** @var list<string> the field names, sorted, without duplicates */
    public array $fields;

    /**
     * @param list<string> $fields the field names the call writes; empty for a write that names no field
     *
     * @throws InvalidArgumentException when a field name is not a database identifier
     */
    public function __construct(
        public RecordReference $record,
        array $fields = [],
    ) {
        foreach ($fields as $field) {
            if (preg_match(self::IDENTIFIER_PATTERN, $field) !== 1) {
                throw new InvalidArgumentException(
                    'A pending write target needs field names that are database identifiers.',
                    1791600101,
                );
            }
        }

        $fields = array_values(array_unique($fields));
        sort($fields);
        $this->fields = $fields;
    }

    /**
     * The target a model-supplied call names, or null when its arguments do
     * not name one a query could reach — a table that is not an identifier, a
     * uid that is not a positive integer, a field name that is not an
     * identifier. A tool's `pendingTarget()` uses this instead of throwing on
     * arguments it has not validated yet: the call is refused when it runs,
     * and until then it simply has no structured target.
     *
     * @param list<mixed> $fields
     */
    public static function fromArguments(mixed $table, mixed $uid, array $fields = []): ?self
    {
        if (!is_string($table)) {
            return null;
        }

        if (is_string($uid) && preg_match('/\A[1-9][0-9]{0,18}\z/', $uid) === 1) {
            $uid = (int)$uid;
        }

        if (!is_int($uid)) {
            return null;
        }

        $names = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                return null;
            }

            $names[] = $field;
        }

        try {
            return new self(new RecordReference($table, $uid), $names);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{table: string, uid: int, fields: list<string>}
     */
    public function toArray(): array
    {
        return ['table' => $this->record->table, 'uid' => $this->record->uid, 'fields' => $this->fields];
    }
}
