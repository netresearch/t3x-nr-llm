<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmbeddingService::class)]
class EmbeddingServiceTest extends AbstractUnitTestCase
{
    private EmbeddingService $subject;
    private LlmServiceManagerInterface $llmManagerStub;
    private CacheManagerInterface $cacheStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $this->cacheStub = self::createStub(CacheManagerInterface::class);
        $this->subject = new EmbeddingService($this->llmManagerStub, $this->cacheStub);
    }

    #[Test]
    public function embedReturnsVectorArray(): void
    {
        $text = 'Test text';
        $expectedVector = [0.1, 0.2, 0.3];

        $cacheStub = self::createStub(CacheManagerInterface::class);
        $cacheStub->method('getCachedEmbeddings')->willReturn(null);

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([$expectedVector]));

        $subject = new EmbeddingService($llmManagerMock, $cacheStub);
        $result = $subject->embed($text);

        self::assertEquals($expectedVector, $result);
    }

    #[Test]
    public function embedUsesCachedResult(): void
    {
        $text = 'Cached text';
        $cachedVector = [0.5, 0.6, 0.7];

        $cacheMock = $this->createMock(CacheManagerInterface::class);
        $cacheMock
            ->expects(self::once())
            ->method('getCachedEmbeddings')
            ->willReturn([
                'embeddings' => [$cachedVector],
                'model' => 'text-embedding-3-small',
                'usage' => ['promptTokens' => 5, 'totalTokens' => 5],
            ]);

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::never())
            ->method('embed');

        $subject = new EmbeddingService($llmManagerMock, $cacheMock);
        $result = $subject->embed($text);

        self::assertEquals($cachedVector, $result);
    }

    #[Test]
    public function embedStoresResultInCache(): void
    {
        $text = 'New text';
        $vector = [0.1, 0.2];

        $cacheMock = $this->createMock(CacheManagerInterface::class);
        $cacheMock->method('getCachedEmbeddings')->willReturn(null);
        $cacheMock->expects(self::once())->method('cacheEmbeddings');

        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $llmManagerStub->method('embed')->willReturn($this->createMockEmbeddingResponse([$vector]));

        $subject = new EmbeddingService($llmManagerStub, $cacheMock);
        $subject->embed($text);
    }

    #[Test]
    public function embedBatchProcessesMultipleTexts(): void
    {
        $texts = ['Text 1', 'Text 2', 'Text 3'];
        $vectors = [[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]];

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse($vectors));

        $subject = new EmbeddingService($llmManagerMock, $this->cacheStub);
        $results = $subject->embedBatch($texts);

        self::assertCount(3, $results);
        self::assertEquals($vectors, $results);
    }

    #[Test]
    public function cosineSimilarityCalculatesCorrectly(): void
    {
        $vectorA = [1.0, 0.0, 0.0];
        $vectorB = [1.0, 0.0, 0.0];

        $similarity = $this->subject->cosineSimilarity($vectorA, $vectorB);

        self::assertEquals(1.0, $similarity);
    }

    #[Test]
    public function cosineSimilarityHandlesOrthogonalVectors(): void
    {
        $vectorA = [1.0, 0.0];
        $vectorB = [0.0, 1.0];

        $similarity = $this->subject->cosineSimilarity($vectorA, $vectorB);

        self::assertEquals(0.0, $similarity);
    }

    #[Test]
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

        self::assertCount(2, $results);
        self::assertEquals(2, $results[0]['index']); // Most similar
        self::assertEquals(0, $results[1]['index']); // Second most similar
        self::assertGreaterThan($results[1]['similarity'], $results[0]['similarity']);
    }

    #[Test]
    public function normalizeCreatesUnitVector(): void
    {
        $vector = [3.0, 4.0]; // Magnitude = 5.0
        $normalized = $this->subject->normalize($vector);

        self::assertEqualsWithDelta(0.6, $normalized[0], 0.001);
        self::assertEqualsWithDelta(0.8, $normalized[1], 0.001);

        // Verify magnitude is 1.0
        $magnitude = sqrt($normalized[0] ** 2 + $normalized[1] ** 2);
        self::assertEqualsWithDelta(1.0, $magnitude, 0.001);
    }

    #[Test]
    public function embedThrowsOnEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text cannot be empty');

        $this->subject->embed('');
    }

    #[Test]
    public function pairwiseSimilaritiesCreatesMatrix(): void
    {
        $vectors = [
            [1.0, 0.0],
            [0.0, 1.0],
            [1.0, 0.0],
        ];

        $similarities = $this->subject->pairwiseSimilarities($vectors);

        self::assertCount(3, $similarities);
        self::assertCount(3, $similarities[0]);

        // Diagonal should be 1.0
        self::assertEquals(1.0, $similarities[0][0]);
        self::assertEquals(1.0, $similarities[1][1]);
        self::assertEquals(1.0, $similarities[2][2]);

        // Vector 0 and 2 are identical
        self::assertEquals(1.0, $similarities[0][2]);
        self::assertEquals(1.0, $similarities[2][0]);

        // Vector 0 and 1 are orthogonal
        self::assertEquals(0.0, $similarities[0][1]);
    }

    #[Test]
    public function embedBatchReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->subject->embedBatch([]);

        self::assertSame([], $result);
    }

    /**
     * Create mock EmbeddingResponse.
     *
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
}
