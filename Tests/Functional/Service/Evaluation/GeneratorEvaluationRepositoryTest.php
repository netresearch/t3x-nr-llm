<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationQualityScoreProvider;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepository;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Evaluation\PromptEvaluation;
use Netresearch\NrLlm\Service\Evaluation\QualityAwareModelSelector;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Routing\CandidateRanker;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(EvaluationResultRepository::class)]
#[CoversClass(EvaluationQualityScoreProvider::class)]
#[CoversClass(CandidateRanker::class)]
#[CoversClass(QualityAwareModelSelector::class)]
final class GeneratorEvaluationRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_eval_result';

    private ConnectionPool $pool;

    private EvaluationResultRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = $this->get(ConnectionPool::class);
        $this->repository = $this->repositoryAt('metadata');
    }

    private function repositoryAt(string $level): EvaluationResultRepository
    {
        $config = self::createStub(ExtensionConfiguration::class);
        $config->method('get')->willReturn(['privacy' => ['level' => $level]]);
        return new EvaluationResultRepository(
            $this->pool,
            new PrivacyPolicy($config, new ContentRedactor()),
        );
    }

    private function provenance(
        string $provider = 'instance-a',
        string $alias = 'shared-alias',
        string $snapshot = 'snapshot-1',
    ): GeneratorProvenance {
        $record = GeneratorProvenance::fromArray(
            [
                'version' => 1,
                'providerIdentifier' => $provider,
                'modelId' => $alias,
                'reportedModelId' => $snapshot,
            ],
        );
        self::assertInstanceOf(GeneratorProvenance::class, $record);
        return $record;
    }

    private function buildRun(
        string $set = 'set-a',
        string $provider = 'instance-a',
        string $alias = 'shared-alias',
        string $snapshot = 'snapshot-1',
        float $score = 1.0,
        int $timestamp = 100,
        string $grader = 'deterministic',
    ): SetEvaluationResult {
        $record = $this->provenance($provider, $alias, $snapshot);
        return new SetEvaluationResult(
            $set,
            $alias,
            $grader,
            [
                new PromptEvaluation(
                    'p1',
                    new GradingResult(
                        $score === 1.0,
                        $score,
                        $grader,
                        'private grading detail',
                    ),
                    3,
                    $record,
                    'openai',
                ),
            ],
            $timestamp,
            generatorProvenance: $record,
        );
    }

    #[Test]
    public function legacyRowsRemainReadableButCannotRouteQuality(): void
    {
        $legacy = new SetEvaluationResult(
            'legacy',
            'shared-alias',
            'deterministic',
            [
                new PromptEvaluation(
                    'p1',
                    new GradingResult(true, 1.0, 'deterministic'),
                    0,
                ),
            ],
            100,
        );
        $this->repository->save($legacy);
        self::assertNull(
            $this->repository->meanQualityScoreForModel(
                'shared-alias',
                'deterministic',
            ),
        );
        self::assertNull(
            (new EvaluationQualityScoreProvider($this->repository))->getQualityScore(
                'shared-alias',
            ),
        );
        $read = $this->repository->findLatest('legacy', 'shared-alias', 'deterministic');
        self::assertNotNull($read);
        self::assertSame(1.0, $read->meanScore);
        self::assertNull($read->generatorProvenance);
    }

    #[Test]
    public function metadataPrivacyRetainsContentFreeServingProofAndScore(): void
    {
        $run = $this->buildRun(score: 0.0);
        $this->repository->save($run);
        $row = $this->pool
            ->getConnectionForTable(self::TABLE)
            ->select(['generator_provenance', 'details'], self::TABLE, [])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('', $row['details']);
        self::assertIsString($row['generator_provenance']);
        self::assertJson($row['generator_provenance']);
        self::assertSame(
            $run->generatorProvenance?->toArray(),
            json_decode(
                $row['generator_provenance'],
                true,
                8,
                JSON_THROW_ON_ERROR,
            ),
        );
        $read = $this->repository->findLatestForGenerator(
            'set-a',
            'instance-a',
            'shared-alias',
            'snapshot-1',
            'deterministic',
        );
        self::assertNotNull($read);
        self::assertSame(
            $run->generatorProvenance?->toArray(),
            $read->generatorProvenance?->toArray(),
        );
        self::assertSame(0.0, $read->meanScore);
        self::assertSame(
            0.0,
            $this->repository->meanQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
                'deterministic',
            ),
        );
    }

    #[Test]
    public function fullPrivacyRecordsPerPromptServingProofAndAdapterSeparately(): void
    {
        $run = $this->buildRun();
        $this->repositoryAt('full')->save($run);
        $row = $this->pool
            ->getConnectionForTable(self::TABLE)
            ->select(['details'], self::TABLE, [])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['details']);
        self::assertJson($row['details']);
        $details = json_decode($row['details'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($details);
        self::assertIsArray($details[0]);
        self::assertSame('private grading detail', $details[0]['reason']);
        self::assertSame('openai', $details[0]['reportedAdapterKey']);
        self::assertSame(
            $run->generatorProvenance?->toArray(),
            $details[0]['generatorProvenance'],
        );
    }

    #[Test]
    public function twoConfiguredInstancesWithTheSameAliasStaySeparateThroughActualRouting(): void
    {
        $this->repository->save(
            $this->buildRun(provider: 'instance-a', score: 1.0),
        );
        $this->repository->save(
            $this->buildRun(provider: 'instance-b', score: 0.0, timestamp: 200),
        );
        $scores = new EvaluationQualityScoreProvider($this->repository);
        self::assertSame(
            1.0,
            $scores->getQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
            ),
        );
        self::assertSame(
            0.0,
            $scores->getQualityScoreForProviderModel(
                'instance-b',
                'shared-alias',
            ),
        );
        self::assertNull($scores->getQualityScore('shared-alias'));
        $a = $this->model('instance-a');
        $b = $this->model('instance-b');
        $ranked = (new CandidateRanker($scores))->rank(
            [$b, $a],
            RoutingPolicyMode::QUALITY,
            [],
        );
        self::assertSame($a, $ranked[0]->model);
        self::assertSame($b, $ranked[1]->model);
        self::assertSame(1.0, $ranked[0]->signals['quality']);
        self::assertSame(0.0, $ranked[1]->signals['quality']);
        $base = $this->createMock(ModelSelectionServiceInterface::class);
        $base
            ->expects(self::once())
            ->method('findCandidates')
            ->with([])
            ->willReturn([$b, $a]);
        self::assertSame(
            $a,
            (new QualityAwareModelSelector($base, $scores))->selectByQuality([]),
        );
    }

    private function model(string $identifier): Model
    {
        $provider = new Provider();
        $provider->setIdentifier($identifier);
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setProvider($provider);
        $model->setModelId('shared-alias');
        return $model;
    }

    #[Test]
    public function allGeneratorDimensionsAreFilteredBeforeChoosingTheLatestBaseline(): void
    {
        $this->repository->save($this->buildRun(score: 0.5, timestamp: 100));
        $this->repository->save(
            $this->buildRun(provider: 'instance-b', timestamp: 200),
        );
        $this->repository->save(
            $this->buildRun(snapshot: 'snapshot-2', timestamp: 300),
        );
        $this->repository->save(
            $this->buildRun(alias: 'other-alias', timestamp: 400),
        );
        $this->repository->save(
            $this->buildRun(set: 'other-set', timestamp: 500),
        );
        $this->repository->save(
            $this->buildRun(timestamp: 600, grader: 'decision-model'),
        );
        $this->repository->save(
            new SetEvaluationResult(
                'set-a',
                'shared-alias',
                'deterministic',
                [],
                700,
            ),
        );
        $found = $this->repository->findLatestForGenerator(
            'set-a',
            'instance-a',
            'shared-alias',
            'snapshot-1',
            'deterministic',
        );
        self::assertNotNull($found);
        self::assertSame(100, $found->runTimestamp);
        self::assertSame(0.5, $found->meanScore);
        self::assertNull(
            $this->repository->findLatestForGenerator(
                'set-a',
                'instance-a',
                'shared-alias',
                'never-reported',
                'deterministic',
            ),
        );
        self::assertNotNull(
            $this->repository->findLatest(
                'set-a',
                'shared-alias',
                'deterministic',
            ),
        );
        self::assertSame(
            700,
            $this->repository->findLatest(
                'set-a',
                'shared-alias',
                'deterministic',
            )?->runTimestamp,
        );
    }

    #[Test]
    public function latestEligiblePerSetAveragePreservesZeroAndGraderIsolation(): void
    {
        $this->repository->save($this->buildRun(score: 1.0, timestamp: 100));
        $this->repository->save($this->buildRun(score: 0.0, timestamp: 100));
        $this->repository->save(
            $this->buildRun(set: 'set-b', score: 0.5, timestamp: 200),
        );
        $this->repository->save(
            $this->buildRun(
                score: 1.0,
                timestamp: 300,
                grader: 'decision-model',
            ),
        );
        $this->repository->save(
            new SetEvaluationResult(
                'set-b',
                'shared-alias',
                'deterministic',
                [],
                400,
            ),
        );
        self::assertSame(
            0.25,
            $this->repository->meanQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
                'deterministic',
            ),
        );
        self::assertSame(
            0.25,
            $this->repository->meanQualityScoreForModel(
                'shared-alias',
                'deterministic',
            ),
        );
        self::assertSame(
            1.0,
            $this->repository->meanQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
                'decision-model',
            ),
        );
        self::assertNull(
            $this->repository->meanQualityScoreForProviderModel(
                'instance-a',
                'unseen',
                'deterministic',
            ),
        );
    }

    #[Test]
    public function malformedFutureOrRowMismatchedProofNeverCreditsAStoredScore(): void
    {
        $run = $this->buildRun();
        $this->repository->save($run);
        $valid = $run->generatorProvenance?->toArray();
        self::assertIsArray($valid);
        $extra = $valid + ['invented' => 'value'];
        $missing = $valid;
        unset($missing['reportedModelId']);
        foreach ([
            '',
            'not-json',
            'null',
            '[]',
            json_encode($extra, JSON_THROW_ON_ERROR),
            json_encode($missing, JSON_THROW_ON_ERROR),
            json_encode(
                array_replace($valid, ['version' => 2]),
                JSON_THROW_ON_ERROR,
            ),
            json_encode(
                array_replace($valid, ['version' => '1']),
                JSON_THROW_ON_ERROR,
            ),
            json_encode(
                array_replace($valid, ['modelId' => 'wrong-alias']),
                JSON_THROW_ON_ERROR,
            ),
            json_encode(
                array_replace($valid, ['providerIdentifier' => null]),
                JSON_THROW_ON_ERROR,
            ),
        ] as $invalid) {
            $this->pool
                ->getConnectionForTable(self::TABLE)
                ->update(
                    self::TABLE,
                    ['generator_provenance' => $invalid],
                    ['set_identifier' => 'set-a'],
                );
            self::assertNull(
                $this->repository->meanQualityScoreForProviderModel(
                    'instance-a',
                    'shared-alias',
                    'deterministic',
                ),
                $invalid,
            );
            self::assertNull(
                $this->repository->findLatestForGenerator(
                    'set-a',
                    'instance-a',
                    'shared-alias',
                    'snapshot-1',
                    'deterministic',
                ),
                $invalid,
            );
            self::assertNotNull(
                $this->repository->findLatest(
                    'set-a',
                    'shared-alias',
                    'deterministic',
                ),
            );
        }
    }

    #[Test]
    public function incompleteOrContradictoryPromptEvidenceIsNotPersistedAsServingProof(): void
    {
        $record = $this->provenance();
        $other = $this->provenance('instance-b');
        foreach ([
            [],
            [
                new PromptEvaluation(
                    'p1',
                    new GradingResult(true, 1.0, 'deterministic'),
                    0,
                ),
            ],
            [
                new PromptEvaluation(
                    'p1',
                    new GradingResult(true, 1.0, 'deterministic'),
                    0,
                    $other,
                ),
            ],
        ] as $evaluations) {
            $this->repository->save(
                new SetEvaluationResult(
                    'set-a',
                    'shared-alias',
                    'deterministic',
                    $evaluations,
                    100,
                    generatorProvenance: $record,
                ),
            );
        }

        self::assertNull(
            $this->repository->meanQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
                'deterministic',
            ),
        );
        $rows = $this->pool
            ->getConnectionForTable(self::TABLE)
            ->select(['generator_provenance'], self::TABLE, [])
            ->fetchAllAssociative();
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame('', $row['generator_provenance']);
        }
    }

    #[Test]
    public function retentionDeletesServingProofTogetherWithItsAggregate(): void
    {
        $this->repository->save($this->buildRun(timestamp: 100));
        $this->repository->save($this->buildRun(timestamp: 200));
        self::assertSame(1, $this->repository->purgeOlderThan(200));
        $rows = $this->pool
            ->getConnectionForTable(self::TABLE)
            ->select(['run_date', 'generator_provenance'], self::TABLE, [])
            ->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(200, (int)$rows[0]['run_date']);
        self::assertIsString($rows[0]['generator_provenance']);
        self::assertJson($rows[0]['generator_provenance']);
        self::assertSame(
            $this->provenance()->toArray(),
            json_decode(
                $rows[0]['generator_provenance'],
                true,
                8,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    #[Test]
    public function actualMixedRunCannotCreditEitherGeneratorThroughTheRepositoryAndRanker(): void
    {
        $records = [$this->provenance('instance-a'), $this->provenance('instance-b')];
        $completion = $this->createMock(CompletionServiceInterface::class);
        $completion
            ->expects(self::exactly(2))
            ->method('complete')
            ->willReturnOnConsecutiveCalls(
                new CompletionResponse(
                    'BAD',
                    'snapshot-1',
                    new UsageStatistics(0, 0, 0),
                    provider: 'openai',
                    metadata: [
                        GeneratorProvenance::METADATA_KEY => $records[0]->toArray(),
                    ],
                ),
                new CompletionResponse(
                    'ACK',
                    'snapshot-1',
                    new UsageStatistics(0, 0, 0),
                    provider: 'openai',
                    metadata: [
                        GeneratorProvenance::METADATA_KEY => $records[1]->toArray(),
                    ],
                ),
            );
        $service = new EvaluationService(
            $completion,
            new GradingService(
                new DeterministicGrader(),
                new DecisionGrader(new FakeDecisionService()),
            ),
        );
        $run = $service->run(
            new GoldenPromptSet(
                'mixed',
                'Mixed',
                'description',
                [
                    new GoldenPrompt(
                        'p1',
                        'Reply ACK',
                        [Assertion::contains('ACK')],
                    ),
                    new GoldenPrompt(
                        'p2',
                        'Reply ACK',
                        [Assertion::contains('ACK')],
                    ),
                ],
            ),
        );
        self::assertSame(0.5, $run->meanScore());
        self::assertTrue($run->sharesOneYardstick());
        self::assertSame('', $run->model);
        $this->repository->save($run);
        $scores = new EvaluationQualityScoreProvider($this->repository);
        self::assertNull(
            $scores->getQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
            ),
        );
        self::assertNull(
            $scores->getQualityScoreForProviderModel(
                'instance-b',
                'shared-alias',
            ),
        );
        $a = $this->model('instance-a');
        $b = $this->model('instance-b');
        $ranked = (new CandidateRanker($scores))->rank(
            [$a, $b],
            RoutingPolicyMode::QUALITY,
            [],
        );
        foreach ($ranked as $candidate) {
            self::assertNull($candidate->signals['quality']);
            self::assertSame(0.5, $candidate->score);
        }

        $history = $this->repository->findLatest('mixed', '', 'deterministic');
        self::assertNotNull($history);
        self::assertSame(0.5, $history->meanScore);
        self::assertNull($history->generatorProvenance);
    }
}
