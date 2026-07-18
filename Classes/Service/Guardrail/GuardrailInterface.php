<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A content-policy guardrail applied to a provider response (ADR-085).
 *
 * Implementers are auto-collected via the `nr_llm.guardrail` DI tag (this
 * attribute) and run by {@see \Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware}
 * after every non-streaming provider call, in tag order. A guardrail returns a
 * {@see GuardrailResult} verdict: allow, redact, deny, retry, or require-approval.
 *
 * Only the OUTPUT (the model's response) is screened here — it is the untrusted
 * content. Input-side guardrails (screening the prompt) are a separate step: the
 * prompt payload is captured in the pipeline's terminal closure, not on the
 * provider-call context, so screening it requires threading the messages through
 * the context first (a documented follow-up in ADR-085).
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface GuardrailInterface
{
    public const TAG_NAME = 'nr_llm.guardrail';

    public function checkOutput(CompletionResponse $response): GuardrailResult;
}
