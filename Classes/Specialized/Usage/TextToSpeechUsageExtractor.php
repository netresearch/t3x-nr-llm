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
 * Records text-to-speech usage from the pipeline (ADR-100). The billed unit is
 * the input character count, which the service knows up front and carries on the
 * {@see SpecializedUsageIntent}; the response (audio bytes) contributes nothing.
 */
#[AutoconfigureTag(name: UsageMetricsExtractorInterface::TAG_NAME)]
final readonly class TextToSpeechUsageExtractor implements UsageMetricsExtractorInterface
{
    private const PROVIDER = 'tts';

    public function __construct(
        private SpecializedCostCalculatorInterface $costCalculator,
    ) {}

    public function supports(ProviderCallContext $context): bool
    {
        return $context->telemetryProvider() === self::PROVIDER
            && $context->operation === ProviderOperation::SpeechSynthesis;
    }

    public function extract(ProviderCallContext $context, mixed $result): ?ProviderUsageRecord
    {
        $intent = SpecializedUsageIntent::fromContext($context);
        if ($intent === null) {
            return null;
        }

        $characters = $intent->characters ?? 0;

        return new ProviderUsageRecord(
            serviceType: 'speech',
            provider: self::PROVIDER,
            metrics: [
                'characters' => $characters,
                'cost'       => $this->costCalculator->estimateSpeechSynthesisCost($intent->modelId, $characters),
            ],
            configurationUid: $intent->configurationUid,
            modelUid: $intent->modelUid,
            modelId: $intent->modelId,
            beUserUid: $intent->beUserUid,
        );
    }
}
