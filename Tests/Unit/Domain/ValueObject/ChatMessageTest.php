<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

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

        new ChatMessage($invalidRole, 'content');
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
            new ChatMessage('invalid', 'content');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('system', $e->getMessage());
            self::assertStringContainsString('user', $e->getMessage());
            self::assertStringContainsString('assistant', $e->getMessage());
            self::assertStringContainsString('tool', $e->getMessage());
        }
    }
}
