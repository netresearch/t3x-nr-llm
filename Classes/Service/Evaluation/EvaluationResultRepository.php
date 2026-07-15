<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Stores and reads evaluation run summaries in `tx_nrllm_eval_result`
 * (ADR-060).
 *
 * A UI-less result log — no TCA, mirroring `tx_nrllm_service_usage`. Each
 * run is one row carrying the aggregate metrics plus a JSON snapshot of the
 * per-prompt outcomes for later inspection. Direct DBAL, like
 * UsageTrackerService; nothing resolves this class from the container by
 * name (the command autowires the interface), so it stays off the public
 * service surface.
 */
final readonly class EvaluationResultRepository implements EvaluationResultRepositoryInterface
{
    private const TABLE = 'tx_nrllm_eval_result';

    public function __construct(
        private ConnectionPool $connectionPool,
        private PrivacyPolicyInterface $privacyPolicy,
    ) {}

    public function save(SetEvaluationResult $result): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid' => 0,
            'set_identifier' => $result->setIdentifier,
            'model_id' => $result->model,
            'grader' => $result->grader,
            'prompt_count' => $result->promptCount(),
            'passed_count' => $result->passedCount(),
            'pass_rate' => $result->passRate(),
            'mean_score' => $result->meanScore(),
            // The details snapshot is the per-prompt content payload; gate it
            // through the central privacy policy before persisting (ADR-064).
            // Metadata columns above are always kept.
            'details' => $this->privacyPolicy->filterContent($this->encodeDetails($result)) ?? '',
            'run_date' => $result->runTimestamp,
            'tstamp' => $now,
            'crdate' => $now,
        ]);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $connection   = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('run_date', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }

    public function findLatest(string $setIdentifier, string $model, string $grader): ?EvaluationResultSummary
    {
        $recent = $this->findRecent($setIdentifier, $model, $grader, 1);

        return $recent[0] ?? null;
    }

    public function findRecent(string $setIdentifier, string $model, string $grader, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $this->baseSelect($queryBuilder)
            ->where(
                $queryBuilder->expr()->eq('set_identifier', $queryBuilder->createNamedParameter($setIdentifier)),
                $queryBuilder->expr()->eq('model_id', $queryBuilder->createNamedParameter($model)),
                $queryBuilder->expr()->eq('grader', $queryBuilder->createNamedParameter($grader)),
            )
            ->orderBy('run_date', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    public function meanQualityScoreForModel(string $model, string $grader): ?float
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('set_identifier', 'mean_score')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('model_id', $queryBuilder->createNamedParameter($model)),
                $queryBuilder->expr()->eq('grader', $queryBuilder->createNamedParameter($grader)),
            )
            ->orderBy('run_date', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $seenSets = [];
        $scores = [];
        foreach ($rows as $row) {
            $setIdentifier = $this->toString($row['set_identifier'] ?? '');
            if (isset($seenSets[$setIdentifier])) {
                continue;
            }
            $seenSets[$setIdentifier] = true;
            $scores[] = $this->toFloat($row['mean_score'] ?? 0);
        }

        if ($scores === []) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    private function baseSelect(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->select(
                'uid',
                'set_identifier',
                'model_id',
                'grader',
                'prompt_count',
                'passed_count',
                'pass_rate',
                'mean_score',
                'run_date',
            )
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): EvaluationResultSummary
    {
        return new EvaluationResultSummary(
            $this->toString($row['set_identifier'] ?? ''),
            $this->toString($row['model_id'] ?? ''),
            $this->toString($row['grader'] ?? ''),
            $this->toInt($row['prompt_count'] ?? 0),
            $this->toInt($row['passed_count'] ?? 0),
            $this->toFloat($row['pass_rate'] ?? 0),
            $this->toFloat($row['mean_score'] ?? 0),
            $this->toInt($row['run_date'] ?? 0),
            $this->toInt($row['uid'] ?? 0),
        );
    }

    private function encodeDetails(SetEvaluationResult $result): string
    {
        $details = array_map(
            static fn(PromptEvaluation $evaluation): array => $evaluation->toArray(),
            $result->evaluations,
        );

        return json_encode($details, JSON_THROW_ON_ERROR);
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}
