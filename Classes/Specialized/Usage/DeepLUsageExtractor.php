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
 * Records DeepL translation usage from the pipeline (ADR-100). The billed unit is
 * the input character count (a batch also records its size); DeepL cost is not
 * computed here. An intent is set only by translate() / translateBatch(), so the
 * internal language-detection sub-call (also a Translation operation) records
 * nothing.
 */
#[AutoconfigureTag(name: UsageMetricsExtractorInterface::TAG_NAME)]
final readonly class DeepLUsageExtractor implements UsageMetricsExtractorInterface
{
    private const PROVIDER = 'deepl';

    public function supports(ProviderCallContext $context): bool
    {
        return $context->telemetryProvider() === self::PROVIDER
            && $context->operation === ProviderOperation::Translation;
    }

    public function extract(ProviderCallContext $context, mixed $result): ?ProviderUsageRecord
    {
        $intent = SpecializedUsageIntent::fromContext($context);
        if ($intent === null) {
            return null;
        }

        /** @var array{characters: int, batch_size?: int} $metrics */
        $metrics = ['characters' => $intent->characters ?? 0];
        if ($intent->batchSize !== null) {
            $metrics['batch_size'] = $intent->batchSize;
        }

        return new ProviderUsageRecord(
            serviceType: 'translation',
            provider: self::PROVIDER,
            metrics: $metrics,
            beUserUid: $intent->beUserUid,
        );
    }
}
