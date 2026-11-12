<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;

/**
 * A guardrail that reads a stream after it was delivered and reports hints
 * (ADR-288).
 *
 * Where {@see self::annotatesStream()} says so and no guardrail holds the
 * stream, the stream is delivered live and
 * {@see ContextualGuardrailInterface::checkOutputInContext()} is called on the
 * whole text at its end. Only the hints of that result are used: a delivered
 * text cannot be taken back, so a blocking verdict there is logged and nothing
 * else. The hints are the return value of the stream's generator.
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
interface StreamAnnotatingGuardrailInterface extends ContextualGuardrailInterface
{
    public function annotatesStream(ProviderCallContext $context): bool;
}
