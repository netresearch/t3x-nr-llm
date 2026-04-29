<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Role of a message in a chat conversation.
 *
 * Mirrors the role values understood by every supported LLM provider.
 * Backed by the same lower-case string each provider expects on the wire,
 * so the enum value is what gets serialised — no separate translation
 * table needed.
 *
 * Replaces the previous `ChatMessage::VALID_ROLES` const array. Existing
 * string call sites (`new ChatMessage('user', ...)`) keep working because
 * `ChatMessage` accepts `string|MessageRole` and normalises through this
 * enum at construction time.
 */
enum MessageRole: string
{
    case System    = 'system';
    case User      = 'user';
    case Assistant = 'assistant';
    case Tool      = 'tool';

    /**
     * Get all role values as a flat list of strings.
     *
     * Useful for error messages, validators, and serialisation paths
     * that still operate on strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Test whether a string is a recognised role.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /**
     * Try to create from a string; returns `null` on unknown values.
     *
     * Provided alongside the built-in `tryFrom()` to match the project's
     * convention (see ModelCapability, ModelSelectionMode).
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
