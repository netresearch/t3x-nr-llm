<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;

/**
 * Turns a provider content-filter block into an explicit guardrail denial
 * (ADR-085).
 *
 * When a provider flags a response with ``finishReason = content_filter`` (see
 * {@see CompletionResponse::wasFiltered()}), the content is typically empty or
 * degraded. Rather than let that pass silently, this guardrail DENIES it so the
 * block surfaces as a typed {@see \Netresearch\NrLlm\Exception\GuardrailViolationException}
 * a caller can handle — the deny-verdict reference implementation.
 */
final readonly class ProviderContentFilterGuardrail implements GuardrailInterface
{
    public function getIdentifier(): string
    {
        return 'provider-content-filter';
    }

    // Optional (ADR-106): this enforces the provider's own policy block
    // (finishReason=content_filter -> DENY), not secret leakage, so a
    // configuration may select it out. Secret redaction stays mandatory.
    public function isMandatory(): bool
    {
        return false;
    }

    public function checkOutput(CompletionResponse $response): GuardrailResult
    {
        if ($response->wasFiltered()) {
            return GuardrailResult::deny('The provider content filter blocked this response.');
        }

        return GuardrailResult::allow();
    }
}
