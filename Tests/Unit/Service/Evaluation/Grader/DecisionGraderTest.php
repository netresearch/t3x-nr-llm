<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Grader;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\Profile\BuiltinDecisionProfileProvider;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(DecisionGrader::class)]
#[CoversClass(BuiltinDecisionProfileProvider::class)]
final class DecisionGraderTest extends TestCase
{
    private FakeDecisionService $decisions;

    protected function setUp(): void
    {
        $this->decisions = new FakeDecisionService();
    }

    private function queueLevel(float $level): void
    {
        $this->decisions->results[] = new DecisionResult(
            profile: BuiltinDecisionProfileProvider::TASK_FULFILMENT,
            profileVersion: 1,
            configuration: 'judge',
            provider: 'typesafe',
            model: 'jev-1.13.0',
            probabilityKind: ProbabilityKind::Distribution,
            answers: ['fulfilment' => DecisionAnswer::score('fulfilment', $level)],
        );
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function levels(): iterable
    {
        yield 'fails' => [0.0, 0.0, false];
        yield 'just below the threshold' => [2.36, 0.59, false];
        yield 'at the threshold' => [2.4, 0.6, true];
        yield 'complete' => [4.0, 1.0, true];
    }

    #[Test]
    #[DataProvider('levels')]
    public function theLevelIsScaledToZeroToOneAndPassesAtTheThreshold(float $level, float $score, bool $passed): void
    {
        $this->queueLevel($level);

        $result = (new DecisionGrader($this->decisions))->grade('response', new GoldenPrompt('p', 'task', [], null, 'ref'));

        self::assertEqualsWithDelta($score, $result->score, 1e-9);
        self::assertSame($passed, $result->passed);
        self::assertSame('decision:typesafe:jev-1.13.0:v1', $result->grader, 'the series names its yardstick');
    }

    #[Test]
    public function theTaskResponseAndReferenceBecomeTheSubject(): void
    {
        $this->queueLevel(4.0);

        (new DecisionGrader($this->decisions))->grade('the response', new GoldenPrompt('p', 'the task', [], null, 'the ideal'));

        $request = $this->decisions->requests[0];
        self::assertSame(BuiltinDecisionProfileProvider::TASK_FULFILMENT, $request->profile);
        self::assertSame(['task' => 'the task', 'candidate' => 'the response', 'evidence' => ['Reference answer: the ideal']], $request->subject->toState());
        self::assertSame(['nr_llm', 'evaluation'], [$request->callerSourceExtension, $request->callerSourceOperation]);
    }

    #[Test]
    public function withoutAReferenceAnswerNoEvidenceIsSentAndABlankSystemPromptAddsNothing(): void
    {
        $this->queueLevel(3.0);

        (new DecisionGrader($this->decisions))->grade('the response', new GoldenPrompt('p', 'the task', [Assertion::contains('x')], "  \n"));

        self::assertSame(['task' => 'the task', 'candidate' => 'the response'], $this->decisions->requests[0]->subject->toState());
    }

    #[Test]
    public function theSystemPromptTheModelWasGivenIsPartOfTheTask(): void
    {
        $this->queueLevel(4.0);

        (new DecisionGrader($this->decisions))->grade('Bonjour', new GoldenPrompt('p', 'Greet the user', [], 'Answer in French.', 'Bonjour'));

        self::assertSame(
            "Instructions the response had to follow:\nAnswer in French.\n\nTask:\nGreet the user",
            $this->decisions->requests[0]->subject->task,
        );
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function failedDecisions(): iterable
    {
        yield 'no configuration' => [DecisionException::noConfiguration(), 'names no configuration'];
        // A policy denial keeps its type in the service, but one prompt over
        // budget must not abort the rest of an offline run either.
        yield 'budget denial' => [new BudgetExceededException(BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_COST, 5.0, 1.0)), 'Decision failed: '];
    }

    #[Test]
    #[DataProvider('failedDecisions')]
    public function aFailedDecisionIsAFailedGradingNotAnAbortedRun(Throwable $failure, string $reason): void
    {
        $this->decisions->throwable = $failure;

        $result = (new DecisionGrader($this->decisions))->grade('response', new GoldenPrompt('p', 'task', [], null, 'ref'));

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(DecisionGrader::FAILED_SERIES, $result->grader, 'no model answered, so no yardstick to name');
        self::assertStringContainsString($reason, $result->reason);
        self::assertStringContainsString($failure->getMessage(), $result->reason);
    }

    #[Test]
    public function theBuiltinProfileRequiresTaskAndCandidateAndMatchesTheGradersScale(): void
    {
        $profiles = (new BuiltinDecisionProfileProvider())->getDecisionProfiles();

        self::assertCount(1, $profiles);
        self::assertSame([SubjectField::Task, SubjectField::Candidate], $profiles[0]->requires);
        $question = $profiles[0]->questions[0];
        self::assertInstanceOf(ScoreQuestion::class, $question);
        self::assertSame(BuiltinDecisionProfileProvider::TASK_FULFILMENT_LEVELS, $question->levels);
    }
}
