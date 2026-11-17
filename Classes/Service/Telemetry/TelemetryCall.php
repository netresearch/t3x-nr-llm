<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

/**
 * One telemetry row read back for a single trace — the provider calls of one
 * agent run (ADR-153).
 *
 * Metadata only, exactly like the row it comes from and like its sibling
 * {@see FallbackHop}: what ran, what answered, whether it worked and how long
 * it took. No prompt, no response, no exception message — `errorClass` is the
 * FQCN the row stores.
 *
 * `responseModel` is the model the provider named on its response (ADR-174's
 * `response_model`), which need not be the one the call was sent to: an alias
 * can resolve to a dated snapshot. `''` where the call produced no usage block:
 * a cache hit, a failed call, a specialized operation, a plain streamed chat.
 * Where a provider's answer names no model, its adapter reports the requested
 * id instead, so the value is not `''` then (ADR-320).
 */
final readonly class TelemetryCall
{
    public function __construct(
        public string $operation,
        public string $provider,
        public string $model,
        public string $servedProvider,
        public string $servedModel,
        public bool $success,
        public string $errorClass,
        public int $latencyMs,
        public bool $cacheHit,
        public int $fallbackAttempts,
        public ?int $timeToFirstTokenMs,
        public int $crdate,
        public string $responseModel = '',
    ) {}
}
