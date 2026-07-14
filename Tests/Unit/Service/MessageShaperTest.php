<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\MessageShaper;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MessageShaper::class)]
class MessageShaperTest extends AbstractUnitTestCase
{
    private MessageShaper $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MessageShaper();
    }

    #[Test]
    public function normalisePassesTypedMessagesThroughUnchanged(): void
    {
        $message = ChatMessage::user('Hello');

        $result = $this->subject->normalise([$message]);

        self::assertSame([$message], $result);
    }

    #[Test]
    public function normaliseConvertsSimpleRoleContentArrayToChatMessage(): void
    {
        $result = $this->subject->normalise([['role' => 'user', 'content' => 'Hi']]);

        self::assertInstanceOf(ChatMessage::class, $result[0]);
        self::assertSame('user', $result[0]->role);
        self::assertSame('Hi', $result[0]->content);
    }

    #[Test]
    public function normaliseLeavesRichArraysUntouched(): void
    {
        // A third key (name) means the shape is richer than {role, content};
        // it must survive as an array so provider-specific fields are not lost.
        $rich = ['role' => 'tool', 'content' => 'result', 'name' => 'fetch_logs'];

        $result = $this->subject->normalise([$rich]);

        self::assertSame([$rich], $result);
    }

    #[Test]
    public function normaliseLeavesNonStringRoleContentArraysUntouched(): void
    {
        $message = ['role' => 'user', 'content' => ['type' => 'text']];

        $result = $this->subject->normalise([$message]);

        self::assertSame([$message], $result);
    }

    #[Test]
    public function normaliseReindexesTheList(): void
    {
        $result = $this->subject->normalise([5 => ['role' => 'user', 'content' => 'Hi']]);

        self::assertArrayHasKey(0, $result);
    }

    #[Test]
    public function applySystemPromptReturnsMessagesUnchangedWhenNoSystemPromptOption(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        self::assertSame($messages, $this->subject->applySystemPrompt($messages, []));
    }

    #[Test]
    public function applySystemPromptReturnsMessagesUnchangedWhenPromptIsEmpty(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        self::assertSame($messages, $this->subject->applySystemPrompt($messages, ['system_prompt' => '']));
    }

    #[Test]
    public function applySystemPromptPrependsSystemMessageWhenNonePresent(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $result = $this->subject->applySystemPrompt($messages, ['system_prompt' => 'Be terse.']);

        self::assertCount(2, $result);
        self::assertInstanceOf(ChatMessage::class, $result[0]);
        self::assertTrue($result[0]->isSystem());
        self::assertSame('Be terse.', $result[0]->content);
    }

    #[Test]
    public function applySystemPromptDoesNotPrependWhenArraySystemMessagePresent(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Caller prompt'],
            ['role' => 'user', 'content' => 'Hi'],
        ];

        $result = $this->subject->applySystemPrompt($messages, ['system_prompt' => 'Config prompt']);

        self::assertSame($messages, $result);
    }

    #[Test]
    public function applySystemPromptDoesNotPrependWhenTypedSystemMessagePresent(): void
    {
        $messages = [ChatMessage::system('Caller prompt'), ChatMessage::user('Hi')];

        $result = $this->subject->applySystemPrompt($messages, ['system_prompt' => 'Config prompt']);

        self::assertSame($messages, $result);
    }
}
