<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a guardrail flags a provider response for human approval (ADR-085).
 *
 * Distinct from {@see GuardrailViolationException} (an outright block): the
 * response is withheld from automatic use but not rejected — a consumer with a
 * run/review context catches this to route it to approval (the Epic-D /
 * review-queue seam), rather than to an error.
 */
final class GuardrailApprovalRequiredException extends RuntimeException implements NrLlmExceptionInterface
{
    public function __construct(
        public readonly string $guardrail,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 1784600201, $previous);
    }
}
