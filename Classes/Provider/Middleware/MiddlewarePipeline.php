<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs an ordered stack of ProviderMiddlewareInterface implementations around
 * a terminal provider call.
 *
 * Ordering follows the PSR-15 convention: the first-registered middleware is
 * the outermost layer of the onion -- it runs first on the way in and last on
 * the way out. Registration order comes from the service container's tagged
 * iterator; services can influence it with a `priority` tag attribute.
 *
 * The pipeline is side-effect-free on its own; every behavioural decision
 * (retry on rate-limit, skip cache, record usage, ...) lives in a concrete
 * middleware. Consumers call `run()` with the immutable call context, the
 * primary configuration, and the terminal callable that performs the actual
 * provider invocation.
 */
final readonly class MiddlewarePipeline
{
    /** @var list<ProviderMiddlewareInterface> */
    private array $middleware;

    /**
     * @param iterable<ProviderMiddlewareInterface> $middleware
     */
    public function __construct(
        #[AutowireIterator(ProviderMiddlewareInterface::TAG_NAME)]
        iterable $middleware,
    ) {
        $this->middleware = \is_array($middleware)
            ? \array_values($middleware)
            : \iterator_to_array($middleware, preserve_keys: false);
    }

    /**
     * @template T
     *
     * @param callable(LlmConfiguration): T $terminal the actual provider call,
     *                                                typically a closure over
     *                                                messages / options /
     *                                                adapter resolution
     *
     * @return T
     */
    public function run(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $terminal,
    ): mixed {
        $next = $terminal;
        foreach (\array_reverse($this->middleware) as $middleware) {
            $captured = $next;
            $next = static fn(LlmConfiguration $config): mixed
                => $middleware->handle($context, $config, $captured);
        }

        return $next($configuration);
    }
}
