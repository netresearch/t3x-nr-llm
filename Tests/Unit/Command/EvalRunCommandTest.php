<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\EvalRunCommand;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryEvaluationResultRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

#[CoversClass(EvalRunCommand::class)]
final class EvalRunCommandTest extends TestCase
{
    private const SET_IDENTIFIER = 'nr_llm.test';

    private const REFERENCE_SET = 'nr_llm.test_reference';

    private function registry(): GoldenPromptSetRegistry
    {
        $set = new GoldenPromptSet(self::SET_IDENTIFIER, 'Test set', 'desc', [
            new GoldenPrompt('ack', 'Reply ACK', [Assertion::contains('ACK')]),
        ]);
        $referenceSet = new GoldenPromptSet(self::REFERENCE_SET, 'Reference set', 'desc', [
            new GoldenPrompt('one', 'First task', [], null, 'first ideal'),
            new GoldenPrompt('two', 'Second task', [], null, 'second ideal'),
        ]);
        $provider = new class ([$set, $referenceSet]) implements GoldenPromptSetProviderInterface {
            /**
             * @param list<GoldenPromptSet> $sets
             */
            public function __construct(private readonly array $sets) {}

            public function getGoldenPromptSets(): array
            {
                return $this->sets;
            }
        };

        return new GoldenPromptSetRegistry([$provider]);
    }

    private function command(StaticCompletionService $completion, InMemoryEvaluationResultRepository $repository, ?FakeDecisionService $decisions = null): EvalRunCommand
    {
        $evaluationService = new EvaluationService(
            $completion,
            new GradingService(new DeterministicGrader(), new DecisionGrader($decisions ?? new FakeDecisionService())),
        );

        return new EvalRunCommand($this->registry(), $evaluationService, $repository, new RegressionDetector());
    }

    private function perfectBaseline(): EvaluationResultSummary
    {
        return new EvaluationResultSummary(self::SET_IDENTIFIER, 'test-model', 'deterministic', 1, 1, 1.0, 1.0, 1_700_000_000);
    }

    #[Test]
    public function unknownSetFailsWithHint(): void
    {
        $tester = new CommandTester($this->command(new StaticCompletionService('ACK'), new InMemoryEvaluationResultRepository()));

        $exitCode = $tester->execute(['set' => 'does.not.exist']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown golden prompt set', $tester->getDisplay());
        self::assertStringContainsString(self::SET_IDENTIFIER, $tester->getDisplay());
    }

    #[Test]
    public function unknownGraderFailsWithHint(): void
    {
        $tester = new CommandTester($this->command(new StaticCompletionService('ACK'), new InMemoryEvaluationResultRepository()));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER, '--grader' => 'llm_judg']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown grader', $tester->getDisplay());
        self::assertStringContainsString('llm_judg', $tester->getDisplay());
    }

    #[Test]
    public function passingRunSucceedsAndPersists(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $tester = new CommandTester($this->command(new StaticCompletionService('ACK'), $repository));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Pass rate', $tester->getDisplay());
        self::assertStringContainsString('100.0%', $tester->getDisplay());
        self::assertCount(1, $repository->saved);
    }

    #[Test]
    public function firstRunHasNoBaselineAndSucceeds(): void
    {
        $tester = new CommandTester($this->command(new StaticCompletionService('nope'), new InMemoryEvaluationResultRepository()));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('baseline', $tester->getDisplay());
    }

    #[Test]
    public function regressionWithFailFlagExitsNonZero(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed($this->perfectBaseline());

        // Current run fails the assertion (response lacks "ACK") → pass rate 0.0.
        $tester = new CommandTester($this->command(new StaticCompletionService('nope'), $repository));

        $exitCode = $tester->execute([
            'set' => self::SET_IDENTIFIER,
            '--fail-on-regression' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('regression', strtolower($tester->getDisplay()));
    }

    #[Test]
    public function regressionWithoutFailFlagStillSucceeds(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed($this->perfectBaseline());

        $tester = new CommandTester($this->command(new StaticCompletionService('nope'), $repository));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    private function fulfilment(float $level): DecisionResult
    {
        return new DecisionResult(
            profile: 'nr_llm.task_fulfilment',
            profileVersion: 1,
            configuration: 'judge',
            provider: 'typesafe',
            model: 'jev-1.13.0',
            probabilityKind: ProbabilityKind::Distribution,
            answers: ['fulfilment' => DecisionAnswer::score('fulfilment', $level)],
        );
    }

    #[Test]
    public function aCleanDecisionRunIsComparedWithinItsOwnSeries(): void
    {
        $series = 'decision:typesafe:jev-1.13.0:v1';
        $repository = new InMemoryEvaluationResultRepository();
        // A perfect run of the same yardstick, and a worse one under another.
        $repository->seed(new EvaluationResultSummary(self::REFERENCE_SET, 'test-model', $series, 2, 2, 1.0, 1.0, 1_700_000_000));
        $repository->seed(new EvaluationResultSummary(self::REFERENCE_SET, 'test-model', 'decision:openai:gpt-4o:v1', 2, 0, 0.0, 0.0, 1_700_000_100));

        $decisions = new FakeDecisionService();
        $decisions->results = [$this->fulfilment(1.0), $this->fulfilment(1.0)];

        $tester = new CommandTester($this->command(new StaticCompletionService('an answer'), $repository, $decisions));
        $exitCode = $tester->execute(['set' => self::REFERENCE_SET, '--grader' => 'decision', '--fail-on-regression' => true]);

        // Level 1 of 4 is a score of 0.25: a regression against its own series.
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('regression', strtolower($tester->getDisplay()));
        self::assertSame($series, $repository->saved[0]->grader);
    }

    /**
     * @return iterable<string, array{bool, int}>
     */
    public static function gateOptions(): iterable
    {
        yield 'reported only' => [false, Command::SUCCESS];
        yield 'fails the gate' => [true, Command::FAILURE];
    }

    #[Test]
    #[DataProvider('gateOptions')]
    public function aRunWithoutOneYardstickIsStoredButNeverCompared(bool $failOnRegression, int $expectedExit): void
    {
        // A bad baseline under the plain identifier: were the run compared
        // against it, the outcome would be a regression, not "not compared".
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed(new EvaluationResultSummary(self::REFERENCE_SET, 'test-model', 'decision', 2, 2, 1.0, 1.0, 1_700_000_000));

        // The first decision fails, the second answers.
        $decisions = new FakeDecisionService();
        $decisions->throwable = DecisionException::failed('judge', new RuntimeException('overloaded', 1795211094));
        $decisions->results = [new DecisionResult(
            profile: 'nr_llm.task_fulfilment',
            profileVersion: 1,
            configuration: 'judge',
            provider: 'typesafe',
            model: 'jev-1.13.0',
            probabilityKind: ProbabilityKind::Distribution,
            answers: ['fulfilment' => DecisionAnswer::score('fulfilment', 4.0)],
        )];

        $tester = new CommandTester($this->command(new StaticCompletionService('an answer'), $repository, $decisions));
        $exitCode = $tester->execute(['set' => self::REFERENCE_SET, '--grader' => 'decision', '--fail-on-regression' => $failOnRegression]);

        // A run that cannot be compared cannot pass a gate either.
        self::assertSame($expectedExit, $exitCode);
        self::assertStringContainsString('do not share one yardstick', $tester->getDisplay());
        self::assertCount(1, $repository->saved);
        self::assertSame('decision', $repository->saved[0]->grader);
    }

    #[Test]
    public function aDecisionGraderThatCannotRunFailsBeforeAnyCompletionIsPaidFor(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $decisions  = new FakeDecisionService();
        $decisions->unavailable = DecisionException::noConfiguration();

        $completion = new StaticCompletionService('an answer');

        $tester = new CommandTester($this->command($completion, $repository, $decisions));
        $exitCode = $tester->execute(['set' => self::REFERENCE_SET, '--grader' => 'decision']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('cannot run', $tester->getDisplay());
        self::assertSame([], $completion->receivedPrompts, 'nothing was spent');
        self::assertSame([], $repository->saved);
    }

    #[Test]
    #[DataProvider('gateOptions')]
    public function aRunInWhichNoDecisionCouldBeMadeIsStoredButNeverCompared(bool $failOnRegression, int $expectedExit): void
    {
        $repository = new InMemoryEvaluationResultRepository();

        // Every grading fails the same way, so the run shares one series —
        // the failed one, which names no yardstick.
        $decisions = new FakeDecisionService();
        $decisions->throwable = DecisionException::noConfiguration();

        $tester = new CommandTester($this->command(new StaticCompletionService('an answer'), $repository, $decisions));
        $exitCode = $tester->execute(['set' => self::REFERENCE_SET, '--grader' => 'decision', '--fail-on-regression' => $failOnRegression]);

        self::assertSame($expectedExit, $exitCode);
        self::assertStringContainsString('no decision of this run could be made', $tester->getDisplay());
        self::assertCount(1, $repository->saved);
        self::assertSame(DecisionGrader::FAILED_SERIES, $repository->saved[0]->grader);
    }

    #[Test]
    #[DataProvider('invalidTolerances')]
    public function invalidTolerancesFailBeforeAnyWork(
        string $option,
        string $value,
    ): void {
        $repository = new InMemoryEvaluationResultRepository();
        $worker = new StaticCompletionService('ACK');
        $tester = new CommandTester($this->command($worker, $repository));
        $exit = null;
        $failure = null;
        try {
            $exit = $tester->execute(['set' => self::SET_IDENTIFIER, $option => $value]);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        self::assertSame(
            [],
            $worker->receivedPrompts,
            'Invalid tolerances must be rejected before external work.',
        );
        self::assertSame(
            [],
            $repository->saved,
            'Invalid tolerances must not persist a measurement.',
        );
        self::assertNull(
            $failure,
            'The command must report invalid input as a failure status.',
        );
        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString($option, $tester->getDisplay());
        self::assertStringContainsString('0..1', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTolerances(): iterable
    {
        foreach (['--max-pass-rate-drop', '--max-mean-score-drop'] as $option) {
            foreach ([
                'below-range' => '-0.0001',
                'above-range' => '1.0001',
                'overflow' => '1e309',
                'nan' => 'NAN',
                'infinity' => 'INF',
                'negative-infinity' => '-INF',
                'nonnumeric' => 'typo',
                'empty' => '',
            ] as $case => $value) {
                yield $option . '-' . $case => [$option, $value];
            }
        }
    }

    #[Test]
    #[DataProvider('validTolerances')]
    public function validToleranceBoundariesControlRegression(
        string $value,
        int $expectedExit,
    ): void {
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed($this->perfectBaseline());

        $worker = new StaticCompletionService('nope');
        $tester = new CommandTester($this->command($worker, $repository));
        $exit = $tester->execute(
            [
                'set' => self::SET_IDENTIFIER,
                '--max-pass-rate-drop' => $value,
                '--max-mean-score-drop' => $value,
                '--fail-on-regression' => true,
            ],
        );
        self::assertSame($expectedExit, $exit);
        self::assertCount(1, $worker->receivedPrompts);
        self::assertCount(1, $repository->saved);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validTolerances(): iterable
    {
        yield 'zero' => ['0', Command::FAILURE];
        yield 'negative-zero' => ['-0.0', Command::FAILURE];
        yield 'decimal' => ['0.5', Command::FAILURE];
        yield 'scientific' => ['5e-1', Command::FAILURE];
        yield 'one' => ['1', Command::SUCCESS];
    }
}
