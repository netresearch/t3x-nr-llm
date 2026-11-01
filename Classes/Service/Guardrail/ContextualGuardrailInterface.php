<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;

/**
 * A guardrail whose verdict depends on the call, not only on the response
 * (ADR-238).
 *
 * {@see GuardrailInterface::checkOutput()} sees the response alone, so it
 * cannot know the configuration or the site. Where this interface is
 * implemented, the output path and the streaming path call
 * {@see self::checkOutputInContext()} instead. {@see self::checkOutput()} stays
 * the answer for a caller with no context.
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
interface ContextualGuardrailInterface extends GuardrailInterface
{
    public function checkOutputInContext(CompletionResponse $response, ProviderCallContext $context): GuardrailResult;

    /**
     * Whether a stream for this call must be held back and screened as a whole.
     *
     * A held stream yields its text once, after this guardrail allowed it. Say
     * true only when a verdict can actually block this call: the editor sees no
     * text growing. The context carries the configuration of the call.
     */
    public function holdsStream(ProviderCallContext $context): bool;
}
