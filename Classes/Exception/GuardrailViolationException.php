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
 *
 * @api
 */
final class GuardrailViolationException extends RuntimeException implements GuardrailPolicyException
{
    /**
     * @param string|null $storableReason the same refusal without generated text, for a record that
     *                                    outlives the request (ADR-288); null when the reason holds none
     */
    public function __construct(
        public readonly string $guardrail,
        string $reason,
        ?Throwable $previous = null,
        public readonly ?string $storableReason = null,
    ) {
        parent::__construct($reason, 1784600200, $previous);
    }

    /**
     * What a consumer may keep in a record, a job's error or a stored chat
     * message: the reason without any passage of the generated text it may
     * quote (ADR-288, ADR-064). The message itself is for the person who is
     * looking at the screen now.
     */
    public function storableMessage(): string
    {
        return $this->storableReason ?? $this->getMessage();
    }
}
