<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\Response;

use Netresearch\NrLlm\Controller\Backend\Response\ContinuationProgress;
use Netresearch\NrLlm\Controller\Backend\Response\NdjsonStream;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the inbox sends while a continuation runs (ADR-276): a line per
 * recorded step, so no read of the proxy waits longer than one step, and
 * nothing but a count, because the person deciding may not be allowed to
 * read the run (ADR-153).
 */
#[CoversClass(ContinuationProgress::class)]
#[CoversClass(NdjsonStream::class)]
final class ContinuationProgressTest extends AbstractUnitTestCase
{
    /** @var list<string> */
    private array $lines = [];

    private int $now = 1000;

    #[Test]
    public function nothingIsWrittenBeforeTheFirstStep(): void
    {
        $progress = $this->progress();

        $progress->settle();

        self::assertFalse($progress->isOpen());
        self::assertSame([], $this->lines, 'a refusal before any step keeps its own response');
    }

    #[Test]
    public function everyRecordedStepIsCountedAndNothingElseIsSent(): void
    {
        $progress = $this->progress();
        $observer = $progress->observer();

        $observer(new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0, content: 'secret answer'));
        $observer(new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolName: 'read_records', toolResult: 'secret record'));
        $observer(new RunStep(kind: RunStep::KIND_LLM, round: 2, durationMs: 1.0, content: 'secret summary'));
        $progress->settle();

        self::assertTrue($progress->isOpen());
        self::assertSame(
            [
                ['event' => 'progress', 'steps' => 1],
                ['event' => 'progress', 'steps' => 2],
                ['event' => 'progress', 'steps' => 3],
                ['event' => 'settled'],
            ],
            $this->decoded(),
        );
        self::assertStringNotContainsString('secret', implode('', $this->lines));
        self::assertStringNotContainsString('read_records', implode('', $this->lines));
    }

    #[Test]
    public function aTextDeltaKeepsTheConnectionAliveAtMostEveryTenSeconds(): void
    {
        $progress = $this->progress();
        $observer = $progress->observer();
        $delta    = new RunStep(kind: RunStep::KIND_TEXT_DELTA, round: 2, durationMs: 0.0, content: 'tok');

        $observer(new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0));
        $this->now += 3;
        $observer($delta);
        $this->now += 8;
        $observer($delta);
        ++$this->now;
        $observer($delta);

        self::assertSame(
            [
                ['event' => 'progress', 'steps' => 1],
                // eleven seconds after the last line, and not counted as a step
                ['event' => 'progress', 'steps' => 1],
            ],
            $this->decoded(),
        );
    }

    #[Test]
    public function openingTheStreamKeepsTheRunGoingWhenTheClientLeaves(): void
    {
        $previous = ignore_user_abort(false);

        try {
            $progress = $this->progress();
            self::assertSame(0, ignore_user_abort(), 'nothing is opened before the first step');

            ($progress->observer())(new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0));

            self::assertSame(1, ignore_user_abort());
        } finally {
            ignore_user_abort((bool) $previous);
        }
    }

    #[Test]
    public function aLineIsPaddedPastTheProxyFlushThreshold(): void
    {
        ($this->progress()->observer())(new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0));

        self::assertGreaterThanOrEqual(NdjsonStream::MIN_LINE_BYTES, strlen($this->lines[0]) - 1);
        self::assertStringEndsWith("\n", $this->lines[0]);
    }

    private function progress(): ContinuationProgress
    {
        return new ContinuationProgress(
            new NdjsonStream(function (string $line): void {
                $this->lines[] = $line;
            }),
            fn (): int => $this->now,
        );
    }

    /**
     * @return list<mixed>
     */
    private function decoded(): array
    {
        return array_map(
            static fn (string $line): mixed => json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR),
            $this->lines,
        );
    }
}
