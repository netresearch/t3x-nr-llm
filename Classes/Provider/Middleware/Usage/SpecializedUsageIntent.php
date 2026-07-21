<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware\Usage;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;

/**
 * The stable, dispatch-independent inputs a specialized service knows before it
 * calls the provider: which model, who to attribute the usage to, and the
 * operation-specific counters it can measure up front (ADR-100).
 *
 * The service builds one and attaches it to the {@see ProviderCallContext}
 * metadata under {@see METADATA_KEY}; the matching
 * {@see UsageMetricsExtractorInterface} reads it back and combines it with the
 * raw response (which supplies the rest — provider token usage, audio duration,
 * the number of images actually returned) to compute the cost and the final
 * usage row.
 */
final readonly class SpecializedUsageIntent
{
    /** Metadata key under which the intent travels on the call context. */
    public const METADATA_KEY = 'nrllm.usage_intent';

    public function __construct(
        public string $modelId,
        public int $modelUid = 0,
        public ?int $configurationUid = null,
        public ?int $beUserUid = null,
        public ?string $size = null,
        public ?string $quality = null,
        public ?int $characters = null,
        public ?int $batchSize = null,
    ) {}

    /**
     * Read the intent off a call context, or null when none was attached
     * (an internal sub-call or metadata lookup that records no usage).
     */
    public static function fromContext(ProviderCallContext $context): ?self
    {
        $intent = $context->metadata[self::METADATA_KEY] ?? null;

        return $intent instanceof self ? $intent : null;
    }
}
