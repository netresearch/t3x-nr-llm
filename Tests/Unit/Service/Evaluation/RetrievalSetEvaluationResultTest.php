<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\QuestionEvaluation;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Service\Evaluation\RetrievalSetEvaluationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetrievalSetEvaluationResult::class)]
#[CoversClass(QuestionEvaluation::class)]
final class RetrievalSetEvaluationResultTest extends TestCase
{
    private function evaluation(
        string $id,
        bool $top1,
        bool $top3,
        QuestionForm $form = QuestionForm::MATCH,
        ?string $hardClass = null,
    ): QuestionEvaluation {
        return new QuestionEvaluation($id, $form, $hardClass, $top1, $top3, ['doc-a'], 5);
    }

    private function retrievalResult(QuestionEvaluation ...$evaluations): RetrievalSetEvaluationResult
    {
        return new RetrievalSetEvaluationResult('a.set', 'test.retriever', array_values($evaluations), 1_700_000_000);
    }

    #[Test]
    public function emptyResultHasZeroRates(): void
    {
        $result = $this->retrievalResult();

        self::assertSame(0, $result->questionCount());
        self::assertSame(0.0, $result->top1HitRate());
        self::assertSame(0.0, $result->top3HitRate());
        self::assertSame([], $result->hitRatesByForm());
        self::assertSame([], $result->hitRatesByHardClass());
    }

    #[Test]
    public function hitRatesAreTheHitFractions(): void
    {
        $result = $this->retrievalResult(
            $this->evaluation('q1', true, true),
            $this->evaluation('q2', false, true),
            $this->evaluation('q3', false, false),
            $this->evaluation('q4', true, true),
        );

        self::assertSame(4, $result->questionCount());
        self::assertSame(2, $result->top1HitCount());
        self::assertSame(3, $result->top3HitCount());
        self::assertSame(0.5, $result->top1HitRate());
        self::assertSame(0.75, $result->top3HitRate());
    }

    #[Test]
    public function hitRatesByFormSplitMatchAndGap(): void
    {
        $result = $this->retrievalResult(
            $this->evaluation('q1', true, true, QuestionForm::MATCH),
            $this->evaluation('q2', false, true, QuestionForm::MATCH),
            $this->evaluation('q3', false, false, QuestionForm::GAP),
        );

        self::assertSame([
            'match' => ['questions' => 2, 'top1HitRate' => 0.5, 'top3HitRate' => 1.0],
            'gap' => ['questions' => 1, 'top1HitRate' => 0.0, 'top3HitRate' => 0.0],
        ], $result->hitRatesByForm());
    }

    #[Test]
    public function hitRatesByHardClassSkipUnclassifiedQuestions(): void
    {
        $result = $this->retrievalResult(
            $this->evaluation('q1', true, true, QuestionForm::MATCH, 'near-duplicate'),
            $this->evaluation('q2', false, false, QuestionForm::GAP, 'near-duplicate'),
            $this->evaluation('q3', true, true, QuestionForm::MATCH),
        );

        self::assertSame([
            'near-duplicate' => ['questions' => 2, 'top1HitRate' => 0.5, 'top3HitRate' => 0.5],
        ], $result->hitRatesByHardClass());
    }

    #[Test]
    public function conversionMapsTop1ToPassAndTop3ToScore(): void
    {
        $converted = $this->retrievalResult(
            $this->evaluation('q1', true, true),
            $this->evaluation('q2', false, true),
            $this->evaluation('q3', false, false),
        )->toSetEvaluationResult();

        self::assertSame('a.set', $converted->setIdentifier);
        self::assertSame('test.retriever', $converted->model);
        self::assertSame(RetrievalSetEvaluationResult::GRADER_IDENTIFIER, $converted->grader);
        self::assertSame(1_700_000_000, $converted->runTimestamp);

        self::assertTrue($converted->evaluations[0]->result->passed);
        self::assertSame(1.0, $converted->evaluations[0]->result->score);
        self::assertFalse($converted->evaluations[1]->result->passed);
        self::assertSame(1.0, $converted->evaluations[1]->result->score);
        self::assertFalse($converted->evaluations[2]->result->passed);
        self::assertSame(0.0, $converted->evaluations[2]->result->score);

        // The persisted aggregates are the hit rates: passRate = top-1, meanScore = top-3.
        $summary = $converted->toSummary();
        self::assertEqualsWithDelta(1 / 3, $summary->passRate, 1e-9);
        self::assertEqualsWithDelta(2 / 3, $summary->meanScore, 1e-9);
    }

    #[Test]
    public function regressionDetectorFlagsATop1HitRateDrop(): void
    {
        $baseline = $this->retrievalResult(
            $this->evaluation('q1', true, true),
            $this->evaluation('q2', true, true),
        )->toSetEvaluationResult()->toSummary();

        $current = $this->retrievalResult(
            $this->evaluation('q1', false, true),
            $this->evaluation('q2', true, true),
        )->toSetEvaluationResult()->toSummary();

        $report = (new RegressionDetector())->compare($current, $baseline, new RegressionThresholds(0.1, 0.1));

        self::assertTrue($report->isRegression);
        self::assertEqualsWithDelta(-0.5, $report->passRateDelta, 1e-9);
        self::assertSame(0.0, $report->meanScoreDelta);
    }
}
