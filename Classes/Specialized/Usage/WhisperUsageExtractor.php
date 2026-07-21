<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Usage;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\Usage\ProviderUsageRecord;
use Netresearch\NrLlm\Provider\Middleware\Usage\SpecializedUsageIntent;
use Netresearch\NrLlm\Provider\Middleware\Usage\UsageMetricsExtractorInterface;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Records Whisper transcription usage from the pipeline (ADR-100). The billed
 * unit is audio duration in seconds, which only the `verbose_json` response
 * format reports — a raw-string / plain-json response records the request with no
 * duration or cost, exactly as before.
 */
#[AutoconfigureTag(name: UsageMetricsExtractorInterface::TAG_NAME)]
final readonly class WhisperUsageExtractor implements UsageMetricsExtractorInterface
{
    private const PROVIDER = 'whisper';

    public function __construct(
        private SpecializedCostCalculatorInterface $costCalculator,
    ) {}

    public function supports(ProviderCallContext $context): bool
    {
        return $context->telemetryProvider() === self::PROVIDER
            && $context->operation === ProviderOperation::Transcription;
    }

    public function extract(ProviderCallContext $context, mixed $result): ?ProviderUsageRecord
    {
        $intent = SpecializedUsageIntent::fromContext($context);
        if ($intent === null) {
            return null;
        }

        $duration = is_array($result) && is_numeric($result['duration'] ?? null)
            ? (float)$result['duration']
            : null;

        /** @var array{audioSeconds?: int, cost?: float} $metrics */
        $metrics = [];
        if ($duration !== null && $duration > 0.0) {
            // Floor of 1: a sub-half-second clip must not round to 0 seconds while
            // carrying a positive cost — units and cost stay consistent.
            $metrics['audioSeconds'] = max(1, (int)round($duration));
            $metrics['cost']         = $this->costCalculator->estimateTranscriptionCost($intent->modelId, $duration);
        }

        return new ProviderUsageRecord(
            serviceType: 'speech',
            provider: self::PROVIDER,
            metrics: $metrics,
            configurationUid: $intent->configurationUid,
            modelUid: $intent->modelUid,
            modelId: $intent->modelId,
            beUserUid: $intent->beUserUid,
        );
    }
}
