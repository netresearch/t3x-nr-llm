<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;
use Netresearch\NrLlm\Domain\Enum\MessageRole;

/**
 * Value Object representing a chat message.
 *
 * Immutable representation of a message in a chat conversation,
 * consisting of a role (system, user, assistant, tool) and content.
 *
 * The constructor accepts either the `MessageRole` enum or a raw string;
 * unknown strings are rejected with the same `InvalidArgumentException`
 * code as before this class moved to the enum so downstream catches stay
 * stable. The public `string $role` field is preserved for back-compat —
 * it is sourced from the enum's value, so the two cannot drift. New
 * code is encouraged to read `getRole(): MessageRole` instead of the
 * string field.
 */
final readonly class ChatMessage implements JsonSerializable
{
    public string $role;

    /**
     * @param string|MessageRole $role Either a backed enum value or its
     *                                 string equivalent — the legacy
     *                                 string form is preserved so
     *                                 existing call sites do not need
     *                                 to migrate immediately.
     */
    public function __construct(
        string|MessageRole $role,
        public string $content,
    ) {
        $resolved = $role instanceof MessageRole ? $role : MessageRole::tryFrom($role);
        if ($resolved === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid role "%s". Valid roles: %s',
                    is_string($role) ? $role : $role->value,
                    implode(', ', MessageRole::values()),
                ),
                1736502001,
            );
        }
        $this->role = $resolved->value;
    }

    /**
     * Get the message role as the typed enum.
     *
     * Prefer this over reading the `$role` string field in new code —
     * the enum gives `match` exhaustiveness and prevents typos.
     */
    public function getRole(): MessageRole
    {
        // Cannot return null: the constructor already validated the value.
        return MessageRole::from($this->role);
    }

    /**
     * Create a system message.
     */
    public static function system(string $content): self
    {
        return new self(MessageRole::System, $content);
    }

    /**
     * Create a user message.
     */
    public static function user(string $content): self
    {
        return new self(MessageRole::User, $content);
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(string $content): self
    {
        return new self(MessageRole::Assistant, $content);
    }

    /**
     * Create a tool message.
     */
    public static function tool(string $content): self
    {
        return new self(MessageRole::Tool, $content);
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
        return $this->role === MessageRole::System->value;
    }

    /**
     * Check if this is a user message.
     */
    public function isUser(): bool
    {
        return $this->role === MessageRole::User->value;
    }

    /**
     * Check if this is an assistant message.
     */
    public function isAssistant(): bool
    {
        return $this->role === MessageRole::Assistant->value;
    }

    /**
     * Check if this is a tool message.
     */
    public function isTool(): bool
    {
        return $this->role === MessageRole::Tool->value;
    }

    /**
     * Get valid roles.
     *
     * Retained for back-compat with callers that consume the string list.
     * New code should call `MessageRole::values()` (or `MessageRole::cases()`)
     * directly.
     *
     * @return list<string>
     */
    public static function getValidRoles(): array
    {
        return MessageRole::values();
    }
}
