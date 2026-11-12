<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Observability;

use OpenTelemetry\API\Trace\SpanInterface;

/**
 * The span of one attempt at a model, with what ending it needs (ADR-291).
 *
 * Returned by {@see GenAiTelemetry::startStream()}, which is the only caller
 * that holds one across a suspension of its own code; the middleware path
 * keeps it on its stack.
 */
final readonly class GenAiCall
{
    /**
     * @param string $operation       the `gen_ai.operation.name` of the call
     * @param int    $startNs         `hrtime(true)` at the start, for the duration metric
     * @param int    $fallbackAttempt how many siblings of the chain had been dispatched when
     *                                this attempt started: 0 for the requested configuration
     */
    public function __construct(
        public SpanInterface $span,
        public string $operation,
        public int $startNs,
        public int $fallbackAttempt,
    ) {}
}
