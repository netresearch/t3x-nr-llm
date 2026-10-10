<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

#[CoversClass(SkillInjectionService::class)]
final class SkillInjectionBoundaryTest extends TestCase
{
    /**
     * @param ChatMessage|array<string, mixed> $system
     * @param ChatMessage|array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('systemTurns')]
    public function lastSystemPlacementReachesIndexZeroAcrossNonsystemSuffixesAndRetainsMetadata(
        ChatMessage|array $system,
        ChatMessage|array $expected,
    ): void {
        $assistant = new ChatMessage(
            'assistant',
            'Reasoned answer.',
            providerItems: [['type' => 'reasoning', 'id' => 'opaque-item']],
        );
        $user = ['role' => 'user', 'content' => 'Next question.', 'name' => 'Editor'];
        $messages = [$system, $assistant, $user];
        $failure = null;
        $actual = null;
        try {
            $actual = SkillInjectionService::appendToLastSystemMessage(
                $messages,
                'Instruction.',
            );
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($failure);
        self::assertEquals([$expected, $assistant, $user], $actual);
        self::assertNotNull($actual);
        self::assertSame(
            $assistant,
            $actual[1],
            'Opaque assistant response items are not rewritten.',
        );
        self::assertSame($user, $actual[2]);
    }

    /**
     * @return iterable<string, array{ChatMessage|array<string, mixed>, ChatMessage|array<string, mixed>}>
     */
    public static function systemTurns(): iterable
    {
        yield 'value object' => [
            ChatMessage::system('Base.'),
            ChatMessage::system("Base.\n\nInstruction."),
        ];
        yield 'plain array' => [
            ['role' => 'system', 'content' => 'Base.', 'name' => 'Policy'],
            [
                'role' => 'system',
                'content' => "Base.\n\nInstruction.",
                'name' => 'Policy',
            ],
        ];
        yield 'part array' => [
            [
                'role' => 'system',
                'content' => [['type' => 'text', 'text' => 'Base.']],
                'name' => 'Policy',
            ],
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'text', 'text' => 'Base.'],
                    ['type' => 'text', 'text' => 'Instruction.'],
                ],
                'name' => 'Policy',
            ],
        ];
    }

    /**
     * @param ChatMessage|array<string, mixed> $system
     * @param ChatMessage|array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('systemTurns')]
    public function firstSystemPlacementPassesAnAssistantButNeverCrossesTheFirstUser(
        ChatMessage|array $system,
        ChatMessage|array $expected,
    ): void {
        $assistant = ChatMessage::assistant('Earlier answer.');
        $user = ChatMessage::user('Question.');
        $failure = null;
        $head = null;
        $late = null;
        try {
            $head = SkillInjectionService::appendToFirstSystemMessage(
                [$assistant, $system, $user],
                'Instruction.',
            );
            $late = SkillInjectionService::appendToFirstSystemMessage(
                [$assistant, $user, $system],
                'Instruction.',
            );
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($failure);
        self::assertEquals([$assistant, $expected, $user], $head);
        self::assertNull(
            $late,
            'A late system message can be evicted and must not carry the approved instruction.',
        );
    }

    /**
     * @param array<string, mixed> $invalid
     */
    #[Test]
    #[DataProvider('invalidUserTurns')]
    public function malformedUserContentIsSkippedForTheNextUsableUserTurn(
        array $invalid,
    ): void {
        $system = ['role' => 'system', 'content' => 'Base.'];
        $user = ['role' => 'user', 'content' => 'Question.', 'name' => 'Editor'];
        $failure = null;
        $actual = null;
        try {
            $actual = $this
                ->subject()
                ->composeIntoMessages([$system, $invalid, $user], [$this->skill()]);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($failure);
        self::assertNotNull($actual);
        self::assertSame($system, $actual['messages'][0]);
        self::assertSame($invalid, $actual['messages'][1]);
        $target = $actual['messages'][2];
        self::assertIsArray($target);
        self::assertSame('Editor', $target['name']);
        self::assertIsString($target['content']);
        self::assertStringContainsString(
            'Always cite sources.',
            $target['content'],
        );
        self::assertStringEndsWith("\n\nQuestion.", $target['content']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidUserTurns(): iterable
    {
        yield 'associative parts' => [
            [
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => 'Not a part list.'],
            ],
        ];
        yield 'missing content' => [['role' => 'user']];
        yield 'nontext scalar' => [['role' => 'user', 'content' => 42]];
    }

    #[Test]
    public function emptyCompositionPreservesPartMessagesAndReportsNoInstructionsOrPins(): void
    {
        $messages = [
            [
                'role' => 'system',
                'content' => [['type' => 'text', 'text' => 'Base.']],
            ],
            ChatMessage::assistant('Earlier answer.'),
            ChatMessage::user('Question.'),
        ];

        self::assertSame(
            ['messages' => $messages, 'instructions' => '', 'pins' => []],
            $this->subject()->composeIntoMessages($messages, []),
        );
    }

    #[Test]
    public function missingUserPreservesTheEntireMessageListAndFencedSkillsCannotEnterSystemRole(): void
    {
        $messages = [
            ChatMessage::system('Base.'),
            ChatMessage::assistant('Earlier answer.'),
        ];

        self::assertSame(
            ['messages' => $messages, 'instructions' => '', 'pins' => []],
            $this->subject()->composeIntoMessages($messages, [$this->skill()]),
        );
    }

    #[Test]
    public function valueObjectUserReceivesTheBlockBeforeTheOriginalTextWithABlankLine(): void
    {
        $original = ChatMessage::user('Question.');
        $actual = $this->subject()->composeIntoMessages([$original], [$this->skill()]);

        self::assertInstanceOf(ChatMessage::class, $actual['messages'][0]);
        self::assertTrue($actual['messages'][0]->isUser());
        self::assertStringContainsString(
            'Always cite sources.',
            $actual['messages'][0]->content,
        );
        self::assertStringEndsWith(
            "\n\nQuestion.",
            $actual['messages'][0]->content,
        );
        self::assertSame(
            'Question.',
            $original->content,
            'The immutable caller message is unchanged.',
        );
    }

    private function subject(): SkillInjectionService
    {
        return new SkillInjectionService(new SkillComposer(), new NullLogger());
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->setIdentifier('guide');
        $skill->setName('Guide');
        $skill->setBody('Always cite sources.');
        $skill->setBodyChecksum(
            'c27b8af2a6c35723707e7d7925148f631c8c8d1c085328bd1c297fc2ab6639d9',
        );
        $skill->setEnabled(true);

        return $skill;
    }
}
