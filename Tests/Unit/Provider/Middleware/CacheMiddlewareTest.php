<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(CacheMiddleware::class)]
final class CacheMiddlewareTest extends AbstractUnitTestCase
{
    private CacheManagerInterface&MockObject $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->createMock(CacheManagerInterface::class);
    }

    #[Test]
    public function passesThroughWhenNoCacheKeyOnContext(): void
    {
        $this->cache->expects(self::never())->method('get');
        $this->cache->expects(self::never())->method('set');

        $result = $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'ran',
        );

        self::assertSame('ran', $result);
    }

    #[Test]
    public function passesThroughWhenCacheKeyIsEmptyString(): void
    {
        $this->cache->expects(self::never())->method('get');
        $this->cache->expects(self::never())->method('set');

        $result = $this->pipeline()->run(
            context: $this->context(key: ''),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'ran',
        );

        self::assertSame('ran', $result);
    }

    #[Test]
    public function passesThroughWhenCacheKeyIsNonString(): void
    {
        $this->cache->expects(self::never())->method('get');

        $context = new ProviderCallContext(
            operation: ProviderOperation::Embedding,
            correlationId: 'test',
            metadata: [CacheMiddleware::METADATA_CACHE_KEY => 42],
        );

        $this->pipeline()->run(
            context: $context,
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => ['stored' => false],
        );
    }

    #[Test]
    public function returnsCachedValueAndSkipsTerminalOnHit(): void
    {
        $cached = ['vector' => [0.1, 0.2, 0.3]];
        $this->cache->expects(self::once())
            ->method('get')
            ->with('embed:abc')
            ->willReturn($cached);
        $this->cache->expects(self::never())->method('set');

        $terminalCalled = false;
        $result         = $this->pipeline()->run(
            context: $this->context(key: 'embed:abc'),
            configuration: $this->configuration(),
            terminal: static function (LlmConfiguration $c) use (&$terminalCalled): array {
                $terminalCalled = true;

                return ['vector' => [0.9]];
            },
        );

        self::assertSame($cached, $result);
        self::assertFalse($terminalCalled, 'Terminal must not run on cache hit.');
    }

    #[Test]
    public function storesArrayResultOnMiss(): void
    {
        $produced = ['vector' => [0.4, 0.5]];

        $this->cache->expects(self::once())
            ->method('get')
            ->with('embed:new')
            ->willReturn(null);
        $this->cache->expects(self::once())
            ->method('set')
            ->with('embed:new', $produced, 3600, []);

        $result = $this->pipeline()->run(
            context: $this->context(key: 'embed:new'),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => $produced,
        );

        self::assertSame($produced, $result);
    }

    #[Test]
    public function usesCustomTtlFromMetadata(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('set')
            ->with('key', ['x' => 1], 86400, []);

        $this->pipeline()->run(
            context: $this->context(key: 'key', ttl: 86400),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => ['x' => 1],
        );
    }

    /**
     * Non-integer / non-positive TTL values all fall back to the default.
     * PHP's loose-typing conventions make it easy for a consumer to pass
     * a numeric string (`'3600'`), a float (`1.5`), a boolean (`true` — 1
     * under int-coercion), or any other shape through an options bag; the
     * middleware must treat every non-strict-int-positive value as "use
     * the default", never silently coercing.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nonIntegerOrNonPositiveTtlProvider(): array
    {
        return [
            'zero (explicit ignore)' => [0],
            'negative'               => [-1],
            'numeric string'         => ['3600'],
            'float'                  => [1.5],
            'bool true'              => [true],
            'bool false'             => [false],
            'null'                   => [null],
            'array'                  => [[3600]],
        ];
    }

    #[Test]
    #[DataProvider('nonIntegerOrNonPositiveTtlProvider')]
    public function nonIntegerOrNonPositiveTtlFallsBackToDefault(mixed $ttl): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('set')
            ->with('key', ['x' => 1], 3600, []);

        $context = new ProviderCallContext(
            operation: ProviderOperation::Embedding,
            correlationId: 'test',
            metadata: [
                CacheMiddleware::METADATA_CACHE_KEY => 'key',
                CacheMiddleware::METADATA_CACHE_TTL => $ttl,
            ],
        );

        $this->pipeline()->run(
            context: $context,
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => ['x' => 1],
        );
    }

    #[Test]
    public function passesCacheTagsThroughToSet(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('set')
            ->with('key', ['x' => 1], 3600, ['nr_llm_embed', 'user_42']);

        $this->pipeline()->run(
            context: $this->context(key: 'key', tags: ['nr_llm_embed', 'user_42']),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => ['x' => 1],
        );
    }

    #[Test]
    public function filtersNonStringAndEmptyTags(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('set')
            ->with('key', ['x' => 1], 3600, ['good']);

        $context = new ProviderCallContext(
            operation: ProviderOperation::Embedding,
            correlationId: 'test',
            metadata: [
                CacheMiddleware::METADATA_CACHE_KEY  => 'key',
                CacheMiddleware::METADATA_CACHE_TAGS => ['good', '', 42, null, false],
            ],
        );

        $this->pipeline()->run(
            context: $context,
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => ['x' => 1],
        );
    }

    #[Test]
    public function doesNotStoreNonArrayResult(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::never())->method('set');

        $result = $this->pipeline()->run(
            context: $this->context(key: 'key'),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'not-an-array',
        );

        self::assertSame('not-an-array', $result);
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline([new CacheMiddleware($this->cache)]);
    }

    /**
     * @param array<int, string>|null $tags
     */
    private function context(string $key, ?int $ttl = null, ?array $tags = null): ProviderCallContext
    {
        $metadata = [CacheMiddleware::METADATA_CACHE_KEY => $key];
        if ($ttl !== null) {
            $metadata[CacheMiddleware::METADATA_CACHE_TTL] = $ttl;
        }
        if ($tags !== null) {
            $metadata[CacheMiddleware::METADATA_CACHE_TAGS] = $tags;
        }

        return new ProviderCallContext(
            operation: ProviderOperation::Embedding,
            correlationId: 'test',
            metadata: $metadata,
        );
    }

    private function configuration(): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setIdentifier('primary');

        return $config;
    }
}
