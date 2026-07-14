<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\EmbedCacheKeyBuilder;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmbedCacheKeyBuilder::class)]
class EmbedCacheKeyBuilderTest extends AbstractUnitTestCase
{
    #[Test]
    public function buildReturnsEmptyMetadataWhenTtlIsZero(): void
    {
        $cacheManager = self::createMock(CacheManagerInterface::class);
        $cacheManager->expects(self::never())->method('generateCacheKey');

        $subject = new EmbedCacheKeyBuilder($cacheManager);

        self::assertSame([], $subject->build(0, 'openai', ['input' => 'x'], 'nrllm_provider_openai'));
    }

    #[Test]
    public function buildReturnsEmptyMetadataWhenTtlIsNegative(): void
    {
        $cacheManager = self::createMock(CacheManagerInterface::class);
        $cacheManager->expects(self::never())->method('generateCacheKey');

        $subject = new EmbedCacheKeyBuilder($cacheManager);

        self::assertSame([], $subject->build(-1, 'openai', ['input' => 'x'], 'nrllm_provider_openai'));
    }

    #[Test]
    public function buildReturnsCacheMetadataWhenTtlIsPositive(): void
    {
        $cacheManager = self::createMock(CacheManagerInterface::class);
        $cacheManager->expects(self::once())
            ->method('generateCacheKey')
            ->with('config-42', 'embeddings', ['input' => 'x', 'model' => 'm'])
            ->willReturn('generated-key');
        $cacheManager->method('sanitizeCacheTag')->willReturnArgument(0);

        $subject = new EmbedCacheKeyBuilder($cacheManager);

        $result = $subject->build(3600, 'config-42', ['input' => 'x', 'model' => 'm'], 'nrllm_configuration_config-42');

        self::assertSame([
            CacheMiddleware::METADATA_CACHE_KEY  => 'generated-key',
            CacheMiddleware::METADATA_CACHE_TTL  => 3600,
            CacheMiddleware::METADATA_CACHE_TAGS => ['nrllm_embeddings', 'nrllm_configuration_config-42'],
        ], $result);
    }

    #[Test]
    public function buildSanitizesTheScopeTag(): void
    {
        // Configuration identifiers use the dotted preset scheme
        // (nr_ai_search.embeddings); the cache frontend rejects a tag with a
        // dot, so the scope tag must be sanitized before it reaches the tags.
        $cacheManager = self::createMock(CacheManagerInterface::class);
        $cacheManager->method('generateCacheKey')->willReturn('generated-key');
        $cacheManager->method('sanitizeCacheTag')
            ->willReturnCallback(static fn(string $value): string => str_replace('.', '_', $value));

        $subject = new EmbedCacheKeyBuilder($cacheManager);

        $result = $subject->build(3600, 'nr_ai_search.embeddings', ['input' => 'x'], 'nrllm_configuration_nr_ai_search.embeddings');

        self::assertSame(
            ['nrllm_embeddings', 'nrllm_configuration_nr_ai_search_embeddings'],
            $result[CacheMiddleware::METADATA_CACHE_TAGS],
        );
    }
}
