<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\LlmJudgeGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GradingService::class)]
final class GradingServiceTest extends TestCase
{
    private function service(string $judgeContent = '{"score":0.9}'): GradingService
    {
        return new GradingService(
            new DeterministicGrader(),
            new LlmJudgeGrader(new StaticCompletionService($judgeContent)),
        );
    }

    private function prompt(): GoldenPrompt
    {
        return new GoldenPrompt('p', 'prompt', [], null, 'reference');
    }

    #[Test]
    public function defaultsToDeterministicGrader(): void
    {
        // The prompt has no assertions, so the deterministic grader reports its
        // "nothing to grade" verdict — proving it, not the judge, was used.
        $result = $this->service()->grade('any response', $this->prompt());

        self::assertSame('deterministic', $result->grader);
        self::assertFalse($result->passed);
    }

    #[Test]
    public function unknownGraderFallsBackToDeterministic(): void
    {
        $result = $this->service()->grade('any', $this->prompt(), 'does-not-exist');

        self::assertSame('deterministic', $result->grader);
    }

    #[Test]
    public function llmJudgeIsUsedWhenRequested(): void
    {
        $result = $this->service('{"score":0.9,"reason":"good"}')->grade('any', $this->prompt(), 'llm_judge');

        self::assertSame('llm_judge', $result->grader);
        self::assertSame(0.9, $result->score);
    }

    #[Test]
    public function availableGradersListsBothStrategies(): void
    {
        self::assertSame(['deterministic', 'llm_judge'], $this->service()->availableGraders());
    }
}
