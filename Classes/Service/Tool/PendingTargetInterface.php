<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;

/**
 * Opt-in: an approval-bound write tool that names, as structured values, the
 * record and the fields a pending call would write (ADR-214, item 9; amends
 * ADR-136).
 *
 * The approval card shows it next to the preview lines of
 * {@see ToolPreviewInterface}, so a consumer keys what the card showed —
 * an open point of a guided process — without parsing prose. Every builtin
 * that declares a write implements it.
 *
 * Contract for implementors:
 *
 * - **A pure function of the arguments.** It runs when a card is rendered,
 *   for whoever renders it, so it must read nothing: not the database, not
 *   the acting user. What it returns is already in the call's arguments,
 *   which the card shows anyway.
 * - Name the record the call acts on and the fields it writes. A call that
 *   writes a relation field of an existing record (a file reference on a
 *   content element, a social image on a page) names that record and field.
 *   A write that names a record but no field — a move, a publish, a delete —
 *   returns an empty field list.
 * - Return null for a call that creates its record, which has no uid before
 *   it runs, and for arguments that name no reachable record;
 *   {@see PendingWriteTarget::fromArguments()} does the second for you.
 *
 * @api Extension point: third-party write tools may implement this. No new
 *      abstract member within a major version.
 */
interface PendingTargetInterface
{
    /**
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget;
}
