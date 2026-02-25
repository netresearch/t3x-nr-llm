<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmbeddingOptions::class)]
class EmbeddingOptionsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorAcceptsValidParameters(): void
    {
        $options = new EmbeddingOptions(
            model: 'text-embedding-3-large',
            dimensions: 1536,
            cacheTtl: 3600,
            provider: 'openai',
        );

        self::assertEquals('text-embedding-3-large', $options->getModel());
        self::assertEquals(1536, $options->getDimensions());
        self::assertEquals(3600, $options->getCacheTtl());
        self::assertEquals('openai', $options->getProvider());
    }

    #[Test]
    public function constructorUsesDefaultCacheTtl(): void
    {
        $options = new EmbeddingOptions();

        // Default is 24 hours (86400 seconds)
        self::assertEquals(86400, $options->getCacheTtl());
    }

    #[Test]
    public function constructorThrowsForNegativeDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dimensions must be a positive integer');

        new EmbeddingOptions(dimensions: -1);
    }

    #[Test]
    public function constructorThrowsForZeroDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dimensions must be a positive integer');

        new EmbeddingOptions(dimensions: 0);
    }

    #[Test]
    public function constructorThrowsForNegativeCacheTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cache_ttl must be between');

        new EmbeddingOptions(cacheTtl: -1);
    }

    // Factory Presets

    #[Test]
    public function standardPresetHasDefaultCacheTtl(): void
    {
        $options = EmbeddingOptions::standard();

        self::assertEquals(86400, $options->getCacheTtl());
    }

    #[Test]
    public function noCachePresetHasZeroCacheTtl(): void
    {
        $options = EmbeddingOptions::noCache();

        self::assertEquals(0, $options->getCacheTtl());
    }

    #[Test]
    public function compactPresetHasLowDimensions(): void
    {
        $options = EmbeddingOptions::compact();

        self::assertEquals(256, $options->getDimensions());
        self::assertEquals(86400, $options->getCacheTtl());
    }

    #[Test]
    public function highPrecisionPresetHasHighDimensions(): void
    {
        $options = EmbeddingOptions::highPrecision();

        self::assertEquals(1536, $options->getDimensions());
        self::assertEquals(86400, $options->getCacheTtl());
    }

    // Fluent Setters

    #[Test]
    public function withModelReturnsNewInstance(): void
    {
        $options1 = new EmbeddingOptions(model: 'model1');
        $options2 = $options1->withModel('model2');

        self::assertNotSame($options1, $options2);
        self::assertEquals('model1', $options1->getModel());
        self::assertEquals('model2', $options2->getModel());
    }

    #[Test]
    public function withDimensionsReturnsNewInstance(): void
    {
        $options1 = new EmbeddingOptions(dimensions: 512);
        $options2 = $options1->withDimensions(1024);

        self::assertEquals(512, $options1->getDimensions());
        self::assertEquals(1024, $options2->getDimensions());
    }

    #[Test]
    public function withDimensionsValidatesValue(): void
    {
        $options = new EmbeddingOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withDimensions(0);
    }

    #[Test]
    public function withCacheTtlReturnsNewInstance(): void
    {
        $options1 = new EmbeddingOptions(cacheTtl: 3600);
        $options2 = $options1->withCacheTtl(7200);

        self::assertEquals(3600, $options1->getCacheTtl());
        self::assertEquals(7200, $options2->getCacheTtl());
    }

    #[Test]
    public function withCacheTtlValidatesValue(): void
    {
        $options = new EmbeddingOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->withCacheTtl(-1);
    }

    #[Test]
    public function withProviderReturnsNewInstance(): void
    {
        $options1 = new EmbeddingOptions(provider: 'openai');
        $options2 = $options1->withProvider('mistral');

        self::assertEquals('openai', $options1->getProvider());
        self::assertEquals('mistral', $options2->getProvider());
    }

    // Array Conversion

    #[Test]
    public function toArrayFiltersNullValues(): void
    {
        $options = new EmbeddingOptions(model: 'test-model');

        $array = $options->toArray();

        self::assertArrayHasKey('model', $array);
        self::assertArrayHasKey('cache_ttl', $array);
        self::assertArrayNotHasKey('dimensions', $array);
        self::assertArrayNotHasKey('provider', $array);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $options = new EmbeddingOptions(
            model: 'test',
            dimensions: 512,
            cacheTtl: 3600,
            provider: 'openai',
        );

        $array = $options->toArray();

        self::assertArrayHasKey('cache_ttl', $array);
        self::assertEquals(3600, $array['cache_ttl']);
    }

    #[Test]
    public function chainedFluentSettersWork(): void
    {
        $options = EmbeddingOptions::standard()
            ->withModel('custom-model')
            ->withDimensions(768)
            ->withProvider('openai');

        self::assertEquals('custom-model', $options->getModel());
        self::assertEquals(768, $options->getDimensions());
        self::assertEquals('openai', $options->getProvider());
        self::assertEquals(86400, $options->getCacheTtl());
    }
}
