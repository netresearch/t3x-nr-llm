<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Middleware wrapping a single provider call.
 *
 * Implementations receive the immutable ProviderCallContext, the current
 * LlmConfiguration, and a `$next` callable that continues the pipeline. Each
 * middleware decides whether to:
 *  - call `$next($configuration)` verbatim (pure pass-through),
 *  - call `$next($otherConfiguration)` to substitute the configuration (that
 *    is how a fallback middleware retries with a sibling config),
 *  - short-circuit and return its own result without calling `$next` at all
 *    (e.g. a cache-hit middleware returning the stored EmbeddingResponse),
 *  - or wrap the call with before/after logic (logging, metrics, usage
 *    tracking, budget accounting).
 *
 * Registered implementations are discovered via the `nr_llm.provider_middleware`
 * tag (auto-applied by AutoconfigureTag) and composed by MiddlewarePipeline in
 * registration order -- the first-registered middleware runs first on the
 * "before" half and last on the "after" half, classic onion ordering.
 *
 * The return type is declared `mixed` because different operations return
 * different typed responses (CompletionResponse, EmbeddingResponse,
 * VisionResponse, Generator for streaming, etc.). Concrete middleware should
 * keep the value unchanged unless its purpose is to transform it.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface ProviderMiddlewareInterface
{
    public const TAG_NAME = 'nr_llm.provider_middleware';

    /**
     * @param callable(LlmConfiguration): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed;
}
