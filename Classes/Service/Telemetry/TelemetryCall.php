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
    ) {}
}
