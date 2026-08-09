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
 *
 * @api
 */
final readonly class ApprovalDecision
{
    /**
     * @param string|null $turnDigest the {@see PendingTurnDigest} of the turn the
     *                                decider actually saw. It binds the decision
     *                                to THAT turn: {@see ResumeCoordinator::approve()}
     *                                recomputes the digest from the freshly
     *                                claimed state and refuses a mismatch, so a
     *                                stale tab — or a second decider whose
     *                                approval already let the run suspend on a
     *                                different turn — cannot authorise calls
     *                                nobody reviewed (ADR-132). Optional in the
     *                                SIGNATURE only, for source compatibility;
     *                                a null is refused at runtime exactly like a
     *                                wrong digest, because "no digest" and "the
     *                                wrong digest" prove the same thing: the
     *                                reviewed turn is not known.
     */
    public function __construct(
        public bool $approved,
        public int $decidedByBeUser,
        public ?string $turnDigest = null,
    ) {}
}
