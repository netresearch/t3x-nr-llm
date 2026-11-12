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
 * reason, and — for a {@see GuardrailVerdict::REDACT} — the replacement content
 * and, optionally, the replacement reasoning/`thinking` block (ADR-089): a model
 * echoes a secret into its reasoning trace as readily as into its answer.
 *
 * Built through the named constructors so a guardrail never has to remember
 * which fields a verdict needs. A null `redactedThinking` on a REDACT means
 * "leave the thinking as-is".
 *
 * @api
 */
final readonly class GuardrailResult
{
    /**
     * @param list<GuardrailHint> $hints       findings that do not stop the output (ADR-288), only on ALLOW
     * @param string|null         $auditReason what the governance log stores instead of `reason`, where
     *                                         the sentence for the editor quotes generated text (ADR-064)
     */
    private function __construct(
        public GuardrailVerdict $verdict,
        public string $reason = '',
        public ?string $redactedContent = null,
        public ?string $redactedThinking = null,
        public array $hints = [],
        public ?string $auditReason = null,
    ) {}

    public static function allow(): self
    {
        return new self(GuardrailVerdict::ALLOW);
    }

    /**
     * Let the output pass and report what was found (ADR-288).
     *
     * @param list<GuardrailHint> $hints
     */
    public static function allowWithHints(array $hints): self
    {
        return new self(GuardrailVerdict::ALLOW, hints: $hints);
    }

    public static function redact(string $redactedContent, string $reason = '', ?string $redactedThinking = null): self
    {
        return new self(GuardrailVerdict::REDACT, $reason, $redactedContent, $redactedThinking);
    }

    /**
     * @param string|null $auditReason the policy facts the governance log keeps when `$reason`
     *                                 quotes generated text; null stores `$reason`
     */
    public static function deny(string $reason, ?string $auditReason = null): self
    {
        return new self(GuardrailVerdict::DENY, $reason, auditReason: $auditReason);
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
