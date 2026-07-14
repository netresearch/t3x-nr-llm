<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\EvalRunCommand;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\LlmJudgeGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryEvaluationResultRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EvalRunCommand::class)]
final class EvalRunCommandTest extends TestCase
{
    private const SET_IDENTIFIER = 'nr_llm.test';

    private function registry(): GoldenPromptSetRegistry
    {
        $set = new GoldenPromptSet(self::SET_IDENTIFIER, 'Test set', 'desc', [
            new GoldenPrompt('ack', 'Reply ACK', [Assertion::contains('ACK')]),
        ]);
        $provider = new class ([$set]) implements GoldenPromptSetProviderInterface {
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

    private function command(StaticCompletionService $completion, InMemoryEvaluationResultRepository $repository): EvalRunCommand
    {
        $evaluationService = new EvaluationService(
            $completion,
            new GradingService(new DeterministicGrader(), new LlmJudgeGrader($completion)),
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
}
