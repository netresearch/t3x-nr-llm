<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepository;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Evaluation\PromptEvaluation;
use Netresearch\NrLlm\Service\Evaluation\QuestionEvaluation;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Service\Evaluation\RetrievalProvenance;
use Netresearch\NrLlm\Service\Evaluation\RetrievalRunIdentity;
use Netresearch\NrLlm\Service\Evaluation\RetrievalSetEvaluationResult;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
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

    private const GRADER = 'deterministic';

    private const TABLE = 'tx_nrllm_eval_result';

    private EvaluationResultRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        // Run the persistence tests at the "full" privacy level so the details
        // snapshot is stored verbatim; the metadata-emptying case below uses a
        // "metadata" policy of its own (ADR-064).
        $this->repository = new EvaluationResultRepository($connectionPool, $this->policy('full'));
    }

    /**
     * Build a result with `$total` prompts of which `$passed` pass and whose
     * mean score equals `$passed / $total`.
     */
    private function buildResult(
        int $total,
        int $passed,
        int $runTimestamp,
        string $model = self::MODEL,
        string $grader = self::GRADER,
    ): SetEvaluationResult {
        $evaluations = [];
        for ($i = 0; $i < $total; ++$i) {
            $didPass = $i < $passed;
            $evaluations[] = new PromptEvaluation(
                'p' . $i,
                new GradingResult($didPass, $didPass ? 1.0 : 0.0, $grader),
                5,
            );
        }

        return new SetEvaluationResult(self::SET, $model, $grader, $evaluations, $runTimestamp);
    }

    #[Test]
    public function saveStoresAggregatesAndDetails(): void
    {
        $this->repository->save($this->buildResult(4, 3, 1700000000));

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $row = $connection
            ->select(
                [
                    'set_identifier',
                    'model_id',
                    'grader',
                    'prompt_count',
                    'passed_count',
                    'pass_rate',
                    'mean_score',
                    'details',
                ],
                self::TABLE,
                ['set_identifier' => self::SET],
            )
            ->fetchAssociative();

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
        $this->repository->save($this->buildResult(4, 4, 1700000000));
        $this->repository->save($this->buildResult(4, 1, 1700000100));

        $latest = $this->repository->findLatest(self::SET, self::MODEL, self::GRADER);

        self::assertNotNull($latest);
        self::assertSame(1700000100, $latest->runTimestamp);
        self::assertEqualsWithDelta(0.25, $latest->passRate, 0.0001);
    }

    #[Test]
    public function findRecentReturnsRunsNewestFirst(): void
    {
        $this->repository->save($this->buildResult(4, 4, 1700000000));
        $this->repository->save($this->buildResult(4, 2, 1700000100));

        $recent = $this->repository->findRecent(self::SET, self::MODEL, self::GRADER, 2);

        self::assertCount(2, $recent);
        self::assertSame(1700000100, $recent[0]->runTimestamp);
        self::assertSame(1700000000, $recent[1]->runTimestamp);
    }

    #[Test]
    public function findLatestIsNullForUnknownSetModel(): void
    {
        self::assertNull($this->repository->findLatest('no.such.set', 'no-model', self::GRADER));
    }

    #[Test]
    public function regressionIsDetectedAcrossTwoPersistedRuns(): void
    {
        // First (baseline) run: all pass. Second run: quality collapses.
        $this->repository->save($this->buildResult(4, 4, 1700000000));

        $previous = $this->repository->findLatest(self::SET, self::MODEL, self::GRADER);
        self::assertNotNull($previous);

        $current = $this->buildResult(4, 1, 1700000100);
        $this->repository->save($current);

        $report = (new RegressionDetector())->compare(
            $current->toSummary(),
            $previous,
            new RegressionThresholds(),
        );

        self::assertTrue($report->hasBaseline);
        self::assertTrue($report->isRegression);
        self::assertEqualsWithDelta(-0.75, $report->passRateDelta, 0.0001);
    }

    #[Test]
    public function meanQualityScoreAveragesLatestRunPerSet(): void
    {
        // Older run should be ignored in favour of the latest for the same set.
        $this->repository->save($this->buildResult(4, 4, 1700000000));
        $this->repository->save($this->buildResult(4, 2, 1700000100));

        $score = $this->repository->meanQualityScoreForModel(self::MODEL, self::GRADER);

        self::assertNotNull($score);
        self::assertEqualsWithDelta(0.5, $score, 0.0001);
    }

    #[Test]
    public function meanQualityScoreIsNullForModelWithoutResults(): void
    {
        self::assertNull($this->repository->meanQualityScoreForModel('unseen-model', self::GRADER));
    }

    #[Test]
    public function findLatestSegregatesByGrader(): void
    {
        // A deterministic run and a decision run for the SAME (set, model)
        // must not be treated as the same series — their pass_rate/mean_score
        // are not comparable, so regression detection must not pair them. A
        // stored run of the removed llm_judge grader is no baseline for the
        // decision grader either (ADR-211): its yardstick was another one.
        $this->repository->save($this->buildResult(4, 4, 1700000000, self::MODEL, 'deterministic'));
        $this->repository->save($this->buildResult(4, 1, 1700000100, self::MODEL, 'decision'));
        $this->repository->save($this->buildResult(4, 3, 1700000200, self::MODEL, 'llm_judge'));

        $deterministic = $this->repository->findLatest(self::SET, self::MODEL, 'deterministic');
        $decision = $this->repository->findLatest(self::SET, self::MODEL, 'decision');

        self::assertNotNull($deterministic);
        self::assertNotNull($decision);
        self::assertSame(1700000000, $deterministic->runTimestamp);
        self::assertSame(1700000100, $decision->runTimestamp);
        self::assertEqualsWithDelta(1.0, $deterministic->passRate, 0.0001);
        self::assertEqualsWithDelta(0.25, $decision->passRate, 0.0001);
    }

    #[Test]
    public function saveAtMetadataLevelEmptiesDetailsButKeepsMetadata(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $repository = new EvaluationResultRepository($connectionPool, $this->policy('metadata'));

        $repository->save($this->buildResult(4, 3, 1700000500));

        $row = $connectionPool
            ->getConnectionForTable(self::TABLE)
            ->select(['prompt_count', 'passed_count', 'details'], self::TABLE, ['run_date' => 1700000500])
            ->fetchAssociative();

        self::assertIsArray($row);
        // Metadata columns are preserved...
        self::assertSame(4, (int)$row['prompt_count']);
        self::assertSame(3, (int)$row['passed_count']);
        // ...but the content payload is dropped (ADR-064).
        self::assertSame('', $row['details']);
    }

    private function policy(string $level): PrivacyPolicy
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['level' => $level]]);

        return new PrivacyPolicy($extensionConfiguration, new ContentRedactor());
    }

    private function retrievalResult(): SetEvaluationResult
    {
        $set = new GoldenQuestionSet(
            'test.retrieval',
            'Set',
            'Description',
            [new GoldenQuestion('q1', 'Question?', QuestionForm::GAP, ['internal-doc'])],
        );
        $identity = RetrievalRunIdentity::forSet(
            $set,
            new RetrievalProvenance(
                'corpus-sha256',
                'model-revision',
                'chunk-sha256',
                'pipeline-sha256',
                'commit-sha',
            ),
        );
        return (new RetrievalSetEvaluationResult(
            $set->identifier,
            'test.retriever',
            [
                new QuestionEvaluation(
                    'q1',
                    QuestionForm::GAP,
                    'near-duplicate',
                    true,
                    true,
                    ['internal-doc', 'second-doc'],
                    19,
                ),
            ],
            1700000000,
            $identity,
        ))->toSetEvaluationResult();
    }

    #[Test]
    public function retrievalIdentityAndRankingRoundTripWithoutNewResultStore(): void
    {
        $result = $this->retrievalResult();
        $this->repository->save($result);
        $summary = $this->repository->findLatest(
            'test.retrieval',
            'test.retriever',
            RetrievalSetEvaluationResult::GRADER_IDENTIFIER,
        );
        self::assertNotNull($summary);
        self::assertSame($result->toSummary()->benchmarkFingerprint, $summary->benchmarkFingerprint);
        self::assertSame($result->toSummary()->variantFingerprint, $summary->variantFingerprint);
        self::assertSame(
            $result->toSummary()->retrievalProvenance?->toArray(),
            $summary->retrievalProvenance?->toArray(),
        );
        $row = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->select(['details'], self::TABLE, ['set_identifier' => 'test.retrieval'])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['details']);
        $details = json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                [
                    'questionId' => 'q1',
                    'form' => 'gap',
                    'hardClass' => 'near-duplicate',
                    'top1Hit' => true,
                    'top3Hit' => true,
                    'retrievedDocumentIds' => ['internal-doc', 'second-doc'],
                    'latencyMs' => 19,
                ],
            ],
            $details,
        );
    }

    #[Test]
    public function metadataPrivacyKeepsIdentityButDropsRetrievalRankings(): void
    {
        $repository = new EvaluationResultRepository($this->get(ConnectionPool::class), $this->policy('metadata'));
        $repository->save($this->retrievalResult());

        $row = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->select(
                ['details', 'retrieval_provenance', 'benchmark_fingerprint'],
                self::TABLE,
                ['set_identifier' => 'test.retrieval'],
            )
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('', $row['details']);
        self::assertIsString($row['retrieval_provenance']);
        self::assertStringNotContainsString('internal-doc', $row['retrieval_provenance']);
        self::assertStringNotContainsString('Question?', $row['retrieval_provenance']);
        self::assertNotSame('', $row['benchmark_fingerprint']);
        self::assertSame(1, $repository->purgeOlderThan(1700000001));
        self::assertNull(
            $repository->findLatest(
                'test.retrieval',
                'test.retriever',
                RetrievalSetEvaluationResult::GRADER_IDENTIFIER,
            ),
        );
        $metadata = json_decode($row['retrieval_provenance'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame(
            $this->retrievalResult()->retrieval?->identity?->labelsFingerprint,
            $metadata['labelsFingerprint'],
        );
        self::assertSame(RetrievalRunIdentity::SCORING_VERSION, $metadata['scoringVersion']);
    }

    #[Test]
    public function legacyAndMalformedMetadataReadAsUnknown(): void
    {
        $this->repository->save($this->buildResult(1, 1, 1700000000));
        $summary = $this->repository->findLatest(self::SET, self::MODEL, self::GRADER);
        self::assertNotNull($summary);
        self::assertSame('', $summary->benchmarkFingerprint);
        self::assertNull($summary->retrievalProvenance);
        $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['retrieval_provenance' => '{broken', 'benchmark_fingerprint' => 'wrong'],
                ['set_identifier' => self::SET],
            );
        $malformed = $this->repository->findLatest(self::SET, self::MODEL, self::GRADER);
        self::assertNotNull($malformed);
        self::assertSame('', $malformed->benchmarkFingerprint);
        self::assertNull($malformed->retrievalProvenance);
    }

    /**
     * Corrupt stored field types and unsafe revisions cannot establish known provenance.
     */
    #[Test]
    public function invalidProvenanceShapesAndRevisionsReadAsUnknown(): void
    {
        $this->repository->save($this->retrievalResult());
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $valid = [
            'corpusRevision' => 'corpus-v1',
            'modelRevision' => 'model-v1',
            'chunkingIdentity' => 'chunk-v1',
            'pipelineIdentity' => 'pipeline-v1',
        ];
        $invalid = [
            'null',
            '"plain-string"',
            '[]',
            json_encode($valid + ['executionRevision' => []], JSON_THROW_ON_ERROR),
        ];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            $invalid[] = json_encode($missing, JSON_THROW_ON_ERROR);
            $invalid[] = json_encode(array_replace($valid, [$key => 12]), JSON_THROW_ON_ERROR);
            $invalid[] = json_encode(
                array_replace($valid, [$key => 'https://private.test/revision']),
                JSON_THROW_ON_ERROR,
            );
        }

        foreach ($invalid as $metadata) {
            $connection->update(
                self::TABLE,
                ['retrieval_provenance' => $metadata],
                ['set_identifier' => 'test.retrieval'],
            );
            $summary = $this->repository->findLatest(
                'test.retrieval',
                'test.retriever',
                RetrievalSetEvaluationResult::GRADER_IDENTIFIER,
            );
            self::assertNotNull($summary);
            self::assertNull($summary->retrievalProvenance, $metadata);
        }
    }

    private function byteRetrievalResult(string $firstId = "doc\xff"): SetEvaluationResult
    {
        return (new RetrievalSetEvaluationResult(
            self::BYTE_SET,
            'test.retriever',
            [
                new QuestionEvaluation(
                    "question\xff",
                    QuestionForm::MATCH,
                    "class\xfe",
                    true,
                    true,
                    [$firstId, "doc\xfe", 'doc-c'],
                    7,
                ),
            ],
            1700000000,
        ))->toSetEvaluationResult();
    }

    private function storedByteDetails(): string
    {
        $row = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->select(['details'], self::TABLE, ['set_identifier' => self::BYTE_SET])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['details']);
        return $row['details'];
    }

    /**
     * The lossless versioned persisted representation of an invalid UTF-8 field.
     *
     * @return array{encoding: string, version: string, value: string}
     */
    private function encodedBytes(string $value): array
    {
        return [
            'encoding' => 'base64',
            'version' => 'retrieval-bytes-v1',
            'value' => base64_encode($value),
        ];
    }

    #[Test]
    public function fullRetrievalDetailsPreserveLegacyByteFieldsAndDistinctTopThree(): void
    {
        $result = $this->byteRetrievalResult();
        $this->repository->save($result);
        $details = json_decode($this->storedByteDetails(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                [
                    'questionId' => $this->encodedBytes("question\xff"),
                    'form' => 'match',
                    'hardClass' => $this->encodedBytes("class\xfe"),
                    'top1Hit' => true,
                    'top3Hit' => true,
                    'retrievedDocumentIds' => [$this->encodedBytes("doc\xff"), $this->encodedBytes("doc\xfe"), 'doc-c'],
                    'latencyMs' => 7,
                ],
            ],
            $details,
        );
    }

    #[Test]
    public function restrictedPrivacyNeverPersistsEncodedLegacyByteSecrets(): void
    {
        $secret = self::BYTE_EMAIL . "\xff";
        foreach (['redacted', 'none', 'metadata'] as $level) {
            $connection = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
            $connection->delete(self::TABLE, ['set_identifier' => self::BYTE_SET]);
            $repository = new EvaluationResultRepository($this->get(ConnectionPool::class), $this->policy($level));
            $repository->save($this->byteRetrievalResult($secret));
            $details = $this->storedByteDetails();
            self::assertStringNotContainsString(self::BYTE_EMAIL, $details, $level);
            self::assertStringNotContainsString(base64_encode($secret), $details, $level);
            self::assertStringNotContainsString('retrieval-bytes-v1', $details, $level);
            if ($level === 'redacted') {
                self::assertStringContainsString('***', $details);
            } else {
                self::assertSame('', $details);
            }
        }
    }

    #[Test]
    public function customFullPolicyScrubsOriginalBytesBeforeTheirEncoding(): void
    {
        $secret = self::BYTE_EMAIL . "\xff";
        $seen = [];
        $policy = self::createStub(PrivacyPolicyInterface::class);
        $policy->method('level')->willReturn(PrivacyLevel::FULL);
        $policy
            ->method('filterContent')
            ->willReturnCallback(
                static function (?string $value) use (&$seen): ?string {
                    $seen[] = $value;
                    return $value === null ? null : str_replace(self::BYTE_EMAIL, '***', $value);
                },
            );
        $repository = new EvaluationResultRepository($this->get(ConnectionPool::class), $policy);
        $repository->save($this->byteRetrievalResult($secret));

        $details = $this->storedByteDetails();
        self::assertContains($secret, $seen);
        self::assertStringNotContainsString(base64_encode($secret), $details);
        $stored = json_decode($details, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        $row = $stored[0] ?? null;
        self::assertIsArray($row);
        $ids = $row['retrievedDocumentIds'] ?? null;
        self::assertIsArray($ids);
        self::assertSame($this->encodedBytes("***\xff"), $ids[0]);
    }

    #[Test]
    public function customFullPolicyCanDropAnOriginalInvalidByteField(): void
    {
        $secret = self::BYTE_EMAIL . "\xff";
        $policy = self::createStub(PrivacyPolicyInterface::class);
        $policy->method('level')->willReturn(PrivacyLevel::FULL);
        $policy
            ->method('filterContent')
            ->willReturnCallback(static fn(?string $value): ?string => $value === $secret ? null : $value);
        $repository = new EvaluationResultRepository($this->get(ConnectionPool::class), $policy);
        $repository->save($this->byteRetrievalResult($secret));

        $details = $this->storedByteDetails();
        self::assertStringNotContainsString(base64_encode($secret), $details);
        $stored = json_decode($details, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        $row = $stored[0] ?? null;
        self::assertIsArray($row);
        $ids = $row['retrievedDocumentIds'] ?? null;
        self::assertIsArray($ids);
        self::assertNull($ids[0]);
    }

    #[Test]
    public function customFullPolicyCanStillDropTheOuterRetrievalPayload(): void
    {
        $policy = self::createStub(PrivacyPolicyInterface::class);
        $policy->method('level')->willReturn(PrivacyLevel::FULL);
        $policy
            ->method('filterContent')
            ->willReturnCallback(
                static fn(
                    ?string $value,
                ): ?string => $value !== null && str_starts_with($value, '[') ? null : $value,
            );
        $repository = new EvaluationResultRepository($this->get(ConnectionPool::class), $policy);
        $repository->save($this->byteRetrievalResult());
        self::assertSame('', $this->storedByteDetails());
        self::assertNotNull(
            $repository->findLatest(
                self::BYTE_SET,
                'test.retriever',
                RetrievalSetEvaluationResult::GRADER_IDENTIFIER,
            ),
        );
    }

    private const BYTE_SET = 'test.byte_details';

    private const BYTE_EMAIL = 'person@example.test';

    #[Test]
    public function customFullPolicyCanReplaceLegacyBytesWithOrdinaryUtf8(): void
    {
        $original = self::BYTE_EMAIL . "\xff";
        $policy = self::createStub(PrivacyPolicyInterface::class);
        $policy->method('level')->willReturn(PrivacyLevel::FULL);
        $policy
            ->method('filterContent')
            ->willReturnCallback(static fn(?string $value): ?string => $value === $original ? 'filtered-id' : $value);
        $repository = new EvaluationResultRepository($this->get(ConnectionPool::class), $policy);
        $repository->save($this->byteRetrievalResult($original));

        $details = $this->storedByteDetails();
        self::assertStringNotContainsString(base64_encode($original), $details);
        self::assertStringContainsString('"retrievedDocumentIds":["filtered-id",', $details);
    }
}
