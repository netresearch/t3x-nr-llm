<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Evaluation\PromptEvaluation;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Aggregation semantics of the golden-set run result (ADR-060): counts,
 * pass rate and mean score derived from the per-prompt evaluations, the
 * empty-set guards, and the collapse to the persistable summary.
 */
#[CoversClass(SetEvaluationResult::class)]
final class SetEvaluationResultTest extends AbstractUnitTestCase
{
    #[Test]
    public function aggregatesCountsPassRateAndMeanScore(): void
    {
        $result = $this->resultWith([
            $this->evaluation('p1', passed: true, score: 1.0),
            $this->evaluation('p2', passed: false, score: 0.25),
            $this->evaluation('p3', passed: true, score: 0.5),
            $this->evaluation('p4', passed: false, score: 0.25),
        ]);

        self::assertSame(4, $result->promptCount());
        self::assertSame(2, $result->passedCount());
        self::assertSame(0.5, $result->passRate());
        self::assertSame(0.5, $result->meanScore());
    }

    #[Test]
    public function emptySetYieldsZeroRatesInsteadOfDivisionByZero(): void
    {
        $result = $this->resultWith([]);

        self::assertSame(0, $result->promptCount());
        self::assertSame(0, $result->passedCount());
        self::assertSame(0.0, $result->passRate());
        self::assertSame(0.0, $result->meanScore());
    }

    #[Test]
    public function toSummaryCollapsesAggregatesAndCarriesUid(): void
    {
        $result = $this->resultWith([
            $this->evaluation('p1', passed: true, score: 0.75),
            $this->evaluation('p2', passed: false, score: 0.25),
        ]);

        $summary = $result->toSummary(42);

        self::assertSame('golden-de', $summary->setIdentifier);
        self::assertSame('test-model', $summary->model);
        self::assertSame('exact', $summary->grader);
        self::assertSame(2, $summary->promptCount);
        self::assertSame(1, $summary->passedCount);
        self::assertSame(0.5, $summary->passRate);
        self::assertSame(0.5, $summary->meanScore);
        self::assertSame(1700000000, $summary->runTimestamp);
        self::assertSame(42, $summary->uid);
    }

    #[Test]
    public function toSummaryDefaultsUidToZero(): void
    {
        self::assertSame(0, $this->resultWith([])->toSummary()->uid);
    }

    /**
     * @param list<PromptEvaluation> $evaluations
     */
    private function resultWith(array $evaluations): SetEvaluationResult
    {
        return new SetEvaluationResult(
            setIdentifier: 'golden-de',
            model: 'test-model',
            grader: 'exact',
            evaluations: $evaluations,
            runTimestamp: 1700000000,
        );
    }

    private function evaluation(string $promptId, bool $passed, float $score): PromptEvaluation
    {
        return new PromptEvaluation(
            promptId: $promptId,
            result: new GradingResult($passed, $score, 'exact'),
            latencyMs: 5,
        );
    }
}
