<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Additional mutation-killing tests for EmbeddingService.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(EmbeddingService::class)]
class EmbeddingServiceMutationTest extends AbstractUnitTestCase
{
    /**
     * @param array<int, array<int, float>> $embeddings
     */
    private function createMockEmbeddingResponse(array $embeddings): EmbeddingResponse
    {
        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(
                promptTokens: 5,
                completionTokens: 0,
                totalTokens: 5,
            ),
            provider: 'openai',
        );
    }

    #[Test]
    public function findMostSimilarReturnsEmptyArrayForEmptyCandidates(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $result = $service->findMostSimilar([0.1, 0.2, 0.3], [], 5);

        self::assertEmpty($result);
    }

    #[Test]
    public function findMostSimilarReturnsLimitedResults(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $queryVector = [1.0, 0.0, 0.0];
        $candidates = [
            [1.0, 0.0, 0.0], // Same
            [0.9, 0.1, 0.0], // Similar
            [0.5, 0.5, 0.0], // Less similar
            [0.0, 1.0, 0.0], // Orthogonal
        ];

        $result = $service->findMostSimilar($queryVector, $candidates, 2);

        self::assertCount(2, $result);
    }

    #[Test]
    public function findMostSimilarSortsByDescendingSimilarity(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $queryVector = [1.0, 0.0];
        $candidates = [
            [0.0, 1.0],  // Orthogonal (similarity 0)
            [1.0, 0.0],  // Same (similarity 1)
            [0.5, 0.5],  // Partial (similarity ~0.71)
        ];

        $result = $service->findMostSimilar($queryVector, $candidates, 3);

        // First result should have highest similarity
        self::assertGreaterThan($result[1]['similarity'], $result[0]['similarity']);
        self::assertGreaterThan($result[2]['similarity'], $result[1]['similarity']);
    }

    #[Test]
    public function pairwiseSimilaritiesHasDiagonalOfOnes(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vectors = [
            [1.0, 0.0, 0.0],
            [0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        // Diagonal should always be 1.0
        self::assertEquals(1.0, $result[0][0]);
        self::assertEquals(1.0, $result[1][1]);
        self::assertEquals(1.0, $result[2][2]);
    }

    #[Test]
    public function pairwiseSimilaritiesIsSymmetric(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vectors = [
            [1.0, 0.5],
            [0.3, 0.7],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        // Matrix should be symmetric
        self::assertEquals($result[0][1], $result[1][0]);
    }

    #[Test]
    public function normalizeReturnsZeroVectorForZeroInput(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $zeroVector = [0.0, 0.0, 0.0];
        $result = $service->normalize($zeroVector);

        self::assertEquals($zeroVector, $result);
    }

    #[Test]
    public function normalizeCreatesUnitLengthVector(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vector = [3.0, 4.0]; // Magnitude = 5.0
        $result = $service->normalize($vector);

        // Calculate magnitude of result
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $result)));

        self::assertEqualsWithDelta(1.0, $magnitude, 0.0001);
    }

    #[Test]
    public function embedFullWithOptionsUsesProvidedOptions(): void
    {

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $service = new EmbeddingService($llmManagerMock);
        $options = new EmbeddingOptions(model: 'text-embedding-3-large');

        $result = $service->embedFull('test text', $options);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
    }

    #[Test]
    public function embedFullCreatesDefaultOptionsWhenNull(): void
    {

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $service = new EmbeddingService($llmManagerMock);

        // Pass null options
        $result = $service->embedFull('test text', null);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
    }

    #[Test]
    public function embedFullThrowsOnEmptyText(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text cannot be empty');

        $service->embedFull('');
    }

    #[Test]
    public function embedBatchCreatesDefaultOptionsWhenNull(): void
    {

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1], [0.2]]));

        $service = new EmbeddingService($llmManagerMock);

        $result = $service->embedBatch(['text1', 'text2'], null);

        self::assertCount(2, $result);
    }

    // embedding cache behaviour is now owned by `CacheMiddleware` inside
    // `LlmServiceManager::embed()`; coverage lives in
    // `LlmServiceManagerTest` and `CacheMiddlewareTest`.

    /**
     * @param array<int, float> $vectorA
     * @param array<int, float> $vectorB
     */
    #[Test]
    #[DataProvider('cosineSimilarityEdgeCasesProvider')]
    public function cosineSimilarityHandlesEdgeCases(array $vectorA, array $vectorB, float $expected): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $result = $service->cosineSimilarity($vectorA, $vectorB);

        self::assertEqualsWithDelta($expected, $result, 0.0001);
    }

    /**
     * @return array<string, array{array<int, float>, array<int, float>, float}>
     */
    public static function cosineSimilarityEdgeCasesProvider(): array
    {
        return [
            'identical vectors' => [[1.0, 0.0], [1.0, 0.0], 1.0],
            'opposite vectors' => [[1.0, 0.0], [-1.0, 0.0], -1.0],
            'orthogonal vectors' => [[1.0, 0.0], [0.0, 1.0], 0.0],
            'similar vectors' => [[1.0, 0.5], [0.9, 0.6], 0.9923], // Approximately
        ];
    }

    #[Test]
    public function pairwiseSimilaritiesCreatesCorrectDimensions(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vectors = [
            [1.0, 0.0],
            [0.0, 1.0],
            [0.5, 0.5],
            [0.7, 0.3],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        self::assertCount(4, $result);
        foreach ($result as $row) {
            self::assertCount(4, $row);
        }
    }

    #[Test]
    public function findMostSimilarUsesDefaultTopKOfFive(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $queryVector = [1.0, 0.0];
        $candidates = [
            [1.0, 0.0],
            [0.9, 0.1],
            [0.8, 0.2],
            [0.7, 0.3],
            [0.6, 0.4],
            [0.5, 0.5],
            [0.4, 0.6],
        ];

        // Without topK parameter, should return 5 by default
        $result = $service->findMostSimilar($queryVector, $candidates);

        self::assertCount(5, $result);
    }

    #[Test]
    public function findMostSimilarReturnsAllWhenCandidatesLessThanTopK(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $queryVector = [1.0, 0.0];
        $candidates = [
            [1.0, 0.0],
            [0.9, 0.1],
        ];

        // Only 2 candidates, ask for 5
        $result = $service->findMostSimilar($queryVector, $candidates, 5);

        self::assertCount(2, $result);
    }

    #[Test]
    public function findMostSimilarReturnsCorrectIndices(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $queryVector = [1.0, 0.0];
        $candidates = [
            [0.0, 1.0],  // index 0, orthogonal
            [1.0, 0.0],  // index 1, identical
            [0.5, 0.5],  // index 2, partial
        ];

        $result = $service->findMostSimilar($queryVector, $candidates, 3);

        // First result should be index 1 (identical)
        self::assertEquals(1, $result[0]['index']);
    }

    #[Test]
    public function normalizePreservesDirection(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vector = [3.0, 4.0];
        $result = $service->normalize($vector);

        // Normalized vector should have same direction (same ratio)
        self::assertEqualsWithDelta(3 / 5, $result[0], 0.0001);
        self::assertEqualsWithDelta(4 / 5, $result[1], 0.0001);
    }

    #[Test]
    public function normalizeHandlesNegativeValues(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vector = [-3.0, 4.0];
        $result = $service->normalize($vector);

        // Magnitude of result should still be 1.0
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $result)));
        self::assertEqualsWithDelta(1.0, $magnitude, 0.0001);

        // Signs should be preserved
        self::assertLessThan(0, $result[0]);
        self::assertGreaterThan(0, $result[1]);
    }

    #[Test]
    public function pairwiseSimilaritiesReturnsEmptyForEmptyInput(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $result = $service->pairwiseSimilarities([]);

        self::assertEmpty($result);
    }

    #[Test]
    public function pairwiseSimilaritiesHandlesSingleVector(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $vectors = [[1.0, 0.0, 0.0]];

        $result = $service->pairwiseSimilarities($vectors);

        self::assertCount(1, $result);
        self::assertCount(1, $result[0]);
        self::assertEquals(1.0, $result[0][0]);
    }

    #[Test]
    public function embedBatchReturnsEmptyArrayForEmptyInput(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $result = $service->embedBatch([]);

        self::assertEmpty($result);
    }

    // cached-usage-default handling moved to `EmbeddingResponse::fromArray`,
    // covered by response-serialization tests.

    #[Test]
    public function pairwiseSimilaritiesOffDiagonalCalculatesCorrectly(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        // Orthogonal unit vectors
        $vectors = [
            [1.0, 0.0],
            [0.0, 1.0],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        // Off-diagonal elements should be 0 for orthogonal vectors
        self::assertEqualsWithDelta(0.0, $result[0][1], 0.0001);
        self::assertEqualsWithDelta(0.0, $result[1][0], 0.0001);
    }

    #[Test]
    public function findMostSimilarWithTopKOfOne(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub);

        $queryVector = [1.0, 0.0];
        $candidates = [
            [0.0, 1.0],
            [1.0, 0.0],
            [0.5, 0.5],
        ];

        $result = $service->findMostSimilar($queryVector, $candidates, 1);

        self::assertCount(1, $result);
        self::assertEquals(1, $result[0]['index']); // Most similar is index 1
    }
}
