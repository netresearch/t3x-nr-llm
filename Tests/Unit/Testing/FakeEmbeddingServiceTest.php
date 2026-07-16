<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Testing;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\EmbeddingServiceInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Testing\FakeEmbeddingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeEmbeddingService::class)]
final class FakeEmbeddingServiceTest extends TestCase
{
    #[Test]
    public function implementsTheRealInterface(): void
    {
        self::assertInstanceOf(EmbeddingServiceInterface::class, new FakeEmbeddingService());
    }

    #[Test]
    public function embedReturnsCannedVectorAndRecordsTheCall(): void
    {
        $options = new EmbeddingOptions();

        $subject = new FakeEmbeddingService();
        $subject->embedResult = [0.5, 0.6];

        self::assertSame([0.5, 0.6], $subject->embed('hello', $options));
        self::assertCount(1, $subject->embedCalls);
        self::assertSame('hello', $subject->embedCalls[0]['text']);
        self::assertSame($options, $subject->embedCalls[0]['options']);
    }

    #[Test]
    public function embedBatchReturnsCannedVectorsAndRecordsTheCall(): void
    {
        $subject = new FakeEmbeddingService();
        $subject->embedBatchResult = [[1.0], [2.0]];

        self::assertSame([[1.0], [2.0]], $subject->embedBatch(['a', 'b']));
        self::assertSame(['a', 'b'], $subject->embedBatchCalls[0]['texts']);
    }

    #[Test]
    public function embedForConfigurationRecordsTheConfiguration(): void
    {
        $configuration = new LlmConfiguration();

        $subject = new FakeEmbeddingService();
        $subject->embedResult = [0.7];

        self::assertSame([0.7], $subject->embedForConfiguration('text', $configuration));
        self::assertSame($configuration, $subject->embedForConfigurationCalls[0]['configuration']);
    }

    #[Test]
    public function embedBatchForConfigurationRecordsTheConfiguration(): void
    {
        $configuration = new LlmConfiguration();

        $subject = new FakeEmbeddingService();
        $subject->embedBatchResult = [[0.8]];

        self::assertSame([[0.8]], $subject->embedBatchForConfiguration(['text'], $configuration));
        self::assertSame($configuration, $subject->embedBatchForConfigurationCalls[0]['configuration']);
    }

    #[Test]
    public function embedFullBuildsADefaultResponseWrappingTheCannedVector(): void
    {
        $subject = new FakeEmbeddingService();
        $subject->embedResult = [0.1, 0.9];

        $response = $subject->embedFull('text');

        self::assertSame([[0.1, 0.9]], $response->embeddings);
        self::assertCount(1, $subject->embedFullCalls);
    }

    #[Test]
    public function embedFullReturnsTheConfiguredResponseWhenSet(): void
    {
        $canned = new EmbeddingResponse([[0.3]], 'custom-model', new UsageStatistics(1, 0, 1));

        $subject = new FakeEmbeddingService();
        $subject->embedFullResult = $canned;

        self::assertSame($canned, $subject->embedFull('text'));
    }

    #[Test]
    public function throwsConfiguredThrowableFromProviderBackedCalls(): void
    {
        $subject = new FakeEmbeddingService();
        $subject->throwable = new RuntimeException('embed failed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('embed failed');

        $subject->embed('text');
    }

    #[Test]
    public function vectorHelpersReturnTheirCannedValues(): void
    {
        $subject = new FakeEmbeddingService();
        $subject->cosineSimilarityResult    = 0.42;
        $subject->findMostSimilarResult     = [['index' => 0, 'similarity' => 0.42]];
        $subject->pairwiseSimilaritiesResult = [[1.0]];

        self::assertSame(0.42, $subject->cosineSimilarity([1.0], [1.0]));
        self::assertSame([['index' => 0, 'similarity' => 0.42]], $subject->findMostSimilar([1.0], [[1.0]]));
        self::assertSame([[1.0]], $subject->pairwiseSimilarities([[1.0]]));
    }

    #[Test]
    public function normalizeReturnsTheInputVectorUnlessOverridden(): void
    {
        $subject = new FakeEmbeddingService();
        self::assertSame([1.0, 2.0], $subject->normalize([1.0, 2.0]));

        $subject->normalizeResult = [0.0, 1.0];
        self::assertSame([0.0, 1.0], $subject->normalize([1.0, 2.0]));
    }
}
