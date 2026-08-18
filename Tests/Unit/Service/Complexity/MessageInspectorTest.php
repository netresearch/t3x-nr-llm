<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Complexity;

use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Complexity\MessageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one definition of "a turn", "tool traffic" and "payload bytes" that both
 * the pre-routing fact set and the post-fit complexity record read by (ADR-174).
 *
 * These tests exist because the two records are written to one row in order to
 * be compared: a second, subtly different definition would break the comparison
 * without breaking either caller's own tests.
 */
#[CoversClass(MessageInspector::class)]
final class MessageInspectorTest extends TestCase
{
    #[Test]
    public function bothMessageShapesAnswerTheSameWay(): void
    {
        $inspector = new MessageInspector();

        $typed = [ChatMessage::system('sys'), ChatMessage::user('hello')];
        $array = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'hello'],
        ];

        self::assertSame($inspector->turnCount($typed), $inspector->turnCount($array));
        self::assertSame($inspector->payloadBytes($typed), $inspector->payloadBytes($array));
        self::assertSame(1, $inspector->turnCount($typed));
        self::assertSame(8, $inspector->payloadBytes($typed));
    }

    #[Test]
    public function anAssistantMessageRequestingCallsIsToolTraffic(): void
    {
        $inspector = new MessageInspector();

        self::assertTrue($inspector->carriesToolTraffic([
            ChatMessage::assistantToolCalls([new ToolCall('call-1', 'list_pages', [])]),
        ]));
        self::assertTrue($inspector->carriesToolTraffic([
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call-1']]],
        ]));
        self::assertFalse($inspector->carriesToolTraffic([ChatMessage::user('hello')]));
    }

    #[Test]
    public function aToolResultIsToolTrafficWithoutAnySchemaOnTheWire(): void
    {
        self::assertTrue((new MessageInspector())->carriesToolTraffic([
            ['role' => 'tool', 'content' => '{"ok":true}'],
        ]));
    }

    #[Test]
    public function toolTrafficOutranksTheTurnCountWhenNamingTheShape(): void
    {
        $inspector = new MessageInspector();

        self::assertSame(RequestShape::TOOL_ASSISTED, $inspector->shape(9, true));
        self::assertSame(RequestShape::MULTI_TURN, $inspector->shape(2, false));
        self::assertSame(RequestShape::SINGLE_TURN, $inspector->shape(1, false));
        self::assertSame(RequestShape::SINGLE_TURN, $inspector->shape(0, false));
    }

    /**
     * Non-string content (a multimodal part list) contributes no bytes rather
     * than throwing — the measurement is an observation and must never be the
     * thing that fails a call.
     */
    #[Test]
    public function nonStringContentContributesNothing(): void
    {
        self::assertSame(0, (new MessageInspector())->payloadBytes([
            ['role' => 'user', 'content' => [['type' => 'image_url']]],
        ]));
    }
}
