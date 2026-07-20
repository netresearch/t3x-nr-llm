<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;

/**
 * A pipeline middleware that records the last context it saw, for asserting
 * that a call was routed through the pipeline with the expected operation and
 * provider/model (ADR-097). Add it to a MiddlewarePipeline in a test, run the
 * call, then read {@see self::$captured}.
 */
final class CapturingMiddleware implements ProviderMiddlewareInterface
{
    public ?ProviderCallContext $captured = null;

    public function handle(ProviderCallContext $context, callable $next): mixed
    {
        $this->captured = $context;

        return $next($context);
    }
}
