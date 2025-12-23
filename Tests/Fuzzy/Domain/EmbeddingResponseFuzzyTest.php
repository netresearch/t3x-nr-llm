<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for EmbeddingResponse
 */
#[CoversClass(EmbeddingResponse::class)]
class EmbeddingResponseFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function cosineSimilarityOfIdenticalVectorsIsOne(): void
    {
        $this
            ->forAll($this->embeddingVector(50))
            ->then(function (array $vector) {
                // Normalize to avoid floating point issues with very small values
                $normalizedVector = $this->normalizeVector($vector);
                $similarity = EmbeddingResponse::cosineSimilarity($normalizedVector, $normalizedVector);
                $this->assertEqualsWithDelta(1.0, $similarity, 0.001);
            });
    }

    #[Test]
    public function cosineSimilarityIsCommutative(): void
    {
        $this
            ->forAll(
                $this->embeddingVector(50),
                $this->embeddingVector(50)
            )
            ->then(function (array $vectorA, array $vectorB) {
                $similarityAB = EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);
                $similarityBA = EmbeddingResponse::cosineSimilarity($vectorB, $vectorA);
                $this->assertEqualsWithDelta($similarityAB, $similarityBA, 0.0001);
            });
    }

    #[Test]
    public function cosineSimilarityIsBetweenMinusOneAndOne(): void
    {
        $this
            ->forAll(
                $this->embeddingVector(50),
                $this->embeddingVector(50)
            )
            ->then(function (array $vectorA, array $vectorB) {
                $similarity = EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);
                $this->assertGreaterThanOrEqual(-1.0, $similarity);
                $this->assertLessThanOrEqual(1.0, $similarity);
            });
    }

    #[Test]
    public function embeddingResponsePreservesData(): void
    {
        $this
            ->forAll(
                $this->embeddingVector(50),
                Generator\suchThat(
                    static fn(string $s) => strlen(trim($s)) > 0 && strlen($s) < 100,
                    Generator\string()
                ),
                Generator\pos(),
                Generator\pos()
            )
            ->then(function (array $embedding, string $model, int $promptTokens, int $totalTokens) {
                $usage = new UsageStatistics(
                    promptTokens: $promptTokens,
                    completionTokens: 0,
                    totalTokens: $totalTokens
                );

                $response = new EmbeddingResponse(
                    embeddings: [$embedding],
                    model: $model,
                    usage: $usage,
                    provider: 'test'
                );

                $this->assertCount(1, $response->embeddings);
                $this->assertEquals($embedding, $response->getVector());
                $this->assertEquals($model, $response->model);
                $this->assertEquals($promptTokens, $response->usage->promptTokens);
            });
    }

    #[Test]
    public function getVectorReturnsFirstEmbedding(): void
    {
        $this
            ->forAll(
                $this->embeddingVector(50),
                $this->embeddingVector(50)
            )
            ->then(function (array $embedding1, array $embedding2) {
                $usage = new UsageStatistics(10, 0, 10);
                $response = new EmbeddingResponse(
                    embeddings: [$embedding1, $embedding2],
                    model: 'test-model',
                    usage: $usage,
                    provider: 'test'
                );

                // getVector should always return the first embedding
                $this->assertEquals($embedding1, $response->getVector());
            });
    }

    #[Test]
    public function multipleEmbeddingsArePreserved(): void
    {
        $this
            ->forAll(
                $this->embeddingVector(25),
                $this->embeddingVector(25),
                $this->embeddingVector(25)
            )
            ->then(function (array $emb1, array $emb2, array $emb3) {
                $usage = new UsageStatistics(10, 0, 10);
                $response = new EmbeddingResponse(
                    embeddings: [$emb1, $emb2, $emb3],
                    model: 'test-model',
                    usage: $usage,
                    provider: 'test'
                );

                $this->assertCount(3, $response->embeddings);
                $this->assertEquals($emb1, $response->embeddings[0]);
                $this->assertEquals($emb2, $response->embeddings[1]);
                $this->assertEquals($emb3, $response->embeddings[2]);
            });
    }

    #[Test]
    public function cosineSimilarityOfOppositeVectorsIsNegativeOne(): void
    {
        $this
            ->forAll($this->embeddingVector(50))
            ->then(function (array $vector) {
                // Normalize vector
                $normalizedVector = $this->normalizeVector($vector);
                // Create opposite vector (negate all elements)
                $oppositeVector = array_map(static fn($x) => -$x, $normalizedVector);

                $similarity = EmbeddingResponse::cosineSimilarity($normalizedVector, $oppositeVector);
                $this->assertEqualsWithDelta(-1.0, $similarity, 0.001);
            });
    }

    /**
     * Normalize vector to unit length
     *
     * @param array<float> $vector
     * @return array<float>
     */
    private function normalizeVector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn($x) => $x * $x, $vector)));
        if ($magnitude == 0) {
            return $vector;
        }
        return array_map(static fn($x) => $x / $magnitude, $vector);
    }
}
