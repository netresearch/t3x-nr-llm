<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for EmbeddingService
 */
class EmbeddingServiceTest extends TestCase
{
    private EmbeddingService $subject;
    private LlmServiceManager|MockObject $llmManagerMock;
    private CacheManager|MockObject $cacheMock;

    protected function setUp(): void
    {
        $this->llmManagerMock = $this->createMock(LlmServiceManager::class);
        $this->cacheMock = $this->createMock(CacheManager::class);
        $this->subject = new EmbeddingService($this->llmManagerMock, $this->cacheMock);
    }

    /**
     * @test
     */
    public function embedReturnsVectorArray(): void
    {
        $text = 'Test text';
        $expectedVector = [0.1, 0.2, 0.3];

        $this->cacheMock
            ->method('get')
            ->willReturn(null);

        $this->llmManagerMock
            ->expects($this->once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse($expectedVector));

        $result = $this->subject->embed($text);

        $this->assertEquals($expectedVector, $result);
    }

    /**
     * @test
     */
    public function embedUsesCachedResult(): void
    {
        $text = 'Cached text';
        $cachedVector = [0.5, 0.6, 0.7];

        $cachedResponse = new EmbeddingResponse(
            vector: $cachedVector,
            dimensions: 3,
            usage: new \Netresearch\NrLlm\Domain\Model\UsageStatistics(0, 0, 0)
        );

        $this->cacheMock
            ->expects($this->once())
            ->method('get')
            ->willReturn($cachedResponse);

        $this->llmManagerMock
            ->expects($this->never())
            ->method('embed');

        $result = $this->subject->embed($text);

        $this->assertEquals($cachedVector, $result);
    }

    /**
     * @test
     */
    public function embedStoresResultInCache(): void
    {
        $text = 'New text';
        $vector = [0.1, 0.2];

        $this->cacheMock
            ->method('get')
            ->willReturn(null);

        $this->cacheMock
            ->expects($this->once())
            ->method('set')
            ->with(
                $this->isType('string'),
                $this->isInstanceOf(EmbeddingResponse::class),
                86400
            );

        $this->llmManagerMock
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse($vector));

        $this->subject->embed($text);
    }

    /**
     * @test
     */
    public function embedBatchProcessesMultipleTexts(): void
    {
        $texts = ['Text 1', 'Text 2', 'Text 3'];
        $vectors = [[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]];

        $this->cacheMock
            ->method('get')
            ->willReturn(null);

        $this->llmManagerMock
            ->expects($this->once())
            ->method('embedBatch')
            ->willReturn($this->createMockBatchEmbeddingResponse($vectors));

        $results = $this->subject->embedBatch($texts);

        $this->assertCount(3, $results);
        $this->assertEquals($vectors, $results);
    }

    /**
     * @test
     */
    public function embedBatchUsesCachedResultsWhenAvailable(): void
    {
        $texts = ['Cached', 'New'];
        $cachedVector = [0.1, 0.2];
        $newVector = [0.3, 0.4];

        $cachedResponse = new EmbeddingResponse(
            vector: $cachedVector,
            dimensions: 2,
            usage: new \Netresearch\NrLlm\Domain\Model\UsageStatistics(0, 0, 0)
        );

        $this->cacheMock
            ->method('get')
            ->willReturnCallback(function ($key) use ($cachedResponse) {
                return str_contains($key, 'Cached') ? $cachedResponse : null;
            });

        $this->llmManagerMock
            ->expects($this->once())
            ->method('embedBatch')
            ->with($this->callback(function ($options) {
                $this->assertCount(1, $options['input']);
                $this->assertEquals('New', $options['input'][0]);
                return true;
            }))
            ->willReturn($this->createMockBatchEmbeddingResponse([$newVector]));

        $results = $this->subject->embedBatch($texts);

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function cosineSimilarityCalculatesCorrectly(): void
    {
        $vectorA = [1.0, 0.0, 0.0];
        $vectorB = [1.0, 0.0, 0.0];

        $similarity = $this->subject->cosineSimilarity($vectorA, $vectorB);

        $this->assertEquals(1.0, $similarity);
    }

    /**
     * @test
     */
    public function cosineSimilarityHandlesOrthogonalVectors(): void
    {
        $vectorA = [1.0, 0.0];
        $vectorB = [0.0, 1.0];

        $similarity = $this->subject->cosineSimilarity($vectorA, $vectorB);

        $this->assertEquals(0.0, $similarity);
    }

    /**
     * @test
     */
    public function cosineSimilarityThrowsOnDimensionMismatch(): void
    {
        $vectorA = [1.0, 2.0];
        $vectorB = [1.0, 2.0, 3.0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('same dimensions');

        $this->subject->cosineSimilarity($vectorA, $vectorB);
    }

    /**
     * @test
     */
    public function findMostSimilarReturnsTopK(): void
    {
        $queryVector = [1.0, 0.0];
        $candidates = [
            [0.9, 0.1],  // Similar
            [0.0, 1.0],  // Orthogonal
            [1.0, 0.0],  // Identical
            [-1.0, 0.0], // Opposite
        ];

        $results = $this->subject->findMostSimilar($queryVector, $candidates, 2);

        $this->assertCount(2, $results);
        $this->assertEquals(2, $results[0]['index']); // Most similar
        $this->assertEquals(0, $results[1]['index']); // Second most similar
        $this->assertGreaterThan($results[1]['similarity'], $results[0]['similarity']);
    }

    /**
     * @test
     */
    public function normalizeCreatesUnitVector(): void
    {
        $vector = [3.0, 4.0]; // Magnitude = 5.0
        $normalized = $this->subject->normalize($vector);

        $this->assertEquals(0.6, $normalized[0], '', 0.001);
        $this->assertEquals(0.8, $normalized[1], '', 0.001);

        // Verify magnitude is 1.0
        $magnitude = sqrt($normalized[0] ** 2 + $normalized[1] ** 2);
        $this->assertEquals(1.0, $magnitude, '', 0.001);
    }

    /**
     * @test
     */
    public function embedThrowsOnEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text cannot be empty');

        $this->subject->embed('');
    }

    /**
     * @test
     */
    public function pairwiseSimilaritiesCreatesMatrix(): void
    {
        $vectors = [
            [1.0, 0.0],
            [0.0, 1.0],
            [1.0, 0.0],
        ];

        $similarities = $this->subject->pairwiseSimilarities($vectors);

        $this->assertCount(3, $similarities);
        $this->assertCount(3, $similarities[0]);

        // Diagonal should be 1.0
        $this->assertEquals(1.0, $similarities[0][0]);
        $this->assertEquals(1.0, $similarities[1][1]);
        $this->assertEquals(1.0, $similarities[2][2]);

        // Vector 0 and 2 are identical
        $this->assertEquals(1.0, $similarities[0][2]);
        $this->assertEquals(1.0, $similarities[2][0]);

        // Vector 0 and 1 are orthogonal
        $this->assertEquals(0.0, $similarities[0][1]);
    }

    /**
     * Create mock embedding response
     */
    private function createMockEmbeddingResponse(array $vector): object
    {
        return new class($vector) {
            public function __construct(private array $vector) {}

            public function getVector(): array
            {
                return $this->vector;
            }

            public function getUsage(): array
            {
                return [
                    'prompt_tokens' => 5,
                    'estimated_cost' => 0.0001,
                ];
            }

            public function getModel(): ?string
            {
                return 'text-embedding-3-small';
            }
        };
    }

    /**
     * Create mock batch embedding response
     */
    private function createMockBatchEmbeddingResponse(array $vectors): object
    {
        return new class($vectors) {
            public function __construct(private array $vectors) {}

            public function getVectors(): array
            {
                return $this->vectors;
            }

            public function getModel(): ?string
            {
                return 'text-embedding-3-small';
            }
        };
    }
}
