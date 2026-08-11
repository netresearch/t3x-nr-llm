<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

/**
 * One governance-decision row read back from the audit trail (ADR-153).
 *
 * The read counterpart of {@see \Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent},
 * which is the write DTO: it has no `crdate` because the repository stamps that
 * on insert, and a reader needs it. Everything here is metadata by
 * construction — the table stores no content (ADR-064), so neither can this.
 */
final readonly class RecordedGovernanceEvent
{
    public function __construct(
        public string $decision,
        public string $reason,
        public string $toolName,
        public string $guardrail,
        public string $detail,
        public int $crdate,
    ) {}
}
