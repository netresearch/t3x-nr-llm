<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;

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
                $message = self::messagePayload($message);
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

    /**
     * A message as the identity sees it (ADR-323). A message without tool
     * calls or provider items is its `toArray()` form, as before, so every
     * identity that existed keeps its bytes. Tool calls are taken as id, type,
     * name and their DECODED arguments, so the order of argument keys does
     * not matter; provider items are taken as the provider sent them, so the
     * identity changes when a provider changes their encoding.
     *
     * @return array<string, mixed>
     */
    private static function messagePayload(ChatMessage $message): array
    {
        if ($message->toolCalls === null) {
            $payload = $message->toArray();
        } else {
            // Not toArray(): it JSON-encodes the arguments, which would run an
            // object's serialisation hooks; canonicalisation refuses objects.
            $payload = ['role' => $message->role, 'content' => $message->content];
            if ($message->toolCallId !== null) {
                $payload['tool_call_id'] = $message->toolCallId;
            }

            $payload['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id'        => $call->id,
                    'type'      => $call->type,
                    'name'      => $call->name,
                    'arguments' => $call->arguments,
                ],
                $message->toolCalls,
            );
        }

        if ($message->providerItems !== null) {
            $payload['provider_items'] = $message->providerItems;
        }

        return $payload;
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
