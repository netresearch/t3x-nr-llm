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
 * Records DALL·E image usage from the pipeline (ADR-100), replacing the
 * service's former direct ``trackImageUsage()`` call.
 *
 * The image count and the model / size / quality come from the
 * {@see SpecializedUsageIntent} the service attached before dispatch; the
 * gpt-image token object (dall-e-2/3 send none) comes from the raw response.
 * Cost is the per-image catalog price plus any token price, exactly as before.
 */
#[AutoconfigureTag(name: UsageMetricsExtractorInterface::TAG_NAME)]
final readonly class DallEUsageExtractor implements UsageMetricsExtractorInterface
{
    private const PROVIDER = 'dall-e';

    public function __construct(
        private SpecializedCostCalculatorInterface $costCalculator,
    ) {}

    public function supports(ProviderCallContext $context): bool
    {
        return $context->telemetryProvider() === self::PROVIDER
            && in_array($context->operation, [
                ProviderOperation::ImageGeneration,
                ProviderOperation::ImageEdit,
                ProviderOperation::ImageVariation,
            ], true);
    }

    public function extract(ProviderCallContext $context, mixed $result): ?ProviderUsageRecord
    {
        $intent = SpecializedUsageIntent::fromContext($context);
        if ($intent === null) {
            return null;
        }

        // The number of images actually returned, matching the former
        // count($results): generate / edit responses carry one entry, the batch
        // and variation endpoints carry the n requested.
        $data       = is_array($result) && is_array($result['data'] ?? null) ? $result['data'] : [];
        $imageCount = count($data);

        // gpt-image-* responses include a `usage` token object; dall-e-2/3 never
        // send one — token metrics are omitted then (all-zero) so the cost falls
        // back to the per-image catalog instead of a fabricated token price.
        $input = $output = $total = $imageInput = 0;
        $usage = is_array($result) ? ($result['usage'] ?? null) : null;
        if (is_array($usage)) {
            $input  = is_numeric($usage['input_tokens'] ?? null) ? (int)$usage['input_tokens'] : 0;
            $output = is_numeric($usage['output_tokens'] ?? null) ? (int)$usage['output_tokens'] : 0;
            $total  = is_numeric($usage['total_tokens'] ?? null) ? (int)$usage['total_tokens'] : $input + $output;
            $details = $usage['input_tokens_details'] ?? null;
            $imageInput = is_array($details) && is_numeric($details['image_tokens'] ?? null)
                ? (int)$details['image_tokens']
                : 0;
        }

        /** @var array{tokens?: int, promptTokens?: int, completionTokens?: int, images: int, cost: float} $metrics */
        $metrics = ['images' => $imageCount];
        if ($input > 0 || $output > 0 || $total > 0) {
            $metrics['tokens']           = $total;
            $metrics['promptTokens']     = $input;
            $metrics['completionTokens'] = $output;
        }
        $metrics['cost'] = $this->costCalculator->estimateImageCost(
            $intent->modelId,
            $intent->quality ?? 'standard',
            $intent->size ?? '',
            $imageCount,
            $input,
            $output,
            $imageInput,
        );

        return new ProviderUsageRecord(
            serviceType: 'image',
            provider: self::PROVIDER,
            metrics: $metrics,
            configurationUid: $intent->configurationUid,
            modelUid: $intent->modelUid,
            modelId: $intent->modelId,
            beUserUid: $intent->beUserUid,
        );
    }
}
