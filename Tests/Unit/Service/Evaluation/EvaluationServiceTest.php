<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(EvaluationService::class)]
final class EvaluationServiceTest extends TestCase
{
    private function evaluationService(StaticCompletionService $completion): EvaluationService
    {
        return new EvaluationService(
            $completion,
            new GradingService(new DeterministicGrader(), new DecisionGrader(new FakeDecisionService())),
        );
    }

    private function set(): GoldenPromptSet
    {
        return new GoldenPromptSet('nr_llm.smoke', 'Smoke', 'desc', [
            new GoldenPrompt('says-ack', 'Reply ACK', [Assertion::contains('ACK')]),
            new GoldenPrompt('says-paris', 'Capital of France?', [Assertion::contains('Paris')]),
        ]);
    }

    #[Test]
    public function runGradesEveryPromptAndAggregates(): void
    {
        // Response contains "ACK" (first prompt passes) but not "Paris" (second fails).
        $completion = new StaticCompletionService('ACK', 'gpt-test');
        $result = $this->evaluationService($completion)->run($this->set());

        self::assertSame('nr_llm.smoke', $result->setIdentifier);
        self::assertSame('gpt-test', $result->model);
        self::assertSame('deterministic', $result->grader);
        self::assertSame(2, $result->promptCount());
        self::assertSame(1, $result->passedCount());
        self::assertSame(0.5, $result->passRate());
        self::assertSame(0.5, $result->meanScore());
    }

    #[Test]
    public function runCallsTheModelOncePerPrompt(): void
    {
        $completion = new StaticCompletionService('ACK Paris');
        $this->evaluationService($completion)->run($this->set());

        self::assertCount(2, $completion->receivedPrompts);
        self::assertSame(['Reply ACK', 'Capital of France?'], $completion->receivedPrompts);
    }

    #[Test]
    public function runRecordsNonNegativeLatencyPerPrompt(): void
    {
        $completion = new StaticCompletionService('ACK Paris');
        $result = $this->evaluationService($completion)->run($this->set());

        foreach ($result->evaluations as $evaluation) {
            self::assertGreaterThanOrEqual(0, $evaluation->latencyMs);
        }
    }

    #[Test]
    public function allPassingSetHasFullPassRate(): void
    {
        $completion = new StaticCompletionService('ACK and Paris');
        $result = $this->evaluationService($completion)->run($this->set());

        self::assertSame(1.0, $result->passRate());
        self::assertSame(1.0, $result->meanScore());
    }

    #[Test]
    public function aDecisionGradedRunIsStoredUnderItsProviderAndModel(): void
    {
        $decisions = new FakeDecisionService();
        $decisions->results = [$this->fulfilment(4.0), $this->fulfilment(2.0)];

        $result = $this->decisionGraded($decisions)->run($this->referenceSet(), 'decision');

        self::assertSame('decision:typesafe:jev-1.13.0:v1', $result->grader);
        self::assertTrue($result->sharesOneYardstick());
    }

    #[Test]
    public function theGraderSeesTheSystemPromptTheCallRanWith(): void
    {
        $decisions = new FakeDecisionService();
        $decisions->results = [$this->fulfilment(4.0), $this->fulfilment(4.0)];

        $set = new GoldenPromptSet('nr_llm.reference', 'Reference', 'desc', [
            new GoldenPrompt('base', 'First task', [], null, 'ideal'),
            new GoldenPrompt('own', 'Second task', [], 'Answer in German.', 'ideal'),
        ]);

        $this->decisionGraded($decisions)->run($set, 'decision', (new ChatOptions())->withSystemPrompt('Answer in French.'));

        self::assertStringContainsString('Answer in French.', (string)$decisions->requests[0]->subject->task, 'the base system prompt applied');
        self::assertStringContainsString('Answer in German.', (string)$decisions->requests[1]->subject->task, 'the prompt overrides the base');
        self::assertStringNotContainsString('French', (string)$decisions->requests[1]->subject->task);
    }

    #[Test]
    public function aRunWhoseGradingsDisagreeIsStoredUnderTheRequestedGrader(): void
    {
        // The first decision fails, the second answers: one grading names no
        // yardstick, so the run is no clean TypeSafe series.
        $decisions = new FakeDecisionService();
        $decisions->throwable = DecisionException::failed('judge', new RuntimeException('overloaded', 1795211095));
        $decisions->results = [$this->fulfilment(4.0)];

        $result = $this->decisionGraded($decisions)->run($this->referenceSet(), 'decision');

        self::assertSame('decision', $result->grader);
        self::assertFalse($result->sharesOneYardstick(), 'a mixed run is never compared');
    }

    #[Test]
    public function aRunInWhichEveryDecisionFailedIsItsOwnSeries(): void
    {
        $result = $this->decisionGraded(new FakeDecisionService())->run($this->referenceSet(), 'decision');

        self::assertSame(DecisionGrader::FAILED_SERIES, $result->grader, 'never the plain identifier a mixed run falls back to');
    }

    private function decisionGraded(FakeDecisionService $decisions): EvaluationService
    {
        return new EvaluationService(
            new StaticCompletionService('an answer'),
            new GradingService(new DeterministicGrader(), new DecisionGrader($decisions)),
        );
    }

    private function referenceSet(): GoldenPromptSet
    {
        return new GoldenPromptSet('nr_llm.reference', 'Reference', 'desc', [
            new GoldenPrompt('one', 'First task', [], null, 'first ideal'),
            new GoldenPrompt('two', 'Second task', [], null, 'second ideal'),
        ]);
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
}
