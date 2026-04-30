<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

/**
 * Reads arbitrary backend records for the Task record-picker pathway.
 *
 * Concrete implementation: `RecordTableReader`. The interface exists
 * so controllers and tests can mock the schema/SQL surface without
 * standing up a real database.
 */
interface RecordTableReaderInterface
{
    /**
     * List every backend table the picker is allowed to expose,
     * sorted alphabetically by display label.
     *
     * @return list<array{name: string, label: string}>
     */
    public function listAllowedTables(): array;

    /**
     * Render a raw table name as a human-friendly label
     * (`sys_log` → `System: Log`, `tx_news_domain_model_news` →
     * `News Domain Model News`, …).
     */
    public function formatTableLabel(string $table): string;

    /**
     * Resolve the column the picker should display per row, with
     * TCA-aware lookup followed by a common-name fallback list.
     *
     * Returns the empty string when no suitable column is found.
     */
    public function detectLabelField(string $table): string;

    /**
     * Cheap schema check — some backend tables (e.g. `tx_scheduler_task`)
     * have no `uid` column and therefore cannot be used as picker
     * sources.
     */
    public function tableHasUidColumn(string $table): bool;

    /**
     * Fetch a label-friendly preview of records for the picker dropdown.
     *
     * The returned shape is intentionally minimal — uid + display label
     * only — so the JSON payload stays small for tables with millions
     * of rows. Use `loadRecordsByUids()` to fetch the full row data
     * for the records the user has actually selected.
     *
     * @return list<array{uid: int, label: string}>
     */
    public function fetchSampleRecords(string $table, string $labelField, int $limit): array;

    /**
     * Load the full row data for a list of uids in a given table.
     * Used by the picker after the user has selected one or more rows.
     *
     * @param list<int> $uids
     *
     * @return list<array<string, mixed>>
     */
    public function loadRecordsByUids(string $table, array $uids): array;

    /**
     * Fetch up to `$limit` complete rows from a table, in storage
     * order. Used by the table-input branch of `getInputData()` to
     * render a Task's input source as JSON.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $table, int $limit): array;
}
