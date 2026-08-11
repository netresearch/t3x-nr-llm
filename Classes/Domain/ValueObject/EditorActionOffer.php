<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One editor action a specific person may start right now (ADR-158).
 *
 * An {@see EditorAction} is a DECLARATION — constant, user-independent, the
 * same for everyone (ADR-152). An offer is that declaration after the tool gate
 * has answered for one viewer, one configuration and (optionally) one record
 * table. The distinction matters: the catalogue must never render an action the
 * gate would refuse, and the gate is the only thing that knows.
 *
 * `$group` is the tool's own {@see \Netresearch\NrLlm\Service\Tool\ToolInterface::getGroup()}
 * string rather than a {@see \Netresearch\NrLlm\Domain\Enum\ToolGroup} case:
 * the set of groups is open to third parties, and a group outside the curated
 * taxonomy renders under its raw identifier instead of disappearing.
 */
final readonly class EditorActionOffer
{
    public function __construct(
        /** The tool's wire name — what the run's allow-list carries. */
        public string $toolName,
        public EditorAction $action,
        public string $group,
    ) {}
}
