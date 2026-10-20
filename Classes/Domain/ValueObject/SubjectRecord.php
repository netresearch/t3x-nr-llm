<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * The record a skill invocation is about (ADR-214 item 10, ADR-228): a page a
 * tour optimises, for example, in the language the editor works in.
 *
 * Identity only — table, uid and language, no field values. The runtime
 * checks at start, at worker pickup and at every continuation that the
 * backend user the run executes as may read it, and the highlight builtin
 * accepts only this record and, for a page, the content elements on it.
 *
 * @api
 */
final readonly class SubjectRecord
{
    public function __construct(
        public RecordReference $record,
        public int $language = 0,
    ) {
        if ($language < 0) {
            throw new InvalidArgumentException(
                sprintf('A subject record needs a language uid of 0 or more, got %d.', $language),
                1793500001,
            );
        }
    }

    /**
     * @return array{table: string, uid: int, language: int}
     */
    public function toArray(): array
    {
        return ['table' => $this->record->table, 'uid' => $this->record->uid, 'language' => $this->language];
    }

    /**
     * A stored subject record, or null for a value that is not one.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $table    = $data['table'] ?? null;
        $uid      = $data['uid'] ?? null;
        $language = $data['language'] ?? null;
        if (!is_string($table) || !is_int($uid) || !is_int($language)) {
            return null;
        }

        try {
            return new self(new RecordReference($table, $uid), $language);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
