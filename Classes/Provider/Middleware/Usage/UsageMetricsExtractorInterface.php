<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware\Usage;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;

/**
 * Turns a specialized provider call (image / speech / translation) into a usage
 * row (ADR-100).
 *
 * The token-shaped responses (chat / embedding / vision) are recorded by
 * {@see \Netresearch\NrLlm\Provider\Middleware\UsageMiddleware} directly. A
 * specialized service instead returns a raw provider payload and measures usage
 * in its own unit (images, characters, audio seconds). Rather than each service
 * writing to the usage table itself, it stashes a {@see SpecializedUsageIntent}
 * in the call-context metadata before dispatch; the matching extractor reads that
 * intent together with the raw response and returns a {@see ProviderUsageRecord}
 * the middleware writes.
 *
 * A service that sets no intent (an internal sub-call such as DeepL language
 * detection, or a metadata lookup) yields no record — {@see supports()} or
 * {@see extract()} returns false / null — so nothing is recorded, exactly as
 * before.
 */
interface UsageMetricsExtractorInterface
{
    public const TAG_NAME = 'nr_llm.usage_metrics_extractor';

    /**
     * Whether this extractor handles the given call (matched on operation and
     * provider, so DALL·E and FAL — both image generation — do not collide).
     */
    public function supports(ProviderCallContext $context): bool;

    /**
     * Build the usage row from the call context (which carries the intent) and
     * the raw provider response, or null when there is nothing to record.
     */
    public function extract(ProviderCallContext $context, mixed $result): ?ProviderUsageRecord;
}
