<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * A writing tool that CREATES records, declaring the tables it creates them in.
 *
 * The declaration the generic creator `create_record_draft` steps back from
 * (ADR-197): where a registered tool implementing this interface lists a
 * table, the fallback refuses that table and names the tool. An extension
 * that ships its own creator for its records implements this next to
 * {@see ToolInterface}, and the fallback withdraws from those tables the day
 * the extension is installed.
 *
 * Deliberately not {@see EditorActionInterface}: an editor action names the
 * SUBJECT its arguments identify (ADR-152) — a content-element creator
 * declares `pages`, because the page is what an editor selects — whereas this
 * names the table the row lands in.
 *
 * A declaration that throws makes the fallback refuse every table and name
 * the tool, rather than skip it: an unreadable declaration may be the one that
 * covers the table.
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
interface RecordCreatorInterface
{
    /**
     * The tables this tool creates records in.
     *
     * @return list<non-empty-string>
     */
    public function getCreatedTables(): array;
}
