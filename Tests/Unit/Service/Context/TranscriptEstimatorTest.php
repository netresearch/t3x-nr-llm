<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranscriptEstimator::class)]
final class TranscriptEstimatorTest extends TestCase
{
    private TranscriptEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new TranscriptEstimator();
    }

    #[Test]
    public function addingAMessageNeverLowersTheEstimate(): void
    {
        $one = [ChatMessage::user('a short prompt')];
        $two = [ChatMessage::user('a short prompt'), ChatMessage::user('another one')];

        self::assertGreaterThanOrEqual(
            $this->estimator->estimate($one, [], 1.0),
            $this->estimator->estimate($two, [], 1.0),
        );
    }

    #[Test]
    public function overCountsProseVersusTheHouseFourCharsPerToken(): void
    {
        $text     = str_repeat('word ', 200);
        $estimate = $this->estimator->estimate([ChatMessage::user($text)], [], 1.0);

        // Divisor 3.5 (+ per-message overhead) over-counts vs a flat chars/4.
        self::assertGreaterThanOrEqual((int)ceil(strlen($text) / 4), $estimate);
    }

    #[Test]
    public function denseToolResultContentUsesTheDenserDivisor(): void
    {
        $json = str_repeat('{"k":"v"}', 100);

        $asToolResult = $this->estimator->estimate([ChatMessage::toolResult('call_1', $json)], [], 1.0);
        $asUserProse  = $this->estimator->estimate([ChatMessage::user($json)], [], 1.0);

        // Same bytes weigh MORE as a tool_result (2.5 divisor) than as prose (3.5).
        self::assertGreaterThan($asUserProse, $asToolResult);
    }

    #[Test]
    public function toolSchemaBytesAreCountedOnlyWhenPresent(): void
    {
        $messages  = [ChatMessage::user('go')];
        $without   = $this->estimator->estimate($messages, [], 1.0);
        $withSpecs = $this->estimator->estimate($messages, [['type' => 'function', 'function' => ['name' => 'fetch', 'parameters' => ['type' => 'object']]]], 1.0);

        self::assertGreaterThan($without, $withSpecs);
    }

    #[Test]
    public function toolCallArgumentsAreCountedAsDense(): void
    {
        $call     = new ToolCall('call_1', 'fetch', ['q' => str_repeat('x', 400)]);
        $estimate = $this->estimator->estimate([ChatMessage::assistantToolCalls([$call], null)], [], 1.0);

        self::assertGreaterThan(100, $estimate);
    }

    #[Test]
    public function calibrationScalesUpNeverBelowTheRawEstimate(): void
    {
        $messages = [ChatMessage::user(str_repeat('token ', 100))];
        $raw      = $this->estimator->estimate($messages, [], 1.0);

        self::assertSame($raw, $this->estimator->estimate($messages, [], 0.5), 'calibration < 1 is floored to 1');
        self::assertGreaterThan($raw, $this->estimator->estimate($messages, [], 2.0));
    }
}
