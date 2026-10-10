<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Provider\Gemini;

use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;

/** Provider-owned generateContent replay on the existing carrier (ADR-222).
 * @internal
 */
final class GeminiReplayMetadata
{
    public const TYPE = 'nrllm_gemini_generate_content';

    private const INVALID_MESSAGE = 'Gemini replay state is malformed or ambiguous.';

    /**
     * Foreign untagged OpenAI items retain their existing meaning.
     * A recognized Gemini capsule must occupy its whole turn.
     *
     * @param array<array-key, mixed>|null $items
     *
     * @return list<array<string, mixed>>|null
     */
    public static function nativeParts(?array $items): ?array
    {
        $owned = null;
        foreach ($items ?? [] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === self::TYPE) {
                $owned = $item;
            }
        }

        if ($owned === null) {
            return null;
        }

        $parts = $owned['parts'] ?? null;
        if (count($items ?? []) !== 1 || !is_array($parts) || !array_is_list($parts)) {
            throw new ProviderConfigurationException(
                self::INVALID_MESSAGE,
                1791619001,
            );
        }

        foreach ($parts as $part) {
            if (!is_array($part)) {
                throw new ProviderConfigurationException(
                    self::INVALID_MESSAGE,
                    1791619001,
                );
            }
        }

        /** @var list<array<string, mixed>> $parts */
        return $parts;
    }

    /**
     * @param array<int, mixed> $parts
     */
    public static function hasSignature(array $parts): bool
    {
        foreach ($parts as $part) {
            if (is_array($part) && array_key_exists('thoughtSignature', $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep valid native arrays in their original iteration order.
     * Malformed non-array parts retain the response parser's skip behavior.
     *
     * @param array<int, mixed> $parts
     *
     * @return list<array<string, mixed>>
     */
    public static function itemsFromParts(array $parts): array
    {
        /** @var list<array<string, mixed>> $nativeParts */
        $nativeParts = array_values(array_filter($parts, is_array(...)));
        return [['type' => self::TYPE, 'parts' => $nativeParts]];
    }
}
