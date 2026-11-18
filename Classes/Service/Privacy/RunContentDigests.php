<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Service\KeyedDigest;

/**
 * The keyed digests a run step keeps of the content it drops (ADR-323).
 *
 * Keyed with the installation's encryption key and a purpose of their own
 * (see {@see KeyedDigest}): they compare within one installation and never
 * equal a digest of another purpose. An installation without an encryption
 * key gets none.
 *
 * A message is digested in a form without what differs between two runs that
 * sent the same input: the ids of tool calls and of the tool results that
 * answer them, which a provider generates, and provider-owned items, which
 * carry signatures and ids of their own. Tool arguments are digested decoded,
 * so the order of their keys does not count.
 *
 * @internal
 */
final class RunContentDigests
{
    public const PURPOSE = 'nr_llm.run.content';

    public static function of(mixed $value): ?string
    {
        return KeyedDigest::of(self::PURPOSE, $value);
    }

    /**
     * One digest per message, in order, with its role; null when a message
     * has no digest (no encryption key, a value without a canonical form), so
     * a list is never partial.
     *
     * @param array<array-key, mixed> $messages
     *
     * @return list<array{role: string, digest: string}>|null
     */
    public static function messages(array $messages): ?array
    {
        $digests = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                return null;
            }

            $digest = self::of(self::comparable($message));
            if ($digest === null) {
                return null;
            }

            $role      = $message['role'] ?? '';
            $digests[] = ['role' => is_string($role) ? $role : '', 'digest' => $digest];
        }

        return $digests;
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function comparable(array $message): array
    {
        $comparable = ['role' => $message['role'] ?? null, 'content' => $message['content'] ?? null];
        $calls      = $message['tool_calls'] ?? null;
        if (is_array($calls)) {
            $comparable['tool_calls'] = [];
            foreach ($calls as $call) {
                $comparable['tool_calls'][] = self::comparableCall($call);
            }
        }

        return $comparable;
    }

    /**
     * @return array{name: mixed, arguments: mixed}
     */
    private static function comparableCall(mixed $call): array
    {
        if (!is_array($call)) {
            return ['name' => null, 'arguments' => $call];
        }

        $function  = is_array($call['function'] ?? null) ? $call['function'] : $call;
        $arguments = $function['arguments'] ?? null;
        if (is_string($arguments)) {
            $decoded   = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : $arguments;
        }

        return ['name' => $function['name'] ?? null, 'arguments' => $arguments];
    }
}
