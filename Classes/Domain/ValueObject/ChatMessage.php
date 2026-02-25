<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Value Object representing a chat message.
 *
 * Immutable representation of a message in a chat conversation,
 * consisting of a role (system, user, assistant) and content.
 */
final readonly class ChatMessage implements JsonSerializable
{
    private const array VALID_ROLES = ['system', 'user', 'assistant', 'tool'];

    /**
     * @param string $role    The message role (system, user, assistant, tool)
     * @param string $content The message content
     */
    public function __construct(
        public string $role,
        public string $content,
    ) {
        if (!in_array($this->role, self::VALID_ROLES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid role "%s". Valid roles: %s', $this->role, implode(', ', self::VALID_ROLES)),
                1736502001,
            );
        }
    }

    /**
     * Create a system message.
     */
    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    /**
     * Create a user message.
     */
    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * Create a tool message.
     */
    public static function tool(string $content): self
    {
        return new self('tool', $content);
    }

    /**
     * Create from array.
     *
     * @param array{role: string, content: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'],
        );
    }

    /**
     * Convert to array.
     *
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }

    /**
     * @return array{role: string, content: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Check if this is a system message.
     */
    public function isSystem(): bool
    {
        return $this->role === 'system';
    }

    /**
     * Check if this is a user message.
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Check if this is an assistant message.
     */
    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * Check if this is a tool message.
     */
    public function isTool(): bool
    {
        return $this->role === 'tool';
    }

    /**
     * Get valid roles.
     *
     * @return list<string>
     */
    public static function getValidRoles(): array
    {
        return self::VALID_ROLES;
    }
}
