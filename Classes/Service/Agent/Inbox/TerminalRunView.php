<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Inbox;

/**
 * A recent terminal run (completed / failed / cancelled) shown READ-ONLY for
 * context in the approvals inbox (ADR-109). Deliberately carries NO
 * suspended-state — a terminal run has none, and the inbox never surfaces run
 * values beyond this summary (ADR-064).
 */
final readonly class TerminalRunView
{
    public function __construct(
        public string $runUuid,
        public string $status,
        public int $createdAt,
        public int $finishedAt,
        public string $configLabel,
        public ?string $formattedCost = null,
    ) {}
}
