<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\LlmJudgeGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvaluationService::class)]
final class EvaluationServiceTest extends TestCase
{
    private function evaluationService(StaticCompletionService $completion): EvaluationService
    {
        return new EvaluationService(
            $completion,
            new GradingService(new DeterministicGrader(), new LlmJudgeGrader($completion)),
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
}
