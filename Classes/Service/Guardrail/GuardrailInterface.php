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
 * content. Input-side screening is a separate contract with its own interface
 * ({@see InputGuardrailInterface}, run by {@see InputGuardrailScreener}) rather
 * than a stage of this pipeline: the pipeline context deliberately carries no
 * payload (ADR-026), so the prompt is screened at the call site before the
 * pipeline is entered (ADR-087).
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface GuardrailInterface extends GuardrailIdentity
{
    public const TAG_NAME = 'nr_llm.guardrail';

    public function checkOutput(CompletionResponse $response): GuardrailResult;
}
