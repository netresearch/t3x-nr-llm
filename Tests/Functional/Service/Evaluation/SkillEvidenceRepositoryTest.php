<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\SkillEvidenceFit;
use Netresearch\NrLlm\Domain\Enum\SkillRole;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Domain\ValueObject\SkillEvidence;
use Netresearch\NrLlm\Domain\ValueObject\SkillUse;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepository;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Evaluation\PromptEvaluation;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Service\Evaluation\SkillEvidenceRepository;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The evaluations that measured a skill version (ADR-322) against the real
 * schema: stored with the evaluation, found by skill and digest with the
 * tested generator, configuration and grading, and purged with it.
 */
#[CoversClass(EvaluationResultRepository::class)]
#[CoversClass(SkillEvidenceRepository::class)]
final class SkillEvidenceRepositoryTest extends AbstractFunctionalTestCase
{
    private const DIGEST = '1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BENCHMARK = 'v1:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EvaluationResultRepository $results;

    private SkillEvidenceRepository $evidence;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $config = self::createStub(ExtensionConfiguration::class);
        $config->method('get')->willReturn(['privacy' => ['level' => 'metadata']]);
        $this->results  = new EvaluationResultRepository($pool, new PrivacyPolicy($config, new ContentRedactor()));
        $this->evidence = new SkillEvidenceRepository($pool);
    }

    #[Test]
    public function anEvaluationIsFoundForTheVersionItMeasuredWithWhatItTested(): void
    {
        $this->results->save($this->evaluation(1_700_000_000, [new SkillUse(3, 1, self::DIGEST, 'house-rules', SkillRole::INSTRUCTION)]));

        $found = $this->evidence->evidenceFor(3, self::DIGEST);

        self::assertCount(1, $found);
        $evidence = $found[0];
        self::assertSame('nr_llm.smoke', $evidence->setIdentifier);
        self::assertSame(1_700_000_000, $evidence->runDate);
        self::assertSame(self::BENCHMARK, $evidence->benchmarkFingerprint);
        self::assertSame('default', $evidence->configurationIdentifier);
        self::assertSame('openai-main', $evidence->providerIdentifier);
        self::assertSame('gpt-5', $evidence->modelId);
        self::assertSame('gpt-5-2025-08-07', $evidence->reportedModelId);
        self::assertSame('deterministic', $evidence->grader);
        self::assertSame('deterministic-assertions-v1', $evidence->gradingProtocol);
        self::assertSame(1, $evidence->promptCount);
        self::assertSame(1.0, $evidence->passRate);
        self::assertSame(SkillEvidenceFit::UNKNOWN, $evidence->fit);
        self::assertTrue($this->evidence->isAvailable($evidence));
        // A snapshot names its evaluation by uid with run date, benchmark and
        // set: a row that reuses the uid for another evaluation is not it.
        foreach (['runDate' => 1_700_000_001, 'benchmark' => 'v1:' . str_repeat('e', 64), 'set' => 'nr_llm.other'] as $key => $other) {
            $snapshot = SkillEvidence::fromArray([$key => $other] + $evidence->toArray());
            self::assertInstanceOf(SkillEvidence::class, $snapshot);
            self::assertFalse($this->evidence->isAvailable($snapshot), $key);
        }

        self::assertSame([], $this->evidence->evidenceFor(3, '1:' . str_repeat('c', 64)), 'another digest of the skill was not measured');
        self::assertSame([], $this->evidence->evidenceFor(4, self::DIGEST), 'another skill was not measured');
    }

    #[Test]
    public function anEvaluationThatMeasuredNoSkillIsNoEvidence(): void
    {
        $this->results->save($this->evaluation(1_700_000_000, []));

        self::assertSame([], $this->evidence->evidenceFor(3, self::DIGEST));
    }

    #[Test]
    public function theNewestEvaluationsComeFirstAndAtMostThree(): void
    {
        $use = [new SkillUse(3, 1, self::DIGEST, 'house-rules', SkillRole::INSTRUCTION)];
        foreach ([1_700_000_001, 1_700_000_004, 1_700_000_002, 1_700_000_003] as $date) {
            $this->results->save($this->evaluation($date, $use));
        }

        self::assertSame(
            [1_700_000_004, 1_700_000_003, 1_700_000_002],
            array_map(static fn (SkillEvidence $e): int => $e->runDate, $this->evidence->evidenceFor(3, self::DIGEST)),
        );
    }

    /**
     * Case 7 of the lineage acceptance, storage side: retention purges the
     * evaluation and the versions it measured together, and a snapshot of it
     * then reads as no longer available.
     */
    #[Test]
    public function retentionPurgesTheEvaluationAndTheVersionsItMeasured(): void
    {
        $this->results->save($this->evaluation(1_700_000_000, [new SkillUse(3, 1, self::DIGEST, 'house-rules', SkillRole::INSTRUCTION)]));
        // A newer evaluation of the same version, inside the retention window.
        $this->results->save($this->evaluation(1_700_000_002, [new SkillUse(3, 1, self::DIGEST, 'house-rules', SkillRole::INSTRUCTION)]));

        $snapshot = $this->evidence->evidenceFor(3, self::DIGEST)[1];
        self::assertSame(1_700_000_000, $snapshot->runDate);

        $this->results->purgeOlderThan(1_700_000_001);

        $kept = $this->evidence->evidenceFor(3, self::DIGEST);
        self::assertSame([1_700_000_002], array_map(static fn (SkillEvidence $e): int => $e->runDate, $kept));
        self::assertTrue($this->evidence->isAvailable($kept[0]));
        self::assertFalse($this->evidence->isAvailable($snapshot));
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        self::assertSame(1, $pool->getConnectionForTable('tx_nrllm_eval_skill_version')->count('*', 'tx_nrllm_eval_skill_version', []));
    }

    /**
     * @param list<SkillUse> $tested
     */
    private function evaluation(int $date, array $tested): SetEvaluationResult
    {
        $generator = GeneratorProvenance::fromArray(['version' => 1, 'providerIdentifier' => 'openai-main', 'modelId' => 'gpt-5', 'reportedModelId' => 'gpt-5-2025-08-07']);
        self::assertInstanceOf(GeneratorProvenance::class, $generator);

        return new SetEvaluationResult(
            'nr_llm.smoke',
            'gpt-5',
            'deterministic',
            [new PromptEvaluation('p1', new GradingResult(true, 1.0, 'deterministic'), 3, $generator, 'openai')],
            $date,
            generatorProvenance: $generator,
            completionBenchmarkFingerprint: self::BENCHMARK,
            testedSkills: $tested,
            testedConfiguration: $tested === [] ? '' : 'default',
            gradingProtocol: 'deterministic-assertions-v1',
        );
    }
}
