<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

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
 * tag (auto-applied by the AutoconfigureTag below) and composed by
 * MiddlewarePipeline in priority order -- the highest priority runs first on
 * the "before" half and last on the "after" half, classic onion ordering.
 *
 * ORDERING CONVENTION: declare the priority as a static method
 *
 *     public static function getDefaultPriority(): int { return 90; }
 *
 * and NOT as `attributes: ['priority' => 90]` on AutoconfigureTag. Because
 * this interface carries the tag too, the container sees the tag twice per
 * middleware and deduplicates; the survivor is this declaration, which has no
 * priority, so an attribute priority is silently dropped and the pipeline
 * assembles unsorted (symfony/symfony#65120, hit on 2026-08-08).
 *
 * The method is a convention, not an abstract member -- adding one here would
 * break third-party implementations within a major version (ADR-127). A
 * middleware that omits it simply sorts at priority 0, i.e. innermost.
 *
 * The return type is declared `mixed` because different operations return
 * different typed responses (CompletionResponse, EmbeddingResponse,
 * VisionResponse, Generator for streaming, etc.). Concrete middleware should
 * keep the value unchanged unless its purpose is to transform it.
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface ProviderMiddlewareInterface
{
    public const TAG_NAME = 'nr_llm.provider_middleware';

    /**
     * Handle one call, delegating to `$next` to run the inner layers (ADR-096).
     * The configuration, when present, lives on `$context->configuration`; a
     * middleware that swaps it re-derives the context via
     * {@see ProviderCallContext::withConfiguration()} before calling `$next`.
     *
     * @param callable(ProviderCallContext): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed;
}
