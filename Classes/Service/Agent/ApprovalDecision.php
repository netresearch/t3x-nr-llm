<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * An operator's decision on a run suspended for human approval (ADR-084 /
 * ADR-101).
 *
 * One decision covers the whole pending tool-call turn (per-call verdicts are a
 * later epic). The decision is persisted as an
 * {@see \Netresearch\NrLlm\Domain\Enum\AgentEventKind::APPROVAL} event —
 * best-effort, like every event write — so who approved or denied, and when, is
 * part of the run's audit stream. Deliberately no free-text note: the event
 * stream is privacy-filtered (ADR-064) and a prose field would bypass that.
 */
final readonly class ApprovalDecision
{
    public function __construct(
        public bool $approved,
        public int $decidedByBeUser,
    ) {}
}
