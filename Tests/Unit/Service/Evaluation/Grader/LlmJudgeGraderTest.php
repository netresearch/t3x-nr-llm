<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Grader;

use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\Grader\LlmJudgeGrader;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmJudgeGrader::class)]
final class LlmJudgeGraderTest extends TestCase
{
    private function prompt(): GoldenPrompt
    {
        return new GoldenPrompt('p', 'What is 2+2?', [Assertion::contains('4')], null, '4');
    }

    private function grader(string $judgeContent, bool $throw = false, float $threshold = 0.6): LlmJudgeGrader
    {
        return new LlmJudgeGrader(new StaticCompletionService($judgeContent, 'judge-model', $throw), $threshold);
    }

    #[Test]
    public function identifierIsLlmJudge(): void
    {
        self::assertSame('llm_judge', $this->grader('{"score":1}')->getIdentifier());
    }

    #[Test]
    public function parsesScoreAndReason(): void
    {
        $result = $this->grader('{"score": 0.8, "reason": "mostly correct"}')->grade('4', $this->prompt());

        self::assertTrue($result->passed);
        self::assertSame(0.8, $result->score);
        self::assertSame('mostly correct', $result->reason);
        self::assertSame('llm_judge', $result->grader);
    }

    #[Test]
    public function scoreBelowThresholdDoesNotPass(): void
    {
        $result = $this->grader('{"score": 0.3}')->grade('nope', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.3, $result->score);
    }

    #[Test]
    public function scoreAboveOneIsClampedToOne(): void
    {
        $result = $this->grader('{"score": 1.7}')->grade('4', $this->prompt());

        self::assertSame(1.0, $result->score);
        self::assertTrue($result->passed);
    }

    #[Test]
    public function negativeScoreIsClampedToZero(): void
    {
        $result = $this->grader('{"score": -0.4}')->grade('4', $this->prompt());

        self::assertSame(0.0, $result->score);
        self::assertFalse($result->passed);
    }

    #[Test]
    public function unparseableJudgeResponseFailsGracefully(): void
    {
        $result = $this->grader('the response looks fine to me')->grade('4', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertStringContainsString('parseable', $result->reason);
    }

    #[Test]
    public function jsonWithoutScoreKeyFailsGracefully(): void
    {
        $result = $this->grader('{"verdict": "good"}')->grade('4', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function judgeTransportErrorFailsGracefully(): void
    {
        $result = $this->grader('irrelevant', throw: true)->grade('4', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertStringContainsString('Judge call failed', $result->reason);
    }
}
