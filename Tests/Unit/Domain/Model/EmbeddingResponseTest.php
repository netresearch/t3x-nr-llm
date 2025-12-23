<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmbeddingResponse::class)]
class EmbeddingResponseTest extends AbstractUnitTestCase
{
    private function createSampleEmbedding(int $dimensions = 1536): array
    {
        return array_map(fn() => (mt_rand() / mt_getrandmax()) * 2 - 1, range(1, $dimensions));
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $embeddings = [$this->createSampleEmbedding(3)];
        $usage = new UsageStatistics(10, 0, 10);

        $response = new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'text-embedding-3-small',
            usage: $usage,
            provider: 'openai',
        );

        $this->assertEquals($embeddings, $response->embeddings);
        $this->assertEquals('text-embedding-3-small', $response->model);
        $this->assertSame($usage, $response->usage);
        $this->assertEquals('openai', $response->provider);
    }

    #[Test]
    public function constructorDefaultsProviderToEmptyString(): void
    {
        $response = new EmbeddingResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            model: 'test-model',
            usage: new UsageStatistics(5, 0, 5),
        );

        $this->assertEquals('', $response->provider);
    }

    #[Test]
    public function getVectorReturnsFirstEmbedding(): void
    {
        $vector1 = [0.1, 0.2, 0.3];
        $vector2 = [0.4, 0.5, 0.6];

        $response = new EmbeddingResponse(
            embeddings: [$vector1, $vector2],
            model: 'test',
            usage: new UsageStatistics(10, 0, 10),
        );

        $this->assertEquals($vector1, $response->getVector());
    }

    #[Test]
    public function getVectorReturnsEmptyArrayWhenNoEmbeddings(): void
    {
        $response = new EmbeddingResponse(
            embeddings: [],
            model: 'test',
            usage: new UsageStatistics(0, 0, 0),
        );

        $this->assertEquals([], $response->getVector());
    }

    #[Test]
    public function getEmbeddingsReturnsAllVectors(): void
    {
        $embeddings = [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
            [0.7, 0.8, 0.9],
        ];

        $response = new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'test',
            usage: new UsageStatistics(30, 0, 30),
        );

        $this->assertEquals($embeddings, $response->getEmbeddings());
        $this->assertCount(3, $response->getEmbeddings());
    }

    #[Test]
    public function getDimensionsReturnsVectorLength(): void
    {
        $vector = $this->createSampleEmbedding(1536);

        $response = new EmbeddingResponse(
            embeddings: [$vector],
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(10, 0, 10),
        );

        $this->assertEquals(1536, $response->getDimensions());
    }

    #[Test]
    public function getDimensionsReturnsZeroWhenEmpty(): void
    {
        $response = new EmbeddingResponse(
            embeddings: [],
            model: 'test',
            usage: new UsageStatistics(0, 0, 0),
        );

        $this->assertEquals(0, $response->getDimensions());
    }

    #[Test]
    public function getCountReturnsNumberOfEmbeddings(): void
    {
        $embeddings = [
            [0.1, 0.2],
            [0.3, 0.4],
            [0.5, 0.6],
            [0.7, 0.8],
        ];

        $response = new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'test',
            usage: new UsageStatistics(40, 0, 40),
        );

        $this->assertEquals(4, $response->getCount());
    }

    #[Test]
    public function normalizeVectorCreatesUnitVector(): void
    {
        $vector = [3.0, 4.0]; // Length = 5

        $response = new EmbeddingResponse(
            embeddings: [$vector],
            model: 'test',
            usage: new UsageStatistics(5, 0, 5),
        );

        $normalized = $response->normalizeVector($vector);

        // Should be [0.6, 0.8] with magnitude 1
        $this->assertEqualsWithDelta(0.6, $normalized[0], 0.0001);
        $this->assertEqualsWithDelta(0.8, $normalized[1], 0.0001);

        // Verify magnitude is 1
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $normalized)));
        $this->assertEqualsWithDelta(1.0, $magnitude, 0.0001);
    }

    #[Test]
    public function normalizeVectorHandlesZeroVector(): void
    {
        $zeroVector = [0.0, 0.0, 0.0];

        $response = new EmbeddingResponse(
            embeddings: [$zeroVector],
            model: 'test',
            usage: new UsageStatistics(5, 0, 5),
        );

        $normalized = $response->normalizeVector($zeroVector);

        $this->assertEquals($zeroVector, $normalized);
    }

    #[Test]
    public function cosineSimilarityReturnsOneForIdenticalVectors(): void
    {
        $vector = [0.5, 0.5, 0.5];

        $similarity = EmbeddingResponse::cosineSimilarity($vector, $vector);

        $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
    }

    #[Test]
    public function cosineSimilarityReturnsMinusOneForOppositeVectors(): void
    {
        $vectorA = [1.0, 0.0, 0.0];
        $vectorB = [-1.0, 0.0, 0.0];

        $similarity = EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);

        $this->assertEqualsWithDelta(-1.0, $similarity, 0.0001);
    }

    #[Test]
    public function cosineSimilarityReturnsZeroForOrthogonalVectors(): void
    {
        $vectorA = [1.0, 0.0];
        $vectorB = [0.0, 1.0];

        $similarity = EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);

        $this->assertEqualsWithDelta(0.0, $similarity, 0.0001);
    }

    #[Test]
    public function cosineSimilarityThrowsForDifferentDimensions(): void
    {
        $vectorA = [0.1, 0.2, 0.3];
        $vectorB = [0.1, 0.2];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Vectors must have the same dimensions');

        EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);
    }

    #[Test]
    public function cosineSimilarityReturnsZeroForZeroVectors(): void
    {
        $zeroVector = [0.0, 0.0, 0.0];
        $vector = [0.1, 0.2, 0.3];

        $similarity = EmbeddingResponse::cosineSimilarity($zeroVector, $vector);

        $this->assertEquals(0.0, $similarity);
    }

    #[Test]
    public function cosineSimilarityCalculatesCorrectlyForRealVectors(): void
    {
        // Test with known vectors
        $vectorA = [1.0, 2.0, 3.0];
        $vectorB = [4.0, 5.0, 6.0];

        // Manual calculation:
        // dot product = 1*4 + 2*5 + 3*6 = 32
        // |A| = sqrt(1 + 4 + 9) = sqrt(14)
        // |B| = sqrt(16 + 25 + 36) = sqrt(77)
        // similarity = 32 / (sqrt(14) * sqrt(77)) ≈ 0.9746

        $similarity = EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);

        $this->assertEqualsWithDelta(0.9746, $similarity, 0.0001);
    }

    #[Test]
    public function worksWithHighDimensionalVectors(): void
    {
        $dimensions = 3072; // text-embedding-3-large
        $embedding = $this->createSampleEmbedding($dimensions);

        $response = new EmbeddingResponse(
            embeddings: [$embedding],
            model: 'text-embedding-3-large',
            usage: new UsageStatistics(100, 0, 100),
        );

        $this->assertEquals($dimensions, $response->getDimensions());
        $this->assertCount($dimensions, $response->getVector());
    }

    #[Test]
    public function batchEmbeddingsAreStoredCorrectly(): void
    {
        $texts = ['text1', 'text2', 'text3', 'text4', 'text5'];
        $embeddings = array_map(fn() => $this->createSampleEmbedding(256), $texts);

        $response = new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'test',
            usage: new UsageStatistics(50, 0, 50),
        );

        $this->assertEquals(5, $response->getCount());
        $this->assertEquals(256, $response->getDimensions());
    }
}
