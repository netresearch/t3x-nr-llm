<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware\Usage;

/**
 * A ready-to-record usage row produced by a {@see UsageMetricsExtractorInterface}
 * and written by {@see \Netresearch\NrLlm\Provider\Middleware\UsageMiddleware}
 * (ADR-100).
 *
 * It carries exactly the arguments of
 * {@see \Netresearch\NrLlm\Service\UsageTrackerServiceInterface::trackUsage()},
 * so the middleware forwards it verbatim — the extractor owns the operation-
 * specific accounting (image count, characters, audio seconds, cost), the
 * middleware owns the single write.
 */
final readonly class ProviderUsageRecord
{
    /**
     * @param array{tokens?: int, promptTokens?: int, completionTokens?: int, characters?: int, audioSeconds?: int, images?: int, batch_size?: int, cost?: float} $metrics
     */
    public function __construct(
        public string $serviceType,
        public string $provider,
        public array $metrics,
        public ?int $configurationUid = null,
        public int $modelUid = 0,
        public string $modelId = '',
        public int $taskUid = 0,
        public ?int $beUserUid = null,
        public bool $countsAsRequest = true,
    ) {}
}
