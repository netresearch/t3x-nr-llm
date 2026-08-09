<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

/**
 * One telemetry row that dispatched at least one fallback configuration, read
 * back for the analytics module.
 *
 * Metadata only, exactly like the row it comes from: which configuration was
 * requested, which one answered, whether the run ended well and how long it
 * took. No prompt, no response, no exception message.
 *
 * Whether a hop was a RESCUE — a sibling actually answering for the requested
 * configuration — is not decided here; see
 * {@see \Netresearch\NrLlm\Service\Analytics\FallbackRescueReport}.
 */
final readonly class FallbackHop
{
    public function __construct(
        public string $correlationId,
        public string $operation,
        public string $configurationIdentifier,
        public string $provider,
        public string $model,
        public string $servedConfigurationIdentifier,
        public string $servedProvider,
        public string $servedModel,
        public bool $success,
        public int $fallbackAttempts,
        public int $latencyMs,
        public int $crdate,
    ) {}
}
