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
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LlmJudgeGrader::class)]
final class LlmJudgeGraderTest extends TestCase
{
    private function prompt(): GoldenPrompt
    {
        return new GoldenPrompt('p', 'What is 2+2?', [Assertion::contains('4')], null, '4');
    }

    /**
     * Build a grader whose completion service returns $verdict from
     * completeStructured() — since ADR-128 the judge receives the decoded,
     * schema-validated array, never raw content.
     *
     * @param array<string, mixed> $verdict
     */
    private function grader(array $verdict, float $threshold = 0.6): LlmJudgeGrader
    {
        $completionService = self::createStub(CompletionServiceInterface::class);
        $completionService->method('completeStructured')->willReturn($verdict);

        return new LlmJudgeGrader($completionService, $threshold);
    }

    private function throwingGrader(): LlmJudgeGrader
    {
        $completionService = self::createStub(CompletionServiceInterface::class);
        $completionService
            ->method('completeStructured')
            ->willThrowException(new RuntimeException('provider unavailable', 1794000099));

        return new LlmJudgeGrader($completionService);
    }

    #[Test]
    public function identifierIsLlmJudge(): void
    {
        self::assertSame('llm_judge', $this->grader(['score' => 1])->getIdentifier());
    }

    #[Test]
    public function parsesScoreAndReason(): void
    {
        $result = $this->grader(['score' => 0.85, 'reason' => 'mostly correct'])->grade('4', $this->prompt());

        self::assertTrue($result->passed);
        self::assertSame(0.85, $result->score);
        self::assertSame('mostly correct', $result->reason);
        self::assertSame('llm_judge', $result->grader);
    }

    #[Test]
    public function scoreBelowThresholdDoesNotPass(): void
    {
        $result = $this->grader(['score' => 0.3])->grade('nope', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.3, $result->score);
    }

    #[Test]
    public function scoreAboveOneIsClampedToOne(): void
    {
        // Characterization: a judge scoring "8.5" on an imagined 10-scale is
        // clamped to 1.0 and passes — the schema deliberately carries no
        // numeric bounds so the clamp stays the authority (ADR-128).
        $result = $this->grader(['score' => 8.5])->grade('4', $this->prompt());

        self::assertSame(1.0, $result->score);
        self::assertTrue($result->passed);
    }

    #[Test]
    public function negativeScoreIsClampedToZero(): void
    {
        $result = $this->grader(['score' => -0.4])->grade('4', $this->prompt());

        self::assertSame(0.0, $result->score);
        self::assertFalse($result->passed);
    }

    #[Test]
    public function nonNumericScoreFailsGracefully(): void
    {
        $result = $this->grader(['score' => 'abc', 'reason' => 'confused judge'])->grade('4', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertStringContainsString('parseable', $result->reason);
    }

    #[Test]
    public function verdictWithoutScoreKeyFailsGracefully(): void
    {
        $result = $this->grader(['verdict' => 'good'])->grade('4', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertStringContainsString('parseable', $result->reason);
    }

    #[Test]
    public function judgeTransportErrorFailsGracefully(): void
    {
        $result = $this->throwingGrader()->grade('4', $this->prompt());

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertStringContainsString('Judge call failed', $result->reason);
    }
}
