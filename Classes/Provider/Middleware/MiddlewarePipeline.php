<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs an ordered stack of ProviderMiddlewareInterface implementations around
 * a terminal provider call.
 *
 * Ordering follows the PSR-15 convention: the first-registered middleware is
 * the outermost layer of the onion -- it runs first on the way in and last on
 * the way out. Order comes from each middleware's static
 * `getDefaultPriority()`, resolved by the tagged iterator below (highest
 * first).
 *
 * The priority deliberately does NOT live in the `AutoconfigureTag` attribute.
 * ProviderMiddlewareInterface carries the tag as well, so the container sees
 * the tag declared twice for every middleware and deduplicates the pair — and
 * the survivor is the interface's declaration, which has no priority. The
 * effect is that the pipeline silently assembles UNSORTED, which for this
 * stack is a privacy fault rather than a cosmetic one: GuardrailMiddleware
 * (90) must unwind inside IdempotencyMiddleware (105), or an unredacted
 * response is persisted to the nrllm_idempotency cache (ADR-085).
 *
 * Reading the priority from code instead of from tag attributes keeps the
 * order a property of this extension rather than of container-internal tag
 * merging. Observed as a live regression on 2026-08-08, when
 * symfony/dependency-injection 7.4.16 changed when discovered interfaces are
 * autoconfigured (symfony/symfony#65120) and the whole stack unsorted itself.
 *
 * The pipeline is side-effect-free on its own; every behavioural decision
 * (retry on rate-limit, skip cache, record usage, ...) lives in a concrete
 * middleware. Consumers call `run()` with the immutable call context (which carries the
 * configuration) and the terminal callable that performs the actual provider
 * invocation.
 */
final readonly class MiddlewarePipeline
{
    /** @var list<ProviderMiddlewareInterface> */
    private array $middleware;

    /**
     * @param iterable<ProviderMiddlewareInterface> $middleware
     */
    public function __construct(
        #[AutowireIterator(
            ProviderMiddlewareInterface::TAG_NAME,
            defaultPriorityMethod: 'getDefaultPriority',
        )]
        iterable $middleware,
    ) {
        $this->middleware = \is_array($middleware)
            ? \array_values($middleware)
            : \iterator_to_array($middleware, preserve_keys: false);
    }

    /**
     * @template T
     *
     * @param callable(ProviderCallContext): T $terminal the actual provider
     *                                                   call, typically a closure
     *                                                   over messages / options /
     *                                                   adapter resolution that
     *                                                   reads the configuration
     *                                                   from the context
     *
     * @return T
     */
    public function run(
        ProviderCallContext $context,
        callable $terminal,
    ): mixed {
        $next = $terminal;
        foreach (\array_reverse($this->middleware) as $middleware) {
            $captured = $next;
            $next = static fn(ProviderCallContext $ctx): mixed
                => $middleware->handle($ctx, $captured);
        }

        return $next($context);
    }
}
