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
 * Thrown when a guardrail denies a provider response (ADR-085).
 *
 * Carries the denying guardrail's class so operators can tell which policy
 * tripped; the message is the guardrail's reason. Mirrors
 * {@see BudgetExceededException} — a typed, catchable pipeline denial.
 */
final class GuardrailViolationException extends RuntimeException implements NrLlmExceptionInterface
{
    public function __construct(
        public readonly string $guardrail,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 1784600200, $previous);
    }
}
