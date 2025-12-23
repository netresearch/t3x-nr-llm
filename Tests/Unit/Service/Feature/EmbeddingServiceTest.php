<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for EmbeddingService
 */
class EmbeddingServiceTest extends TestCase
{
    private EmbeddingService $subject;
    private LlmServiceManager&MockObject $llmManagerMock;
    private CacheManager&MockObject $cacheMock;

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
            ->method('getCachedEmbeddings')
            ->willReturn(null);

        $this->llmManagerMock
            ->expects($this->once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([$expectedVector]));

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

        $this->cacheMock
            ->expects($this->once())
            ->method('getCachedEmbeddings')
            ->willReturn([
                'embeddings' => [$cachedVector],
                'model' => 'text-embedding-3-small',
                'usage' => ['promptTokens' => 5, 'totalTokens' => 5],
            ]);

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
            ->method('getCachedEmbeddings')
            ->willReturn(null);

        $this->cacheMock
            ->expects($this->once())
            ->method('cacheEmbeddings');

        $this->llmManagerMock
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([$vector]));

        $this->subject->embed($text);
    }

    /**
     * @test
     */
    public function embedBatchProcessesMultipleTexts(): void
    {
        $texts = ['Text 1', 'Text 2', 'Text 3'];
        $vectors = [[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]];

        $this->llmManagerMock
            ->expects($this->once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse($vectors));

        $results = $this->subject->embedBatch($texts);

        $this->assertCount(3, $results);
        $this->assertEquals($vectors, $results);
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

        $this->assertEqualsWithDelta(0.6, $normalized[0], 0.001);
        $this->assertEqualsWithDelta(0.8, $normalized[1], 0.001);

        // Verify magnitude is 1.0
        $magnitude = sqrt($normalized[0] ** 2 + $normalized[1] ** 2);
        $this->assertEqualsWithDelta(1.0, $magnitude, 0.001);
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
     * @test
     */
    public function embedBatchReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->subject->embedBatch([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Create mock EmbeddingResponse
     */
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
}
