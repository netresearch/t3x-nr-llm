<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Service\EmbeddingModelDimensions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmbeddingModelDimensions::class)]
class EmbeddingModelDimensionsTest extends AbstractUnitTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function knownModelProvider(): array
    {
        return [
            'openai small' => ['text-embedding-3-small', 1536],
            'openai large' => ['text-embedding-3-large', 3072],
            'openrouter route to openai small' => ['openai/text-embedding-3-small', 1536],
            'mistral' => ['mistral-embed', 1024],
            'ollama nomic' => ['nomic-embed-text', 768],
            'gemini' => ['gemini-embedding-2', 3072],
        ];
    }

    #[Test]
    #[DataProvider('knownModelProvider')]
    public function forModelIdReturnsPublishedDimensionality(string $modelId, int $expected): void
    {
        self::assertSame($expected, EmbeddingModelDimensions::forModelId($modelId));
    }

    #[Test]
    public function forModelIdStripsOllamaTagSuffix(): void
    {
        self::assertSame(768, EmbeddingModelDimensions::forModelId('nomic-embed-text:latest'));
        self::assertSame(768, EmbeddingModelDimensions::forModelId('nomic-embed-text:v1.5'));
    }

    #[Test]
    public function forModelIdReturnsZeroForUnknownModels(): void
    {
        self::assertSame(0, EmbeddingModelDimensions::forModelId('gpt-5.2'));
        self::assertSame(0, EmbeddingModelDimensions::forModelId('unknown-embed:latest'));
        self::assertSame(0, EmbeddingModelDimensions::forModelId(''));
    }

    #[Test]
    public function forModelIdDoesNotSeedAda002(): void
    {
        // ada-002 rejects the `dimensions` request parameter which
        // LlmServiceManager fills from the record — must stay unknown.
        self::assertSame(0, EmbeddingModelDimensions::forModelId('text-embedding-ada-002'));
    }
}
