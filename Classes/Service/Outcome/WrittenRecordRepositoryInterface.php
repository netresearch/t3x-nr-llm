<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

/**
 * The reads the observed outcome needs (ADR-185).
 *
 * An interface for the same reason {@see CallOutcomeRepositoryInterface} is
 * one: the classification is worth testing at its boundaries — a purged
 * history, a vanished record — and arranging those against a live database
 * costs more than it proves.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface WrittenRecordRepositoryInterface
{
    /**
     * Every recorded write whose observation window has closed.
     *
     * @return list<ObservedWrite>
     */
    public function findWritesSettledBefore(int $timestamp, int $limit): array;

    /**
     * The history action codes of every row strictly newer than the write, and
     * whether the write's OWN row is still there.
     *
     * The second half separates "nothing happened" from "the evidence is gone",
     * which is the difference between `ACCEPTED_UNCHANGED` and `UNKNOWN`.
     *
     * @return array{later: list<int>, hasOwnRow: bool}
     */
    public function historyAfter(RecordReference $record, int $writtenAt): array;

    /**
     * Whether the record still exists at all — asked separately, so a discarded
     * record is recognised even when its history did not survive.
     */
    public function recordExists(RecordReference $record): bool;

    /**
     * Whether a history action code means the record was deleted.
     */
    public function isDeletion(int $actionType): bool;
}
