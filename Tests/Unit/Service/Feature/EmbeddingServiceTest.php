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
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmbeddingService::class)]
class EmbeddingServiceTest extends AbstractUnitTestCase
{
    private EmbeddingService $subject;
    private LlmServiceManagerInterface $llmManagerStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $this->subject = new EmbeddingService($this->llmManagerStub);
    }

    #[Test]
    public function embedReturnsVectorArray(): void
    {
        $text = 'Test text';
        $expectedVector = [0.1, 0.2, 0.3];

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([$expectedVector]));

        $subject = new EmbeddingService($llmManagerMock);
        $result = $subject->embed($text);

        self::assertEquals($expectedVector, $result);
    }

    #[Test]
    public function embedDelegatesToLlmServiceManager(): void
    {
        // Caching is now handled transparently by CacheMiddleware inside
        // LlmServiceManager::embed() — the feature service just forwards.
        // See ADR-026 for the pipeline architecture.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('embed')
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $subject = new EmbeddingService($llmManagerMock);
        $subject->embed('Text');
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

        $subject = new EmbeddingService($llmManagerMock);
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

    #[Test]
    public function embedFullAutoPopulatesBeUserUidFromResolver(): void
    {
        // REC #4 slice 15b: identical wiring to slice 15a's
        // CompletionService — the resolver fills `beUserUid` only
        // when the caller did not set one.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveBeUserUid')
            ->willReturn(42);

        $subject = new EmbeddingService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('embed')
            ->with(
                'hello',
                self::callback(static fn(EmbeddingOptions $options): bool
                    => $options->getBeUserUid() === 42),
            )
            ->willReturn($this->createMockEmbeddingResponse([[0.1, 0.2]]));

        $subject->embedFull('hello');
    }

    #[Test]
    public function embedFullRespectsExplicitBeUserUidOverResolver(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::never())
            ->method('resolveBeUserUid');

        $subject = new EmbeddingService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('embed')
            ->with(
                'hello',
                self::callback(static fn(EmbeddingOptions $options): bool
                    => $options->getBeUserUid() === 99),
            )
            ->willReturn($this->createMockEmbeddingResponse([[0.1]]));

        $subject->embedFull('hello', (new EmbeddingOptions())->withBeUserUid(99));
    }

    #[Test]
    public function embedBatchAutoPopulatesBeUserUidFromResolver(): void
    {
        // The batch path takes its own option-construction route
        // (`?? new EmbeddingOptions()`); explicit coverage so the
        // resolver hook is not silently bypassed for batch callers.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveBeUserUid')
            ->willReturn(7);

        $subject = new EmbeddingService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('embed')
            ->with(
                ['a', 'b'],
                self::callback(static fn(EmbeddingOptions $options): bool
                    => $options->getBeUserUid() === 7),
            )
            ->willReturn($this->createMockEmbeddingResponse([[0.1], [0.2]]));

        $subject->embedBatch(['a', 'b']);
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
