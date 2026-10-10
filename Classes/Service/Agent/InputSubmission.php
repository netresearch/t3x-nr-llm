<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * Typed user input for a WAITING_FOR_INPUT run (ADR-105).
 * The declared schema and turn digest must match before the run is claimed;
 * an invalid submission leaves the claim available for another submission.
 *
 * Values are untrusted content passed to the tool and model. Submission is
 * authorized for the run's initiator, administrators and approval-grant actors;
 * pending tools are checked again against the owner's live rights.
 *
 * The INPUT audit event records only submittedBy, never these values (ADR-064).
 * Values consumed by the tool can still appear in a later resumable transcript,
 * which is stored verbatim under the separate approval retention policy.
 *
 * @api
 */
final readonly class InputSubmission
{
    /**
     * @param array<string, mixed> $data       the user-supplied input, validated against the tool's schema before use
     * @param string|null          $turnDigest the {@see PendingTurnDigest::forInputState()} of the
     *                                         turn the submitter's form was rendered from. It binds
     *                                         the submission to THAT turn — pending calls, target
     *                                         tool and declared schema:
     *                                         {@see ResumeCoordinator::submitInput()} recomputes the
     *                                         digest from the freshly claimed state and refuses a
     *                                         mismatch, so a stale tab — or a second submitter whose
     *                                         input already let the run suspend on a different turn
     *                                         — cannot feed values into a call nobody was shown
     *                                         (ADR-150). Optional in the SIGNATURE only, for source
     *                                         compatibility; a null is refused at runtime exactly
     *                                         like a wrong digest, because "no digest" and "the
     *                                         wrong digest" prove the same thing.
     */
    public function __construct(
        public array $data,
        public int $submittedByBeUser,
        public ?string $turnDigest = null,
    ) {}
}
