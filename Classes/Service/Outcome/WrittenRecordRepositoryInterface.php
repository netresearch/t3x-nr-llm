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
 * @internal not part of the @api surface; may change without notice (ADR-127)
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
     * The history action codes of every retained row strictly newer than the
     * write, read at derivation time. Retained history is never proof of
     * complete coverage (ADR-273).
     *
     * @return array{later: list<int>}
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
