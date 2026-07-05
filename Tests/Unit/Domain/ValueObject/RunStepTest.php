<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunStep::class)]
final class RunStepTest extends TestCase
{
    #[Test]
    public function toArrayAlwaysCarriesKindRoundAndRoundedDuration(): void
    {
        $step = new RunStep(kind: RunStep::KIND_LLM, round: 2, durationMs: 12.3456);
        $array = $step->toArray();

        self::assertSame('llm', $array['kind']);
        self::assertSame(2, $array['round']);
        self::assertSame(12.35, $array['durationMs']);
    }

    #[Test]
    public function toArrayDropsNullFieldsSoEachKindOnlyEmitsItsOwnKeys(): void
    {
        $step = new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 5.0, toolName: 'fetch', toolResult: 'ok', toolIsError: false);
        $array = $step->toArray();

        self::assertSame('fetch', $array['toolName']);
        self::assertSame('ok', $array['toolResult']);
        self::assertFalse($array['toolIsError']);
        // LLM-only keys must be absent for a tool step.
        self::assertArrayNotHasKey('content', $array);
        self::assertArrayNotHasKey('promptTokens', $array);
        self::assertArrayNotHasKey('messagesSent', $array);
    }

    #[Test]
    public function toArrayKeepsZeroAndFalseButNotNull(): void
    {
        $step = new RunStep(
            kind: RunStep::KIND_LLM,
            round: 1,
            durationMs: 0.0,
            promptTokens: 0,
            toolIsError: false,
        );
        $array = $step->toArray();

        // Zero and false are meaningful and must survive the null filter.
        self::assertSame(0, $array['promptTokens']);
        self::assertArrayHasKey('toolIsError', $array);
        self::assertArrayNotHasKey('thinking', $array);
    }
}
