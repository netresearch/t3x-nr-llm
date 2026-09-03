<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Enum\MessageRole;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\InvalidArgumentException as NrLlmInvalidArgumentException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * MessageRole is a backed enum and its directory is excluded from
 * the coverage source set, so PHPUnit 12 rejects #[CoversClass(MessageRole::class)]
 * with a "not a valid target for code coverage" warning. Coverage for
 * ChatMessage itself stays attributed below.
 */
#[CoversClass(ChatMessage::class)]
class ChatMessageTest extends AbstractUnitTestCase
{
    // ──────────────────────────────────────────────
    // Constructor
    // ──────────────────────────────────────────────

    #[Test]
    public function constructorSetsRoleAndContent(): void
    {
        $content = $this->faker->sentence();

        $message = new ChatMessage('user', $content);

        self::assertSame('user', $message->role);
        self::assertSame($content, $message->content);
    }

    #[Test]
    #[DataProvider('validRoleProvider')]
    public function constructorAcceptsValidRole(string $role): void
    {
        $message = new ChatMessage($role, 'some content');

        self::assertSame($role, $message->role);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validRoleProvider(): array
    {
        return [
            'system' => ['system'],
            'user' => ['user'],
            'assistant' => ['assistant'],
            'tool' => ['tool'],
        ];
    }

    #[Test]
    #[DataProvider('invalidRoleProvider')]
    public function constructorThrowsForInvalidRole(string $invalidRole): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1736502001);
        $this->expectExceptionMessage(sprintf('Invalid role "%s"', $invalidRole));

        self::assertInstanceOf(ChatMessage::class, new ChatMessage($invalidRole, 'content'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRoleProvider(): array
    {
        return [
            'empty string' => [''],
            'uppercase' => ['USER'],
            'unknown role' => ['moderator'],
            'numeric' => ['123'],
            'with spaces' => ['user '],
        ];
    }

    #[Test]
    public function constructorAllowsEmptyContent(): void
    {
        $message = new ChatMessage('user', '');

        self::assertSame('', $message->content);
    }

    #[Test]
    public function constructorAcceptsMessageRoleEnumDirectly(): void
    {
        $content = $this->faker->sentence();

        $message = new ChatMessage(MessageRole::ASSISTANT, $content);

        self::assertSame('assistant', $message->role);
        self::assertSame(MessageRole::ASSISTANT, $message->getRole());
        self::assertSame($content, $message->content);
    }

    #[Test]
    public function getRoleReturnsTypedEnumForStringConstruction(): void
    {
        $message = new ChatMessage('tool', 'output');

        self::assertSame(MessageRole::TOOL, $message->getRole());
    }

    // ──────────────────────────────────────────────
    // Factory methods
    // ──────────────────────────────────────────────

    #[Test]
    public function systemFactoryCreatesSystemMessage(): void
    {
        $content = $this->faker->sentence();

        $message = ChatMessage::system($content);

        self::assertSame('system', $message->role);
        self::assertSame($content, $message->content);
    }

    #[Test]
    public function userFactoryCreatesUserMessage(): void
    {
        $content = $this->faker->sentence();

        $message = ChatMessage::user($content);

        self::assertSame('user', $message->role);
        self::assertSame($content, $message->content);
    }

    #[Test]
    public function assistantFactoryCreatesAssistantMessage(): void
    {
        $content = $this->faker->sentence();

        $message = ChatMessage::assistant($content);

        self::assertSame('assistant', $message->role);
        self::assertSame($content, $message->content);
    }

    #[Test]
    public function toolFactoryCreatesToolMessage(): void
    {
        $content = $this->faker->sentence();

        $message = ChatMessage::tool($content);

        self::assertSame('tool', $message->role);
        self::assertSame($content, $message->content);
    }

    // ──────────────────────────────────────────────
    // fromArray / toArray
    // ──────────────────────────────────────────────

    #[Test]
    public function fromArrayCreatesMessageFromValidArray(): void
    {
        $content = $this->faker->sentence();

        $message = ChatMessage::fromArray([
            'role' => 'assistant',
            'content' => $content,
        ]);

        self::assertSame('assistant', $message->role);
        self::assertSame($content, $message->content);
    }

    #[Test]
    public function fromArrayThrowsForInvalidRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1736502001);

        ChatMessage::fromArray([
            'role' => 'invalid',
            'content' => 'content',
        ]);
    }

    #[Test]
    public function fromArrayThrowsWhenRoleKeyIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1736502002);

        ChatMessage::fromArray(['content' => 'content']);
    }

    #[Test]
    public function fromArrayThrowsWhenRoleIsNotString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1736502002);

        ChatMessage::fromArray(['role' => 123, 'content' => 'content']);
    }

    #[Test]
    public function fromArrayThrowsWhenContentKeyIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1736502003);

        ChatMessage::fromArray(['role' => 'user']);
    }

    #[Test]
    public function fromArrayThrowsWhenContentIsNotString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1736502003);

        ChatMessage::fromArray(['role' => 'user', 'content' => ['not', 'a', 'string']]);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $content = $this->faker->sentence();
        $message = ChatMessage::user($content);

        $array = $message->toArray();

        self::assertSame([
            'role' => 'user',
            'content' => $content,
        ], $array);
    }

    #[Test]
    public function fromArrayAndToArrayRoundTrip(): void
    {
        $data = [
            'role' => 'system',
            'content' => $this->faker->paragraph(),
        ];

        $message = ChatMessage::fromArray($data);
        $result = $message->toArray();

        self::assertSame($data, $result);
    }

    // ──────────────────────────────────────────────
    // JSON serialization
    // ──────────────────────────────────────────────

    #[Test]
    public function jsonSerializeReturnsToArrayResult(): void
    {
        $content = $this->faker->sentence();
        $message = ChatMessage::assistant($content);

        self::assertSame($message->toArray(), $message->jsonSerialize());
    }

    #[Test]
    public function jsonEncodeProducesExpectedJson(): void
    {
        $message = ChatMessage::user('Hello, world!');

        $json = json_encode($message, JSON_THROW_ON_ERROR);

        self::assertSame('{"role":"user","content":"Hello, world!"}', $json);
    }

    // ──────────────────────────────────────────────
    // Role check methods
    // ──────────────────────────────────────────────

    #[Test]
    public function isSystemReturnsTrueOnlyForSystemRole(): void
    {
        self::assertTrue(ChatMessage::system('x')->isSystem());
        self::assertFalse(ChatMessage::user('x')->isSystem());
        self::assertFalse(ChatMessage::assistant('x')->isSystem());
        self::assertFalse(ChatMessage::tool('x')->isSystem());
    }

    #[Test]
    public function isUserReturnsTrueOnlyForUserRole(): void
    {
        self::assertFalse(ChatMessage::system('x')->isUser());
        self::assertTrue(ChatMessage::user('x')->isUser());
        self::assertFalse(ChatMessage::assistant('x')->isUser());
        self::assertFalse(ChatMessage::tool('x')->isUser());
    }

    #[Test]
    public function isAssistantReturnsTrueOnlyForAssistantRole(): void
    {
        self::assertFalse(ChatMessage::system('x')->isAssistant());
        self::assertFalse(ChatMessage::user('x')->isAssistant());
        self::assertTrue(ChatMessage::assistant('x')->isAssistant());
        self::assertFalse(ChatMessage::tool('x')->isAssistant());
    }

    #[Test]
    public function isToolReturnsTrueOnlyForToolRole(): void
    {
        self::assertFalse(ChatMessage::system('x')->isTool());
        self::assertFalse(ChatMessage::user('x')->isTool());
        self::assertFalse(ChatMessage::assistant('x')->isTool());
        self::assertTrue(ChatMessage::tool('x')->isTool());
    }

    // ──────────────────────────────────────────────
    // getValidRoles
    // ──────────────────────────────────────────────

    #[Test]
    public function getValidRolesReturnsAllFourRoles(): void
    {
        $roles = ChatMessage::getValidRoles();

        self::assertCount(4, $roles);
        self::assertContains('system', $roles);
        self::assertContains('user', $roles);
        self::assertContains('assistant', $roles);
        self::assertContains('tool', $roles);
    }

    // ──────────────────────────────────────────────
    // Immutability
    // ──────────────────────────────────────────────

    #[Test]
    public function messageIsImmutable(): void
    {
        $message = ChatMessage::user('original content');

        // readonly class: properties cannot be modified after construction
        self::assertSame('user', $message->role);
        self::assertSame('original content', $message->content);
    }

    // ──────────────────────────────────────────────
    // Error message quality
    // ──────────────────────────────────────────────

    #[Test]
    public function invalidRoleExceptionIncludesValidRoles(): void
    {
        try {
            self::assertInstanceOf(ChatMessage::class, new ChatMessage('invalid', 'content'));
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('system', $e->getMessage());
            self::assertStringContainsString('user', $e->getMessage());
            self::assertStringContainsString('assistant', $e->getMessage());
            self::assertStringContainsString('tool', $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    // Tool turns: assistantToolCalls / toolResult
    // ──────────────────────────────────────────────

    #[Test]
    public function assistantToolCallsFactoryCreatesAssistantTurnWithToolCalls(): void
    {
        $call = new ToolCall('call_1', 'get_weather', ['location' => 'Leipzig']);

        $message = ChatMessage::assistantToolCalls([$call], 'checking the weather');

        self::assertSame('assistant', $message->role);
        self::assertSame('checking the weather', $message->content);
        self::assertSame([$call], $message->toolCalls);
        self::assertNull($message->toolCallId);
    }

    #[Test]
    public function assistantToolCallsFactoryDefaultsNullContentToEmptyString(): void
    {
        $message = ChatMessage::assistantToolCalls([new ToolCall('call_1', 'get_weather', [])]);

        self::assertSame('', $message->content);
    }

    #[Test]
    public function toolResultFactoryCreatesToolTurn(): void
    {
        $message = ChatMessage::toolResult('call_1', '{"temp": 20}');

        self::assertSame('tool', $message->role);
        self::assertSame('{"temp": 20}', $message->content);
        self::assertSame('call_1', $message->toolCallId);
        self::assertNull($message->toolCalls);
    }

    #[Test]
    public function toolResultFactoryRejectsEmptyToolCallId(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400005);

        ChatMessage::toolResult('', 'result');
    }

    #[Test]
    public function constructorRejectsToolCallsOnNonAssistantRole(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400001);

        new ChatMessage('user', 'content', [new ToolCall('call_1', 'get_weather', [])]);
    }

    #[Test]
    public function constructorRejectsEmptyToolCallsList(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400002);

        new ChatMessage('assistant', 'content', []);
    }

    #[Test]
    public function constructorRejectsNonToolCallElements(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400003);

        new ChatMessage('assistant', 'content', [['id' => 'call_1']]);
    }

    #[Test]
    public function constructorRejectsToolCallIdOnNonToolRole(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400004);

        new ChatMessage('assistant', 'content', null, 'call_1');
    }

    // ──────────────────────────────────────────────
    // Tool turns: wire shape (toArray / jsonSerialize)
    // ──────────────────────────────────────────────

    #[Test]
    public function toArrayEmitsOpenAiWireShapeForAssistantToolCalls(): void
    {
        $message = ChatMessage::assistantToolCalls(
            [new ToolCall('call_1', 'get_weather', ['location' => 'Leipzig'])],
            'looking it up',
        );

        self::assertSame([
            'role' => 'assistant',
            'content' => 'looking it up',
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'arguments' => '{"location":"Leipzig"}',
                    ],
                ],
            ],
        ], $message->toArray());
    }

    #[Test]
    public function toArrayEncodesEmptyArgumentsAsJsonObjectNotArray(): void
    {
        $message = ChatMessage::assistantToolCalls([new ToolCall('call_1', 'ping', [])]);

        $array = $message->toArray();
        $calls = $array['tool_calls'] ?? [];
        assert(isset($calls[0]['function']['arguments']));

        self::assertSame('{}', $calls[0]['function']['arguments']);
    }

    #[Test]
    public function toArrayEmitsToolCallIdForToolResult(): void
    {
        $message = ChatMessage::toolResult('call_1', 'LOGS');

        self::assertSame([
            'role' => 'tool',
            'content' => 'LOGS',
            'tool_call_id' => 'call_1',
        ], $message->toArray());
    }

    #[Test]
    public function jsonSerializeFollowsToArrayForToolTurns(): void
    {
        $assistant = ChatMessage::assistantToolCalls([new ToolCall('call_1', 'ping', [])]);
        $tool = ChatMessage::toolResult('call_1', 'pong');

        self::assertSame($assistant->toArray(), $assistant->jsonSerialize());
        self::assertSame($tool->toArray(), $tool->jsonSerialize());
    }

    // ──────────────────────────────────────────────
    // Tool turns: fromArray round-trips
    // ──────────────────────────────────────────────

    #[Test]
    public function fromArrayRoundTripsAssistantToolCallsWireShape(): void
    {
        $original = ChatMessage::assistantToolCalls(
            [new ToolCall('call_1', 'get_weather', ['location' => 'Leipzig'])],
            'looking it up',
        );

        $rebuilt = ChatMessage::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
        self::assertNotNull($rebuilt->toolCalls);
        self::assertSame('get_weather', $rebuilt->toolCalls[0]->name);
        // ToolCall::fromArray() decodes the JSON-string arguments back to a map.
        self::assertSame(['location' => 'Leipzig'], $rebuilt->toolCalls[0]->arguments);
    }

    #[Test]
    public function fromArrayRoundTripsToolResultWireShape(): void
    {
        $original = ChatMessage::toolResult('call_1', 'LOGS');

        $rebuilt = ChatMessage::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
        self::assertSame('call_1', $rebuilt->toolCallId);
    }

    #[Test]
    public function fromArrayAcceptsNullContentAlongsideToolCalls(): void
    {
        // Providers send `content: null` on assistant tool-call turns.
        $message = ChatMessage::fromArray([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ping', 'arguments' => '{}']],
            ],
        ]);

        self::assertSame('', $message->content);
        self::assertNotNull($message->toolCalls);
        self::assertSame('ping', $message->toolCalls[0]->name);
    }

    #[Test]
    public function fromArrayRejectsNonArrayToolCalls(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400006);

        ChatMessage::fromArray(['role' => 'assistant', 'content' => '', 'tool_calls' => 'nope']);
    }

    #[Test]
    public function fromArrayRejectsNonArrayToolCallElement(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400007);

        ChatMessage::fromArray(['role' => 'assistant', 'content' => '', 'tool_calls' => ['nope']]);
    }

    #[Test]
    public function fromArrayRejectsNonStringToolCallId(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1752400008);

        ChatMessage::fromArray(['role' => 'tool', 'content' => 'x', 'tool_call_id' => 123]);
    }

    #[Test]
    public function fromArrayStillRejectsNullContentWithoutToolCalls(): void
    {
        $this->expectException(NrLlmInvalidArgumentException::class);
        $this->expectExceptionCode(1736502003);

        ChatMessage::fromArray(['role' => 'user', 'content' => null]);
    }
}
