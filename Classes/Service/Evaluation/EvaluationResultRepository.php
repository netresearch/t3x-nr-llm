<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use InvalidArgumentException;
use JsonException;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
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
final readonly class EvaluationResultRepository implements GeneratorEvaluationResultRepositoryInterface
{
    private const TABLE = 'tx_nrllm_eval_result';

    public function __construct(
        private ConnectionPool $connectionPool,
        private PrivacyPolicyInterface $privacyPolicy,
    ) {}

    public function save(SetEvaluationResult $result): void
    {
        $identity = $result->retrieval?->identity;
        $generator = $result->verifiedGeneratorProvenance();
        $now = time();
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->insert(
                self::TABLE,
                [
                    'pid' => 0,
                    'set_identifier' => $result->setIdentifier,
                    'model_id' => $result->model,
                    'grader' => $result->grader,
                    'generator_provenance' => $generator instanceof GeneratorProvenance ? json_encode($generator->toArray(), JSON_THROW_ON_ERROR) : '',
                    'retrieval_provenance' => $identity?->provenance instanceof RetrievalProvenance ? json_encode(
                        $identity->provenance->toArray() + [
                            'labelsFingerprint' => $identity->labelsFingerprint,
                            'scoringVersion' => RetrievalRunIdentity::SCORING_VERSION,
                        ],
                        JSON_THROW_ON_ERROR,
                    ) : '',
                    'benchmark_fingerprint' => $identity->benchmarkFingerprint ?? '',
                    'variant_fingerprint' => $identity->variantFingerprint ?? '',
                    'prompt_count' => $result->promptCount(),
                    'passed_count' => $result->passedCount(),
                    'pass_rate' => $result->passRate(),
                    'mean_score' => $result->meanScore(),
                    'details' => $this->privacyPolicy->filterContent(
                        $this->encodeDetails($result),
                    ) ?? '',
                    'run_date' => $result->runTimestamp,
                    'tstamp' => $now,
                    'crdate' => $now,
                ],
            );
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('run_date', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }

    public function findLatest(
        string $setIdentifier,
        string $model,
        string $grader,
    ): ?EvaluationResultSummary {
        $recent = $this->findRecent($setIdentifier, $model, $grader, 1);

        return $recent[0] ?? null;
    }

    public function findRecent(
        string $setIdentifier,
        string $model,
        string $grader,
        int $limit,
    ): array {
        if ($limit < 1) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $this
            ->baseSelect($queryBuilder)
            ->where(
                $queryBuilder->expr()->eq(
                    'set_identifier',
                    $queryBuilder->createNamedParameter($setIdentifier),
                ),
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

    public function meanQualityScoreForModel(
        string $model,
        string $grader,
    ): ?float {
        return $this->qualityScore($model, $grader, null);
    }

    private function baseSelect(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder->select(
            'uid',
            'generator_provenance',
            'retrieval_provenance',
            'benchmark_fingerprint',
            'variant_fingerprint',
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
            $this->readFingerprint($row['benchmark_fingerprint'] ?? null),
            $this->readFingerprint($row['variant_fingerprint'] ?? null),
            $this->readProvenance($row['retrieval_provenance'] ?? null),
            $this->readGeneratorProvenance($row),
        );
    }

    private function encodeDetails(SetEvaluationResult $result): string
    {
        if ($result->retrieval instanceof RetrievalSetEvaluationResult) {
            $mapper = $this->privacyPolicy->level() === PrivacyLevel::FULL ? $this->losslessRetrievalDetails(...) : static fn(QuestionEvaluation $evaluation): array => $evaluation->toArray();
            $details = array_map($mapper, $result->retrieval->evaluations);
        } else {
            $details = array_map(
                static fn(PromptEvaluation $evaluation): array => $evaluation->toArray(),
                $result->evaluations,
            );
        }

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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

    private function readFingerprint(mixed $value): string
    {
        return is_string($value) && preg_match('/^v1:[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    private function readProvenance(mixed $value): ?RetrievalProvenance
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $this->decodeProvenance($decoded) : null;
        } catch (JsonException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Validate the stored field types before constructing the bounded revision declarations.
     *
     * @param array<array-key, mixed> $decoded
     */
    private function decodeProvenance(array $decoded): ?RetrievalProvenance
    {
        foreach (['corpusRevision', 'modelRevision', 'chunkingIdentity', 'pipelineIdentity'] as $key) {
            if (!is_string($decoded[$key] ?? null)) {
                return null;
            }
        }

        $execution = $decoded['executionRevision'] ?? null;
        if ($execution !== null && !is_string($execution)) {
            return null;
        }

        return new RetrievalProvenance(
            $decoded['corpusRevision'],
            $decoded['modelRevision'],
            $decoded['chunkingIdentity'],
            $decoded['pipelineIdentity'],
            $execution,
        );
    }

    /**
     * Ordinary UTF-8 fields retain their existing JSON shape; legacy bytes are tagged only at FULL privacy.
     *
     * @return array<string, mixed>
     */
    private function losslessRetrievalDetails(QuestionEvaluation $evaluation): array
    {
        return array_replace(
            $evaluation->toArray(),
            [
                'questionId' => $this->losslessRetrievalString($evaluation->questionId),
                'hardClass' => $evaluation->hardClass === null ? null : $this->losslessRetrievalString($evaluation->hardClass),
                'retrievedDocumentIds' => array_map($this->losslessRetrievalString(...), $evaluation->retrievedDocumentIds),
            ],
        );
    }

    /**
     * Filter original invalid UTF-8 bytes before encoding; custom FULL policies can scrub or drop them.
     * The complete JSON payload separately passes the existing filter in save().
     *
     * @return string|array{encoding: string, version: string, value: string}|null
     */
    private function losslessRetrievalString(string $value): string|array|null
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $permitted = $this->privacyPolicy->filterContent($value);
        if ($permitted === null || preg_match('//u', $permitted) === 1) {
            return $permitted;
        }

        return [
            'encoding' => 'base64',
            'version' => 'retrieval-bytes-v1',
            'value' => base64_encode($permitted),
        ];
    }

    public function meanQualityScoreForProviderModel(
        string $providerId,
        string $modelId,
        string $grader,
    ): ?float {
        return $this->qualityScore($modelId, $grader, $providerId);
    }

    public function findLatestForGenerator(
        string $setIdentifier,
        string $providerId,
        string $modelId,
        string $reportedModelId,
        string $grader,
    ): ?EvaluationResultSummary {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $this
            ->baseSelect($builder)
            ->where(
                $builder->expr()->eq(
                    'set_identifier',
                    $builder->createNamedParameter($setIdentifier),
                ),
                $builder->expr()->eq(
                    'model_id',
                    $builder->createNamedParameter($modelId),
                ),
                $builder->expr()->eq(
                    'grader',
                    $builder->createNamedParameter($grader),
                ),
            )
            ->orderBy('run_date', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->iterateAssociative();
        // Filter all dimensions before the first matching row; a newer different snapshot cannot mask it.
        foreach ($rows as $row) {
            $record = $this->generatorForScope($row, $modelId, $grader);
            if (($row['set_identifier'] ?? null) === $setIdentifier && $record?->providerIdentifier === $providerId && $record?->reportedModelId === $reportedModelId) {
                return $this->mapRow($row);
            }
        }

        return null;
    }

    /**
     * Latest eligible result per set, streamed without a database-specific JSON function.
     */
    private function qualityScore(
        string $model,
        string $grader,
        ?string $providerIdentifier,
    ): ?float {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $this
            ->baseSelect($builder)
            ->where(
                $builder->expr()->eq(
                    'model_id',
                    $builder->createNamedParameter($model),
                ),
                $builder->expr()->eq(
                    'grader',
                    $builder->createNamedParameter($grader),
                ),
            )
            ->orderBy('run_date', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->iterateAssociative();
        $providers = [];
        $scores = [];
        foreach ($rows as $row) {
            $record = $this->generatorForScope($row, $model, $grader);
            if (!$record instanceof GeneratorProvenance || $providerIdentifier !== null && $record->providerIdentifier !== $providerIdentifier) {
                continue;
            }

            $providers[$record->providerIdentifier] = true;
            $set = $this->toString($row['set_identifier'] ?? '');
            if (!array_key_exists($set, $scores)) {
                $scores[$set] = $this->toFloat($row['mean_score'] ?? 0);
            }
        }

        if ($scores === [] || $providerIdentifier === null && count($providers) !== 1) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function readGeneratorProvenance(array $row): ?GeneratorProvenance
    {
        $value = $row['generator_provenance'] ?? null;
        if (!is_string($value) || $value === '' || $this->toInt($row['prompt_count'] ?? 0) < 1) {
            return null;
        }

        try {
            $record = GeneratorProvenance::fromArray(
                json_decode($value, true, 8, JSON_THROW_ON_ERROR),
            );
        } catch (JsonException) {
            return null;
        }

        return $record instanceof GeneratorProvenance && $record->modelId === ($row['model_id'] ?? null) ? $record : null;
    }

    /**
     * SQL collations may produce a broader candidate set; eligibility uses exact identities.
     *
     * @param array<string,mixed> $row
     */
    private function generatorForScope(
        array $row,
        string $modelId,
        string $grader,
    ): ?GeneratorProvenance {
        $record = $this->readGeneratorProvenance($row);
        return $record?->modelId === $modelId && ($row['grader'] ?? null) === $grader ? $record : null;
    }
}
