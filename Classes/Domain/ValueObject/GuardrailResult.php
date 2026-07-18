<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;

/**
 * The outcome of a guardrail check (ADR-085): the verdict, a human-readable
 * reason, and — for a {@see GuardrailVerdict::REDACT} — the replacement content.
 *
 * Built through the named constructors so a guardrail never has to remember
 * which fields a verdict needs.
 */
final readonly class GuardrailResult
{
    private function __construct(
        public GuardrailVerdict $verdict,
        public string $reason = '',
        public ?string $redactedContent = null,
    ) {}

    public static function allow(): self
    {
        return new self(GuardrailVerdict::ALLOW);
    }

    public static function redact(string $redactedContent, string $reason = ''): self
    {
        return new self(GuardrailVerdict::REDACT, $reason, $redactedContent);
    }

    public static function deny(string $reason): self
    {
        return new self(GuardrailVerdict::DENY, $reason);
    }

    public static function retry(string $reason = ''): self
    {
        return new self(GuardrailVerdict::RETRY, $reason);
    }

    public static function requireApproval(string $reason): self
    {
        return new self(GuardrailVerdict::REQUIRE_APPROVAL, $reason);
    }
}
