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
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GradingService::class)]
final class GradingServiceTest extends TestCase
{
    private FakeDecisionService $decisions;

    protected function setUp(): void
    {
        $this->decisions = new FakeDecisionService();
    }

    private function service(): GradingService
    {
        return new GradingService(new DeterministicGrader(), new DecisionGrader($this->decisions));
    }

    private function prompt(): GoldenPrompt
    {
        return new GoldenPrompt('p', 'prompt', [], null, 'reference');
    }

    #[Test]
    public function defaultsToDeterministicGrader(): void
    {
        // The prompt has no assertions, so the deterministic grader reports its
        // "nothing to grade" verdict — proving it, not the decision grader, ran.
        $result = $this->service()->grade('any response', $this->prompt());

        self::assertSame('deterministic', $result->grader);
        self::assertFalse($result->passed);
        self::assertSame([], $this->decisions->requests);
    }

    #[Test]
    public function unknownGraderFallsBackToDeterministic(): void
    {
        $result = $this->service()->grade('any', $this->prompt(), 'llm_judge');

        self::assertSame('deterministic', $result->grader);
        self::assertSame([], $this->decisions->requests, 'the removed judge id must not spend tokens');
    }

    #[Test]
    public function decisionGraderIsUsedWhenRequested(): void
    {
        $this->decisions->results[] = new DecisionResult(
            profile: 'nr_llm.task_fulfilment',
            profileVersion: 1,
            configuration: 'judge',
            provider: 'openai',
            model: 'm',
            probabilityKind: ProbabilityKind::None,
            answers: ['fulfilment' => DecisionAnswer::score('fulfilment', 4.0)],
        );

        $result = $this->service()->grade('any', $this->prompt(), 'decision');

        self::assertSame('decision:openai:m:v1', $result->grader);
        self::assertSame(1.0, $result->score);
    }

    #[Test]
    public function availableGradersListsBothStrategies(): void
    {
        self::assertSame(['deterministic', 'decision'], $this->service()->availableGraders());
    }
}
