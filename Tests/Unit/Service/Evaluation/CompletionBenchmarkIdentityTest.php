<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\CompletionBenchmarkIdentity;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionBenchmarkIdentity::class)]
#[CoversClass(EvaluationService::class)]
#[CoversClass(GradingService::class)]
#[CoversClass(DecisionGrader::class)]
final class CompletionBenchmarkIdentityTest extends TestCase
{
    private function set(?GoldenPrompt $prompt = null): GoldenPromptSet
    {
        return new GoldenPromptSet(
            'test.set',
            'Test',
            'Description',
            [
                $prompt ?? new GoldenPrompt(
                    'one',
                    'Reply ACK',
                    [Assertion::exact('ACK')],
                    'Be concise',
                    'ACK',
                ),
            ],
        );
    }

    /**
     * @return iterable<string, array{GoldenPrompt}>
     */
    public static function changedPrompts(): iterable
    {
        yield 'id' => [
            new GoldenPrompt(
                'two',
                'Reply ACK',
                [Assertion::exact('ACK')],
                'Be concise',
                'ACK',
            ),
        ];
        yield 'content' => [
            new GoldenPrompt(
                'one',
                'Reply ACK!',
                [Assertion::exact('ACK')],
                'Be concise',
                'ACK',
            ),
        ];
        yield 'system' => [
            new GoldenPrompt(
                'one',
                'Reply ACK',
                [Assertion::exact('ACK')],
                'Be precise',
                'ACK',
            ),
        ];
        yield 'assertion type' => [
            new GoldenPrompt(
                'one',
                'Reply ACK',
                [Assertion::contains('ACK')],
                'Be concise',
                'ACK',
            ),
        ];
        yield 'assertion value' => [
            new GoldenPrompt(
                'one',
                'Reply ACK',
                [Assertion::exact('OK')],
                'Be concise',
                'ACK',
            ),
        ];
        yield 'reference' => [
            new GoldenPrompt(
                'one',
                'Reply ACK',
                [Assertion::exact('ACK')],
                'Be concise',
                'OK',
            ),
        ];
        yield 'invalid UTF8 stays distinct' => [
            new GoldenPrompt(
                'one',
                "Reply \xff",
                [Assertion::exact('ACK')],
                'Be concise',
                'ACK',
            ),
        ];
    }

    #[Test]
    #[DataProvider('changedPrompts')]
    public function everyBenchmarkContentChangeGetsADifferentRevision(
        GoldenPrompt $changed,
    ): void {
        self::assertNotSame(
            CompletionBenchmarkIdentity::forSet(
                $this->set(),
                new ChatOptions(),
                [],
            ),
            CompletionBenchmarkIdentity::forSet(
                $this->set($changed),
                new ChatOptions(),
                [],
            ),
        );
    }

    #[Test]
    public function generatorAndDisplayLabelsAreIndependentButOptionsAndProtocolMatter(): void
    {
        $set  = $this->set();
        $base = CompletionBenchmarkIdentity::forSet(
            $set,
            new ChatOptions(),
            ['version' => 'v1'],
        );
        self::assertTrue(CompletionBenchmarkIdentity::isKnown($base));
        self::assertSame(
            $base,
            CompletionBenchmarkIdentity::forSet(
                new GoldenPromptSet(
                    'test.set',
                    'Renamed',
                    'Another description',
                    $set->prompts,
                ),
                new ChatOptions(provider: 'other', model: 'other'),
                ['version' => 'v1'],
            ),
        );
        self::assertNotSame(
            $base,
            CompletionBenchmarkIdentity::forSet(
                $set,
                new ChatOptions(temperature: 0.2),
                ['version' => 'v1'],
            ),
        );
        self::assertNotSame(
            $base,
            CompletionBenchmarkIdentity::forSet(
                $set,
                new ChatOptions(),
                ['version' => 'v2'],
            ),
        );
    }

    #[Test]
    public function callerSystemPromptOnlyMattersWhereItIsEffective(): void
    {
        $first  = new ChatOptions(systemPrompt: 'First');
        $second = new ChatOptions(systemPrompt: 'Second');
        self::assertSame(
            CompletionBenchmarkIdentity::forSet($this->set(), $first, []),
            CompletionBenchmarkIdentity::forSet($this->set(), $second, []),
        );
        $inherits = $this->set(
            new GoldenPrompt('one', 'Reply ACK', [Assertion::exact('ACK')]),
        );
        self::assertNotSame(
            CompletionBenchmarkIdentity::forSet($inherits, $first, []),
            CompletionBenchmarkIdentity::forSet($inherits, $second, []),
        );
    }

    #[Test]
    public function promptOrderAndOriginalBytesArePreserved(): void
    {
        $one    = new GoldenPrompt('one', "\xff", [Assertion::exact('ACK')]);
        $two    = new GoldenPrompt('two', "\xfe", [Assertion::exact('ACK')]);
        $first  = new GoldenPromptSet('test.set', 'Test', '', [$one, $two]);
        $second = new GoldenPromptSet('test.set', 'Test', '', [$two, $one]);
        self::assertSame(
            CompletionBenchmarkIdentity::forSet($first, new ChatOptions(), []),
            CompletionBenchmarkIdentity::forSet($first, new ChatOptions(), []),
        );
        self::assertNotSame(
            CompletionBenchmarkIdentity::forSet($first, new ChatOptions(), []),
            CompletionBenchmarkIdentity::forSet($second, new ChatOptions(), []),
        );
    }

    #[Test]
    public function actualEvaluatorCarriesItsProtocolAndOptionsIntoTheStoredSummary(): void
    {
        $completion                     = new StaticCompletionService('ACK');
        $completion->requestFingerprint = 'v1:' . str_repeat('a', 64);

        $grading = new GradingService(
            new DeterministicGrader(),
            new DecisionGrader(new FakeDecisionService()),
        );
        $service = new EvaluationService($completion, $grading);
        $base    = $service->run($this->set());
        self::assertSame(
            $base->completionBenchmarkFingerprint,
            $base->toSummary()->benchmarkFingerprint,
        );
        self::assertNotSame(
            $base->completionBenchmarkFingerprint,
            $service->run(
                $this->set(),
                baseOptions: new ChatOptions(temperature: 0.5),
            )->completionBenchmarkFingerprint,
        );
        $other = new GradingService(
            new DeterministicGrader(),
            new DecisionGrader(new FakeDecisionService(), 0.8),
        );
        self::assertNotSame(
            $grading->benchmarkProtocol('decision'),
            $other->benchmarkProtocol('decision'),
        );
    }

    /**
     * Threshold changes still affect actual grading, but neither run attests the judge request.
     */
    #[Test]
    public function changedDecisionThresholdAffectsScoresButCannotAttestTheJudgeRequest(): void
    {
        $judge  = new FakeDecisionService();
        $result = new DecisionResult(
            'nr_llm.task_fulfilment',
            1,
            'judge',
            'typesafe',
            'model-v1',
            ProbabilityKind::Distribution,
            ['fulfilment' => DecisionAnswer::score('fulfilment', 3.0)],
        );
        $judge->results                 = [$result, $result];
        $completion                     = new StaticCompletionService('ACK');
        $completion->requestFingerprint = 'v1:' . str_repeat('a', 64);

        $first = (new EvaluationService(
            $completion,
            new GradingService(
                new DeterministicGrader(),
                new DecisionGrader($judge, 0.6),
            ),
        ))->run($this->set(), 'decision');
        $second = (new EvaluationService(
            $completion,
            new GradingService(
                new DeterministicGrader(),
                new DecisionGrader($judge, 0.8),
            ),
        ))->run($this->set(), 'decision');
        self::assertSame($first->grader, $second->grader);
        self::assertSame(
            ['', ''],
            [
                $first->completionBenchmarkFingerprint,
                $second->completionBenchmarkFingerprint,
            ],
        );
        self::assertSame(1, $first->passedCount());
        self::assertSame(0, $second->passedCount());
    }

    #[Test]
    public function absentEffectiveRequestEvidenceCannotBeCompared(): void
    {
        $service = new EvaluationService(
            new StaticCompletionService('ACK'),
            new GradingService(
                new DeterministicGrader(),
                new DecisionGrader(new FakeDecisionService()),
            ),
        );
        self::assertSame(
            '',
            $service->run($this->set())->completionBenchmarkFingerprint,
        );
        self::assertSame(
            '',
            CompletionBenchmarkIdentity::forRun(
                'v1:' . str_repeat('a', 64),
                [''],
            ),
        );
    }
}
