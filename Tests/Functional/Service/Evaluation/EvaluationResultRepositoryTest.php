<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepository;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Evaluation\PromptEvaluation;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for evaluation-result persistence and the two-run
 * regression comparison (ADR-060).
 *
 * The repository is instantiated directly with the container's ConnectionPool
 * — its only dependency — so it does not need to be a public service.
 */
#[CoversClass(EvaluationResultRepository::class)]
final class EvaluationResultRepositoryTest extends AbstractFunctionalTestCase
{
    private const SET = 'nr_llm.smoke';
    private const MODEL = 'gpt-test';
    private const TABLE = 'tx_nrllm_eval_result';

    private EvaluationResultRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->repository = new EvaluationResultRepository($connectionPool);
    }

    /**
     * Build a result with `$total` prompts of which `$passed` pass and whose
     * mean score equals `$passed / $total`.
     */
    private function buildResult(int $total, int $passed, int $runTimestamp, string $model = self::MODEL): SetEvaluationResult
    {
        $evaluations = [];
        for ($i = 0; $i < $total; ++$i) {
            $didPass = $i < $passed;
            $evaluations[] = new PromptEvaluation(
                'p' . $i,
                new GradingResult($didPass, $didPass ? 1.0 : 0.0, 'deterministic'),
                5,
            );
        }

        return new SetEvaluationResult(self::SET, $model, 'deterministic', $evaluations, $runTimestamp);
    }

    #[Test]
    public function saveStoresAggregatesAndDetails(): void
    {
        $this->repository->save($this->buildResult(4, 3, 1_700_000_000));

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['set_identifier', 'model_id', 'grader', 'prompt_count', 'passed_count', 'pass_rate', 'mean_score', 'details'],
            self::TABLE,
            ['set_identifier' => self::SET],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(self::MODEL, $row['model_id']);
        self::assertSame('deterministic', $row['grader']);
        self::assertSame(4, (int)$row['prompt_count']);
        self::assertSame(3, (int)$row['passed_count']);
        self::assertIsNumeric($row['pass_rate']);
        self::assertIsNumeric($row['mean_score']);
        self::assertEqualsWithDelta(0.75, (float)$row['pass_rate'], 0.0001);
        self::assertEqualsWithDelta(0.75, (float)$row['mean_score'], 0.0001);

        self::assertIsString($row['details']);
        $details = json_decode($row['details'], true);
        self::assertIsArray($details);
        self::assertCount(4, $details);
    }

    #[Test]
    public function findLatestReturnsMostRecentRun(): void
    {
        $this->repository->save($this->buildResult(4, 4, 1_700_000_000));
        $this->repository->save($this->buildResult(4, 1, 1_700_000_100));

        $latest = $this->repository->findLatest(self::SET, self::MODEL);

        self::assertNotNull($latest);
        self::assertSame(1_700_000_100, $latest->runTimestamp);
        self::assertEqualsWithDelta(0.25, $latest->passRate, 0.0001);
    }

    #[Test]
    public function findRecentReturnsRunsNewestFirst(): void
    {
        $this->repository->save($this->buildResult(4, 4, 1_700_000_000));
        $this->repository->save($this->buildResult(4, 2, 1_700_000_100));

        $recent = $this->repository->findRecent(self::SET, self::MODEL, 2);

        self::assertCount(2, $recent);
        self::assertSame(1_700_000_100, $recent[0]->runTimestamp);
        self::assertSame(1_700_000_000, $recent[1]->runTimestamp);
    }

    #[Test]
    public function findLatestIsNullForUnknownSetModel(): void
    {
        self::assertNull($this->repository->findLatest('no.such.set', 'no-model'));
    }

    #[Test]
    public function regressionIsDetectedAcrossTwoPersistedRuns(): void
    {
        // First (baseline) run: all pass. Second run: quality collapses.
        $this->repository->save($this->buildResult(4, 4, 1_700_000_000));

        $previous = $this->repository->findLatest(self::SET, self::MODEL);
        self::assertNotNull($previous);

        $current = $this->buildResult(4, 1, 1_700_000_100);
        $this->repository->save($current);

        $report = (new RegressionDetector())->compare($current->toSummary(), $previous, new RegressionThresholds());

        self::assertTrue($report->hasBaseline);
        self::assertTrue($report->isRegression);
        self::assertEqualsWithDelta(-0.75, $report->passRateDelta, 0.0001);
    }

    #[Test]
    public function meanQualityScoreAveragesLatestRunPerSet(): void
    {
        // Older run should be ignored in favour of the latest for the same set.
        $this->repository->save($this->buildResult(4, 4, 1_700_000_000));
        $this->repository->save($this->buildResult(4, 2, 1_700_000_100));

        $score = $this->repository->meanQualityScoreForModel(self::MODEL);

        self::assertNotNull($score);
        self::assertEqualsWithDelta(0.5, $score, 0.0001);
    }

    #[Test]
    public function meanQualityScoreIsNullForModelWithoutResults(): void
    {
        self::assertNull($this->repository->meanQualityScoreForModel('unseen-model'));
    }
}
