<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Additional mutation-killing tests for EmbeddingService.
 */
#[CoversClass(EmbeddingService::class)]
class EmbeddingServiceMutationTest extends AbstractUnitTestCase
{
    private function createMockEmbeddingResponse(array $embeddings): EmbeddingResponse
    {
        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(
                promptTokens: 5,
                completionTokens: 0,
                totalTokens: 5
            ),
            provider: 'openai',
        );
    }

    #[Test]
    public function findMostSimilarReturnsEmptyArrayForEmptyCandidates(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $result = $service->findMostSimilar([0.1, 0.2, 0.3], [], 5);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function findMostSimilarReturnsLimitedResults(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $queryVector = [1.0, 0.0, 0.0];
        $candidates = [
            [1.0, 0.0, 0.0], // Same
            [0.9, 0.1, 0.0], // Similar
            [0.5, 0.5, 0.0], // Less similar
            [0.0, 1.0, 0.0], // Orthogonal
        ];

        $result = $service->findMostSimilar($queryVector, $candidates, 2);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function findMostSimilarSortsByDescendingSimilarity(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $queryVector = [1.0, 0.0];
        $candidates = [
            [0.0, 1.0],  // Orthogonal (similarity 0)
            [1.0, 0.0],  // Same (similarity 1)
            [0.5, 0.5],  // Partial (similarity ~0.71)
        ];

        $result = $service->findMostSimilar($queryVector, $candidates, 3);

        // First result should have highest similarity
        $this->assertGreaterThan($result[1]['similarity'], $result[0]['similarity']);
        $this->assertGreaterThan($result[2]['similarity'], $result[1]['similarity']);
    }

    #[Test]
    public function pairwiseSimilaritiesHasDiagonalOfOnes(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $vectors = [
            [1.0, 0.0, 0.0],
            [0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        // Diagonal should always be 1.0
        $this->assertEquals(1.0, $result[0][0]);
        $this->assertEquals(1.0, $result[1][1]);
        $this->assertEquals(1.0, $result[2][2]);
    }

    #[Test]
    public function pairwiseSimilaritiesIsSymmetric(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $vectors = [
            [1.0, 0.5],
            [0.3, 0.7],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        // Matrix should be symmetric
        $this->assertEquals($result[0][1], $result[1][0]);
    }

    #[Test]
    public function normalizeReturnsZeroVectorForZeroInput(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $zeroVector = [0.0, 0.0, 0.0];
        $result = $service->normalize($zeroVector);

        $this->assertEquals($zeroVector, $result);
    }

    #[Test]
    public function normalizeCreatesUnitLengthVector(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $vector = [3.0, 4.0]; // Magnitude = 5.0
        $result = $service->normalize($vector);

        // Calculate magnitude of result
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $result)));

        $this->assertEqualsWithDelta(1.0, $magnitude, 0.0001);
    }

    #[Test]
    public function embedFullWithOptionsUsesProvidedOptions(): void
    {
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $cacheStub->method('getCachedEmbeddings')->willReturn(null);

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $service = new EmbeddingService($llmManagerMock, $cacheStub);
        $options = new EmbeddingOptions(model: 'text-embedding-3-large');

        $result = $service->embedFull('test text', $options);

        $this->assertInstanceOf(EmbeddingResponse::class, $result);
    }

    #[Test]
    public function embedFullCreatesDefaultOptionsWhenNull(): void
    {
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $cacheStub->method('getCachedEmbeddings')->willReturn(null);

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $service = new EmbeddingService($llmManagerMock, $cacheStub);

        // Pass null options
        $result = $service->embedFull('test text', null);

        $this->assertInstanceOf(EmbeddingResponse::class, $result);
    }

    #[Test]
    public function embedFullThrowsOnEmptyText(): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text cannot be empty');

        $service->embedFull('');
    }

    #[Test]
    public function embedBatchCreatesDefaultOptionsWhenNull(): void
    {
        $cacheStub = $this->createStub(CacheManagerInterface::class);

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1], [0.2]]));

        $service = new EmbeddingService($llmManagerMock, $cacheStub);

        $result = $service->embedBatch(['text1', 'text2'], null);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function embedFullCachesResultAfterApiCall(): void
    {
        $cacheMock = $this->createMock(CacheManagerInterface::class);
        $cacheMock->method('getCachedEmbeddings')->willReturn(null);
        $cacheMock
            ->expects($this->once())
            ->method('cacheEmbeddings');

        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $llmManagerStub
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $service = new EmbeddingService($llmManagerStub, $cacheMock);
        $service->embedFull('test text');
    }

    #[Test]
    public function embedFullUsesDefaultCacheTtlOf24Hours(): void
    {
        $cacheMock = $this->createMock(CacheManagerInterface::class);
        $cacheMock->method('getCachedEmbeddings')->willReturn(null);
        $cacheMock
            ->expects($this->once())
            ->method('cacheEmbeddings')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                86400 // 24 hours
            );

        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $llmManagerStub
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $service = new EmbeddingService($llmManagerStub, $cacheMock);
        $service->embedFull('test text');
    }

    #[Test]
    public function embedFullReturnsCachedResponseWhenAvailable(): void
    {
        $cachedData = [
            'embeddings' => [[0.5, 0.6]],
            'model' => 'cached-model',
            'usage' => ['promptTokens' => 10, 'totalTokens' => 10],
        ];

        $cacheMock = $this->createMock(CacheManagerInterface::class);
        $cacheMock
            ->expects($this->once())
            ->method('getCachedEmbeddings')
            ->willReturn($cachedData);

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->never())
            ->method('embed');

        $service = new EmbeddingService($llmManagerMock, $cacheMock);
        $result = $service->embedFull('test text');

        $this->assertEquals([[0.5, 0.6]], $result->embeddings);
        $this->assertEquals('cached-model', $result->model);
    }

    #[Test]
    #[DataProvider('cosineSimilarityEdgeCasesProvider')]
    public function cosineSimilarityHandlesEdgeCases(array $vectorA, array $vectorB, float $expected): void
    {
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $result = $service->cosineSimilarity($vectorA, $vectorB);

        $this->assertEqualsWithDelta($expected, $result, 0.0001);
    }

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
        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $cacheStub = $this->createStub(CacheManagerInterface::class);
        $service = new EmbeddingService($llmManagerStub, $cacheStub);

        $vectors = [
            [1.0, 0.0],
            [0.0, 1.0],
            [0.5, 0.5],
            [0.7, 0.3],
        ];

        $result = $service->pairwiseSimilarities($vectors);

        $this->assertCount(4, $result);
        foreach ($result as $row) {
            $this->assertCount(4, $row);
        }
    }
}
