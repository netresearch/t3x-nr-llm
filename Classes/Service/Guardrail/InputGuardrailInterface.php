<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A content-policy guardrail applied to the OUTGOING prompt before a provider
 * call (ADR-087) — the input-side complement of {@see GuardrailInterface}.
 *
 * Implementers are auto-collected via the `nr_llm.input_guardrail` DI tag (this
 * attribute) and run by {@see InputGuardrailScreener} on each message's text, in
 * tag order, before the request reaches the provider.
 *
 * The two sides differ in where they run and therefore in what they can do. The
 * output side runs inside the provider pipeline (ADR-085), where the prompt is
 * not reachable. Input screening runs on the send path in
 * {@see \Netresearch\NrLlm\Service\LlmServiceManager}, where the messages ARE
 * reachable — so a REDACT verdict rewrites the prompt content in place (a
 * middleware-side check could not). A DENY / REQUIRE_APPROVAL blocks the call
 * with the same typed exception the output side throws. RETRY has no meaning
 * before a provider call and is ignored.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface InputGuardrailInterface
{
    public const TAG_NAME = 'nr_llm.input_guardrail';

    public function checkInput(string $text): GuardrailResult;
}
