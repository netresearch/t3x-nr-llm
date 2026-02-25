<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Enum representing model capabilities.
 *
 * Used to define what features a model supports (chat, completion, embeddings, etc.).
 */
enum ModelCapability: string
{
    case CHAT = 'chat';
    case COMPLETION = 'completion';
    case EMBEDDINGS = 'embeddings';
    case VISION = 'vision';
    case STREAMING = 'streaming';
    case TOOLS = 'tools';
    case JSON_MODE = 'json_mode';
    case AUDIO = 'audio';

    /**
     * Get all capability values as an array.
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
     * Check if a given string is a valid capability.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Try to create from string, returns null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
