<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Timeline;

use Netresearch\NrLlm\Domain\Enum\ToolOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\Timeline\ApprovedCallFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApprovedCallFailure::class)]
final class ApprovedCallFailureTest extends TestCase
{
    private const FAILED = ['toolIsError' => true, 'toolOutcome' => 'failed'];

    /**
     * @return iterable<string, array{list<array{0: string, 1: array<string, mixed>}>, bool}>
     */
    public static function streams(): iterable
    {
        // Run 00241df2 on the demo (NEXT-168), its kinds and the fields read.
        yield 'approved, then the call failed' => [[['request', []], ['llm', []], ['approval', ['approved' => true]], ['tool', self::FAILED], ['request', []], ['llm', []]], true];
        yield 'denied' => [[['llm', []], ['approval', ['approved' => false]], ['tool', self::FAILED], ['llm', []]], false];
        yield 'approved, the call took' => [[['llm', []], ['approval', ['approved' => true]], ['tool', ['toolIsError' => false, 'toolOutcome' => 'ok']], ['llm', []]], false];
        yield 'approved, the call cancelled' => [[['llm', []], ['approval', ['approved' => true]], ['tool', ['toolIsError' => true, 'toolOutcome' => 'cancelled']]], false];
        yield 'failed before any approval' => [[['llm', []], ['tool', self::FAILED], ['llm', []], ['approval', ['approved' => true]], ['tool', ['toolIsError' => false]]], false];
        yield 'failed in a later round of its own' => [[['approval', ['approved' => true]], ['tool', ['toolIsError' => false]], ['llm', []], ['tool', self::FAILED]], false];
        yield 'failed, then a later card denied' => [[['approval', ['approved' => true]], ['tool', self::FAILED], ['llm', []], ['approval', ['approved' => false]], ['tool', self::FAILED], ['llm', []]], false];
        yield 'failed, then a later card approved and its call took' => [[['approval', ['approved' => true]], ['tool', self::FAILED], ['llm', []], ['approval', ['approved' => true]], ['tool', ['toolIsError' => false, 'toolOutcome' => 'ok']], ['llm', []]], false];
        yield 'took, then a later approved call failed' => [[['approval', ['approved' => true]], ['tool', ['toolIsError' => false, 'toolOutcome' => 'ok']], ['llm', []], ['approval', ['approved' => true]], ['tool', self::FAILED], ['llm', []]], true];
        yield 'one approval released two calls, the second failed' => [[['approval', ['approved' => true]], ['tool', ['toolIsError' => false, 'toolOutcome' => 'ok']], ['tool', self::FAILED], ['llm', []]], true];
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $stream
     */
    #[Test]
    #[DataProvider('streams')]
    public function readsWhetherACallAnApprovalReleasedFailed(array $stream, bool $expected): void
    {
        self::assertSame($expected, ApprovedCallFailure::in($stream));
    }

    #[Test]
    public function theStepsOfAResumedRunBeginWithTheReleasedCalls(): void
    {
        $failed = new RunStep(RunStep::KIND_TOOL, 1, 1.0, toolIsError: true, toolOutcome: ToolOutcome::FAILED);
        $answer = new RunStep(RunStep::KIND_LLM, 2, 1.0, finishReason: 'stop');

        self::assertTrue(ApprovedCallFailure::afterApproval([$failed, $answer]));
        self::assertFalse(ApprovedCallFailure::afterApproval([$answer, $failed]));
        self::assertFalse(ApprovedCallFailure::afterApproval([new RunStep(RunStep::KIND_TOOL, 1, 1.0, toolIsError: false, toolOutcome: ToolOutcome::OK), $answer]));
    }
}
