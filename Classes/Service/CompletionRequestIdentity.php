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
        $tools    = self::hasToolHistory($messages);
        $ids      = [];
        $payloads = [];
        foreach ($messages as $message) {
            if ($message instanceof ChatMessage) {
                $message = $tools ? self::toolRoundPayload($message, $ids) : $message->toArray();
            }

            $payloads[] = $message;
        }

        [$supported, $canonical] = self::canonicalize([$tools ? 'completion-request-v1-tools' : 'completion-request-v1', $payloads, $options]);
        if (!$supported) {
            return '';
        }

        // Only literal values reach serialize: no object hooks or resource coercion.
        return 'v1:' . hash('sha256', serialize($canonical));
    }

    /**
     * Whether the request carries tool calls or provider items: the requests
     * that had no identity before ADR-323. Every other request is identified
     * exactly as before, so its identity keeps its bytes.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private static function hasToolHistory(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof ChatMessage && ($message->toolCalls !== null || $message->providerItems !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A message of a request with tool history (ADR-323), without what a
     * provider generates afresh for every run, so two runs that sent the same
     * input get the same identity:
     *
     * - a tool-call id, and the `tool_call_id` of the result that answers it,
     *   become the call's ordinal in the request (`#1`, `#2`, ...), which keeps
     *   which result answers which call;
     * - provider items are left out: they are the provider's own replay of its
     *   earlier output, with ids and signatures of their own.
     *
     * Tool calls are taken with their DECODED arguments, so the order of
     * argument keys does not count. Not toArray(): it JSON-encodes the
     * arguments, which would run an object's serialisation hooks;
     * canonicalisation refuses objects.
     *
     * @param array<string, string> $ids the ordinals given so far, by id
     *
     * @return array<string, mixed>
     */
    private static function toolRoundPayload(ChatMessage $message, array &$ids): array
    {
        $ordinal = static function (string $id) use (&$ids): string {
            return $ids[$id] ??= '#' . (count($ids) + 1);
        };

        $payload = ['role' => $message->role, 'content' => $message->content];
        if ($message->toolCallId !== null) {
            $payload['tool_call_id'] = $ordinal($message->toolCallId);
        }

        if ($message->toolCalls !== null) {
            $payload['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id'        => $ordinal($call->id),
                    'type'      => $call->type,
                    'name'      => $call->name,
                    'arguments' => $call->arguments,
                ],
                $message->toolCalls,
            );
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
