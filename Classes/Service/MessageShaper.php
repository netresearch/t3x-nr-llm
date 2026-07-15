<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

/**
 * Shapes a public-API messages list for forwarding to provider adapters.
 *
 * Extracted from {@see LlmServiceManager} so the message-normalisation and
 * system-prompt rules live in one stateless collaborator that the manager
 * (and, later, the per-operation dispatchers) share instead of carrying
 * private copies.
 */
final readonly class MessageShaper
{
    /**
     * Normalise a public-API messages list for forwarding to providers.
     *
     * Simple legacy fixtures matching the `ChatMessage` shape (`{role: string,
     * content: string}` only) are routed through `ChatMessage::fromArray()`
     * so providers downstream see typed VOs whenever the sender used the
     * documented shape. Richer provider-specific arrays carrying
     * `tool_call_id`, `tool_calls`, `name`, or multimodal `content` arrays
     * are passed through unchanged so their additional fields survive the
     * round-trip. `ChatMessage` models the tool-turn keys (`tool_calls`,
     * `tool_call_id`) since #345 — typed tool turns pass through as VOs and
     * serialise those fields in `toArray()` — but `name` and multimodal
     * `content` arrays remain array-only shapes, and eagerly running them
     * through `fromArray()` would silently drop the extra keys (and break
     * `ClaudeProvider::convertMessagesForClaude()` for multimodal messages).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function normalise(array $messages): array
    {
        return array_values(array_map(
            static function (ChatMessage|array $message): ChatMessage|array {
                if ($message instanceof ChatMessage) {
                    return $message;
                }

                if (
                    count($message) === 2
                    && array_key_exists('role', $message)
                    && array_key_exists('content', $message)
                    && is_string($message['role'])
                    && is_string($message['content'])
                ) {
                    return ChatMessage::fromArray($message);
                }

                return $message;
            },
            $messages,
        ));
    }

    /**
     * Prepend the effective system prompt (from the merged call options) as a
     * system message when the caller has not already supplied one.
     *
     * The provider adapters read the system instruction from the message list,
     * NOT from `options['system_prompt']`. A configuration's stored system
     * prompt is surfaced via {@see \Netresearch\NrLlm\Domain\Model\LlmConfiguration::toOptionsArray()}
     * under that option key, so without this step it would be silently dropped
     * on every chat and tool-loop call. An explicit system message already
     * present in $messages always wins (per-call precedence over the
     * configuration).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function applySystemPrompt(array $messages, array $options): array
    {
        $systemPrompt = $options['system_prompt'] ?? null;
        if (!is_string($systemPrompt) || $systemPrompt === '') {
            return $messages;
        }

        foreach ($messages as $message) {
            $isSystem = $message instanceof ChatMessage
                ? $message->isSystem()
                : (is_array($message) && ($message['role'] ?? null) === 'system');
            if ($isSystem) {
                return $messages;
            }
        }

        array_unshift($messages, ChatMessage::system($systemPrompt));

        return $messages;
    }
}
