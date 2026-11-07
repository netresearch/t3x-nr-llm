<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

/**
 * Content-free identity of the final completion request sent to an adapter. @internal.
 */
final class CompletionRequestIdentity
{
    public const METADATA_KEY = 'nr_llm_completion_request';

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     */
    public static function fingerprint(array $messages, array $options): string
    {
        unset($options['model'], $options['provider']);
        $payloads = [];
        foreach ($messages as $message) {
            if ($message instanceof ChatMessage) {
                // toArray omits native provider items and serializes tool arguments.
                // Neither conversion can attest those inputs without a wider contract.
                if ($message->providerItems !== null || $message->toolCalls !== null) {
                    return '';
                }

                $message = $message->toArray();
            }

            $payloads[] = $message;
        }

        [$supported, $canonical] = self::canonicalize(['completion-request-v1', $payloads, $options]);
        if (!$supported) {
            return '';
        }

        // Only literal values reach serialize: no object hooks or resource coercion.
        return 'v1:' . hash('sha256', serialize($canonical));
    }

    public static function read(mixed $value): string
    {
        return is_string($value) && preg_match('/^v1:[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    /**
     * @return array{bool, mixed}
     */
    private static function canonicalize(mixed $value, int $depth = 0): array
    {
        // Bounds recursive arrays as well as excessively deep provider-specific data.
        if ($depth > 64) {
            return [false, null];
        }

        if ($value === null || is_scalar($value)) {
            return [true, $value];
        }

        if (!is_array($value)) {
            return [false, null];
        }

        $canonical = [];
        foreach ($value as $key => $child) {
            [$supported, $normalized] = self::canonicalize($child, $depth + 1);
            if (!$supported) {
                return [false, null];
            }

            // Build a fresh tree; assignments must not mutate caller-owned references.
            $canonical[$key] = $normalized;
        }

        if (!array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        return [true, $canonical];
    }
}
