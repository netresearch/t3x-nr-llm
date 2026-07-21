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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Records FAL image usage from the pipeline (ADR-100). FAL publishes no static
 * price list (billing varies per hosted model), so no cost is recorded — only
 * the number of images returned.
 */
#[AutoconfigureTag(name: UsageMetricsExtractorInterface::TAG_NAME)]
final readonly class FalUsageExtractor implements UsageMetricsExtractorInterface
{
    private const PROVIDER = 'fal';

    public function supports(ProviderCallContext $context): bool
    {
        return $context->telemetryProvider() === self::PROVIDER
            && $context->operation === ProviderOperation::ImageGeneration;
    }

    public function extract(ProviderCallContext $context, mixed $result): ?ProviderUsageRecord
    {
        $intent = SpecializedUsageIntent::fromContext($context);
        if ($intent === null) {
            return null;
        }

        $images = is_array($result) && is_array($result['images'] ?? null) ? $result['images'] : [];

        return new ProviderUsageRecord(
            serviceType: 'image',
            provider: self::PROVIDER,
            metrics: ['images' => count($images)],
            modelUid: $intent->modelUid,
            modelId: $intent->modelId,
            beUserUid: $intent->beUserUid,
        );
    }
}
