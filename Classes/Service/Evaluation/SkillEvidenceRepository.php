<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use JsonException;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Domain\ValueObject\SkillEvidence;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads the evaluations that measured a skill version (ADR-322) from the
 * versions each evaluation stored beside its result.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SkillEvidenceRepository implements SkillEvidenceLookupInterface
{
    private const SKILL_TABLE = 'tx_nrllm_eval_skill_version';

    private const RESULT_TABLE = 'tx_nrllm_eval_result';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function evidenceFor(int $skillUid, string $versionDigest): array
    {
        if ($skillUid <= 0 || $versionDigest === '') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::RESULT_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            // The figures a snapshot holds; never the graded details.
            ->select('r.uid', 'r.run_date', 'r.set_identifier', 'r.benchmark_fingerprint', 'r.configuration_identifier', 'r.model_id', 'r.generator_provenance', 'r.grader', 'r.grading_protocol', 'r.prompt_count', 'r.passed_count', 'r.pass_rate', 'r.mean_score')
            ->from(self::SKILL_TABLE, 'v')
            ->join('v', self::RESULT_TABLE, 'r', $queryBuilder->expr()->eq('r.uid', $queryBuilder->quoteIdentifier('v.eval_result')))
            ->where(
                $queryBuilder->expr()->eq('v.skill_uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('v.version_digest', $queryBuilder->createNamedParameter($versionDigest)),
            )
            ->orderBy('r.run_date', 'DESC')
            ->addOrderBy('r.uid', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();

        $evidence = [];
        foreach ($rows as $row) {
            $evidence[] = $this->evidenceOf($row);
        }

        return $evidence;
    }

    public function isAvailable(SkillEvidence $evidence): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::RESULT_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('uid')
            ->from(self::RESULT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($evidence->evaluationUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('run_date', $queryBuilder->createNamedParameter($evidence->runDate, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('benchmark_fingerprint', $queryBuilder->createNamedParameter($evidence->benchmarkFingerprint)),
                $queryBuilder->expr()->eq('set_identifier', $queryBuilder->createNamedParameter($evidence->setIdentifier)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function evidenceOf(array $row): SkillEvidence
    {
        $generator = null;
        $stored    = $row['generator_provenance'] ?? null;
        if (is_string($stored) && $stored !== '') {
            try {
                $generator = GeneratorProvenance::fromArray(json_decode($stored, true, 8, JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $generator = null;
            }
        }

        return new SkillEvidence(
            evaluationUid: $this->int($row, 'uid'),
            runDate: $this->int($row, 'run_date'),
            setIdentifier: $this->str($row, 'set_identifier'),
            benchmarkFingerprint: $this->str($row, 'benchmark_fingerprint'),
            configurationIdentifier: $this->str($row, 'configuration_identifier'),
            providerIdentifier: $generator instanceof GeneratorProvenance ? $generator->providerIdentifier : '',
            modelId: $generator instanceof GeneratorProvenance ? $generator->modelId : $this->str($row, 'model_id'),
            reportedModelId: $generator instanceof GeneratorProvenance ? $generator->reportedModelId : '',
            grader: $this->str($row, 'grader'),
            gradingProtocol: $this->str($row, 'grading_protocol'),
            promptCount: $this->int($row, 'prompt_count'),
            passedCount: $this->int($row, 'passed_count'),
            passRate: $this->float($row, 'pass_rate'),
            meanScore: $this->float($row, 'mean_score'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $column): int
    {
        $value = $row[$column] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function float(array $row, string $column): float
    {
        $value = $row[$column] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
