<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The backend user a governance simulation answered for (ADR-157).
 *
 * Two uses, deliberately one type: the picker's options, and the identity the
 * readout names. An operator who reads "Refused" has to be able to see whose
 * permissions produced it, and a picker whose entries are a different shape
 * from the answer is how the two drift apart.
 *
 * **This is not impersonation.** The simulation resolves the user through
 * {@see \Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface} —
 * the same read-only seam a queue worker uses to authorise for the user who
 * queued the work (ADR-083) — and asks the gates. No session is switched,
 * nothing is executed as the user, and nothing is written.
 *
 * @internal
 */
final readonly class SimulationActor
{
    /**
     * @param int  $uid      the backend user's uid; 0 for a picker entry that
     *                       means "whoever is reading this page"
     * @param bool $resolved whether the resolver produced a live user. False
     *                       means the gates were asked with NO user, which is
     *                       how the runtime fails closed for a uid that no
     *                       longer resolves — a deleted or disabled account.
     */
    public function __construct(
        public int $uid,
        public string $username,
        public bool $admin,
        public bool $resolved = true,
    ) {}
}
