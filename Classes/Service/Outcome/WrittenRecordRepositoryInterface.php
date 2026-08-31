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
     * Correlations with at least one write whose window has closed and NO
     * observed outcome yet.
     *
     * Excluding the already-answered ones in the QUERY rather than after it is
     * load-bearing: a fixed page of the oldest writes would stop advancing the
     * moment a full page of them had been answered, and the command would then
     * report no work while writes piled up behind it.
     *
     * @return list<string>
     */
    public function findUnansweredCorrelations(int $timestamp, int $limit): array;

    /**
     * Every write one run recorded, oldest first.
     *
     * A run can call more than one write tool, and they share a correlation id
     * because that id is the run's uuid. All of them are needed before the run
     * can be judged.
     *
     * @return list<ObservedWrite>
     */
    public function findWritesForCorrelation(string $correlationId): array;

    /**
     * What happened to a record after it was written: the action codes of every
     * history row strictly newer than the write, and the oldest history
     * timestamp still retained for that record.
     *
     * The second value is how "nothing happened" is told apart from "the
     * evidence is gone". History is trimmed oldest-first, so a record whose
     * oldest RETAINED row is newer than our write has had our write — and
     * anything between — removed. Asking whether a row exists at or before the
     * write proves nothing: an older row that survived would answer yes for a
     * write whose own row is long gone.
     *
     * `null` means the record has no retained history at all.
     *
     * @return array{later: list<int>, oldestRetained: int|null}
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
