<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;

/**
 * Proves idempotency round-trips a real, typed CompletionResponse through the
 * live `nrllm_idempotency` VariableFrontend — the capability that motivated a
 * dedicated middleware over the deliberately array-only CacheMiddleware.
 */
#[CoversClass(IdempotencyMiddleware::class)]
final class IdempotencyMiddlewarePipelineTest extends AbstractFunctionalTestCase
{
    private IdempotencyMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $cacheManager = $this->get(Typo3CacheManager::class);
        self::assertInstanceOf(Typo3CacheManager::class, $cacheManager);

        $this->middleware = new IdempotencyMiddleware($cacheManager);
    }

    #[Test]
    public function replaysAStoredTypedResponseFromTheRealCache(): void
    {
        $context  = new ProviderCallContext(
            ProviderOperation::Chat,
            'corr',
            [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => 'checkout-99'],
        );
        $response = new CompletionResponse(
            content: 'idempotent answer',
            model: 'gpt-4o',
            usage: UsageStatistics::fromArray(['promptTokens' => 5, 'completionTokens' => 7, 'totalTokens' => 12]),
            provider: 'openai',
        );

        // First call computes and stores.
        $first = $this->middleware->handle(
            $context,
            new LlmConfiguration(),
            static fn(LlmConfiguration $c): CompletionResponse => $response,
        );
        self::assertInstanceOf(CompletionResponse::class, $first);

        // Second call with the same key must NOT reach the provider — a throwing
        // terminal proves the replay is served entirely from the store.
        $replay = $this->middleware->handle(
            $context,
            new LlmConfiguration(),
            static fn(LlmConfiguration $c): CompletionResponse => throw new RuntimeException('provider must not be called', 1),
        );

        self::assertInstanceOf(CompletionResponse::class, $replay);
        self::assertSame('idempotent answer', $replay->content);
        self::assertSame('gpt-4o', $replay->model);
        self::assertSame('openai', $replay->provider);
        self::assertSame(12, $replay->usage->totalTokens);
    }
}
