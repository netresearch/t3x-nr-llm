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

    /**
     * An output buffer started without the removable flag cannot be ended:
     * ob_end_flush() fails and the level stays where it was. Opening the stream
     * must leave such a buffer in place instead of trying for ever.
     *
     * In a PHP process of its own, because nothing can remove that buffer
     * afterwards, PHPUnit's own check included. Its time limit turns a loop that
     * never ends into a failed exit.
     */
    #[Test]
    public function aBufferThatCannotBeRemovedDoesNotHoldTheStreamOpen(): void
    {
        $autoload = dirname(__DIR__, 5) . '/.Build/vendor/autoload.php';
        self::assertFileExists($autoload);

        $script = sprintf(
            <<<'PHP'
                require %s;
                set_time_limit(5);
                ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);
                $level = ob_get_level();
                (new %s())->line(['event' => 'progress', 'steps' => 1]);
                fwrite(STDERR, ob_get_level() === $level && str_contains((string) ob_get_contents(), '"event":"progress"') ? 'written' : 'lost');
                PHP,
            var_export($autoload, true),
            '\\' . NdjsonStream::class,
        );

        // No error display: a notice per failed attempt would fill the stderr
        // pipe, block the child on the write and keep the time limit from firing.
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('written', $stderr, 'the line lands in the buffer that stays');
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
