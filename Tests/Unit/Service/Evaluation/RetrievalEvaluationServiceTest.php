<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\RetrievalEvaluationService;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticRetriever;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetrievalEvaluationService::class)]
final class RetrievalEvaluationServiceTest extends TestCase
{
    private function set(GoldenQuestion ...$questions): GoldenQuestionSet
    {
        return new GoldenQuestionSet('a.set', 'Set', 'Description', array_values($questions));
    }

    /**
     * A retriever that, unlike StaticRetriever, truncates its ranking to
     * the requested limit — mirroring a real backend, where only the
     * overfetch makes raw results beyond the metric depth reachable.
     *
     * @param list<string> $ranking
     */
    private function limitRespectingRetriever(array $ranking): EvaluatableRetrieverInterface
    {
        return new class ($ranking) implements EvaluatableRetrieverInterface {
            /**
             * @param list<string> $ranking
             */
            public function __construct(private readonly array $ranking) {}

            public function getIdentifier(): string
            {
                return 'test.limit_respecting';
            }

            public function retrieve(string $question, int $limit): array
            {
                return array_slice($this->ranking, 0, $limit);
            }
        };
    }

    #[Test]
    public function topRankedTargetIsATop1AndTop3Hit(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-a'])),
            new StaticRetriever(defaultRanking: ['doc-a', 'doc-b', 'doc-c']),
        );

        self::assertTrue($result->evaluations[0]->top1Hit);
        self::assertTrue($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function thirdRankedTargetIsOnlyATop3Hit(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-c'])),
            new StaticRetriever(defaultRanking: ['doc-a', 'doc-b', 'doc-c']),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertTrue($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function fourthRankedTargetIsAMissOnBothMetrics(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-d'])),
            new StaticRetriever(defaultRanking: ['doc-a', 'doc-b', 'doc-c', 'doc-d']),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertFalse($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function anyOfSeveralTargetDocumentsCountsAsAHit(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-x', 'doc-b'])),
            new StaticRetriever(defaultRanking: ['doc-a', 'doc-b', 'doc-c']),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertTrue($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function duplicateDocumentIdsCollapseToDistinctRanks(): void
    {
        // Four chunks mapping to two documents: top-3 must still reach doc-b.
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-b'])),
            new StaticRetriever(defaultRanking: ['doc-a', 'doc-a', 'doc-a', 'doc-b']),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertTrue($result->evaluations[0]->top3Hit);
        self::assertSame(['doc-a', 'doc-b'], $result->evaluations[0]->retrievedDocumentIds);
    }

    #[Test]
    public function emptyDocumentIdsInTheRankingAreIgnored(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-a'])),
            new StaticRetriever(defaultRanking: ['', 'doc-a']),
        );

        self::assertTrue($result->evaluations[0]->top1Hit);
        self::assertSame(['doc-a'], $result->evaluations[0]->retrievedDocumentIds);
    }

    #[Test]
    public function retrievedDocumentsAreCappedAtTopK(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-a'])),
            new StaticRetriever(defaultRanking: ['doc-a', 'doc-b', 'doc-c', 'doc-d', 'doc-e']),
        );

        self::assertCount(RetrievalEvaluationService::TOP_K, $result->evaluations[0]->retrievedDocumentIds);
    }

    #[Test]
    public function emptyRetrievalOnAQuestionWithTargetsMissesBothMetrics(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::GAP, ['doc-a'])),
            new StaticRetriever(),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertFalse($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function noResultQuestionScoresAsHitOnCorrectEmptyResult(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Is there a moon base?', QuestionForm::GAP, [])),
            new StaticRetriever(),
        );

        self::assertTrue($result->evaluations[0]->top1Hit);
        self::assertTrue($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function noResultQuestionScoresAsMissWhenAnythingIsRetrieved(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Is there a moon base?', QuestionForm::GAP, [])),
            new StaticRetriever(defaultRanking: ['doc-a']),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertFalse($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function retrieverIsAskedEachQuestionWithOverfetchedLimit(): void
    {
        $retriever = new StaticRetriever();
        $service = new RetrievalEvaluationService();
        $service->run(
            $this->set(
                new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-a']),
                new GoldenQuestion('q2', 'Question two?', QuestionForm::GAP, ['doc-b']),
            ),
            $retriever,
        );

        $limit = RetrievalEvaluationService::TOP_K * RetrievalEvaluationService::OVERFETCH_MULTIPLIER;
        self::assertSame([
            ['question' => 'Question one?', 'limit' => $limit],
            ['question' => 'Question two?', 'limit' => $limit],
        ], $retriever->receivedCalls);
    }

    #[Test]
    public function chunkGrainedRankingStillFillsAllDistinctRanksOnALimitRespectingRetriever(): void
    {
        // Two chunks of doc-a rank first: without overfetching, a retriever
        // that honours the limit would return only [doc-a, doc-a, doc-b] —
        // two distinct documents — and never surface doc-c.
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-x'])),
            $this->limitRespectingRetriever(['doc-a', 'doc-a', 'doc-b', 'doc-c', 'doc-d']),
        );

        self::assertSame(['doc-a', 'doc-b', 'doc-c'], $result->evaluations[0]->retrievedDocumentIds);
    }

    #[Test]
    public function targetAtRawRankFourButDistinctRankThreeIsATop3Hit(): void
    {
        // doc-c is the fourth raw result but the third distinct document.
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::MATCH, ['doc-c'])),
            $this->limitRespectingRetriever(['doc-a', 'doc-a', 'doc-b', 'doc-c']),
        );

        self::assertFalse($result->evaluations[0]->top1Hit);
        self::assertTrue($result->evaluations[0]->top3Hit);
    }

    #[Test]
    public function resultCarriesSetRetrieverAndQuestionLabels(): void
    {
        $service = new RetrievalEvaluationService();
        $result = $service->run(
            $this->set(new GoldenQuestion('q1', 'Question one?', QuestionForm::GAP, ['doc-a'], 'near-duplicate')),
            new StaticRetriever(identifier: 'nr_ai_search.vector'),
        );

        self::assertSame('a.set', $result->setIdentifier);
        self::assertSame('nr_ai_search.vector', $result->retriever);
        self::assertSame('q1', $result->evaluations[0]->questionId);
        self::assertSame(QuestionForm::GAP, $result->evaluations[0]->form);
        self::assertSame('near-duplicate', $result->evaluations[0]->hardClass);
        self::assertGreaterThanOrEqual(0, $result->evaluations[0]->latencyMs);
        self::assertGreaterThan(0, $result->runTimestamp);
    }
}
