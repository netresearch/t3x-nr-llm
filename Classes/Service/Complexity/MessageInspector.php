<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Complexity;

use Netresearch\NrLlm\Domain\Enum\MessageRole;
use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

/**
 * Reads a transcript the way both measurements read it (ADR-174).
 *
 * {@see RequestComplexityEstimator} (post-fit) and {@see RequestFactsCollector}
 * (pre-routing) describe the same send at two different moments, and the point
 * of writing both to one row is that they can be compared. A second definition
 * of "a turn" or "tool traffic" would quietly break that comparison, so there
 * is one definition and both callers use it.
 *
 * Accepts either shape a send can carry: a {@see ChatMessage} or the legacy
 * array form the manager still normalises from.
 *
 * @internal
 */
final readonly class MessageInspector
{
    /**
     * A conversation turn is any message that is not a system message: the
     * system prompt is configuration, not conversation.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function turnCount(array $messages): int
    {
        $turns = 0;
        foreach ($messages as $message) {
            if ($this->roleOf($message) !== MessageRole::SYSTEM->value) {
                ++$turns;
            }
        }

        return $turns;
    }

    /**
     * Total byte length of the message contents — {@see strlen}, not
     * {@see mb_strlen} (:ref:`ADR-121 <adr-121>`).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function payloadBytes(array $messages): int
    {
        $bytes = 0;
        foreach ($messages as $message) {
            $bytes += strlen($this->contentOf($message));
        }

        return $bytes;
    }

    /**
     * Whether the transcript carries either half of a tool exchange: an
     * assistant message that requested calls, or the result that answered one.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function carriesToolTraffic(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($this->isToolTraffic($message, $this->roleOf($message))) {
                return true;
            }
        }

        return false;
    }

    public function shape(int $conversationTurns, bool $carriesTools): RequestShape
    {
        if ($carriesTools) {
            return RequestShape::TOOL_ASSISTED;
        }

        return $conversationTurns > 1 ? RequestShape::MULTI_TURN : RequestShape::SINGLE_TURN;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    public function roleOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->getRole()->value;
        }

        $role = $message['role'] ?? null;

        return is_string($role) ? $role : '';
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    public function contentOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->content;
        }

        $content = $message['content'] ?? null;

        return is_string($content) ? $content : '';
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    public function isToolTraffic(ChatMessage|array $message, string $role): bool
    {
        if ($role === MessageRole::TOOL->value) {
            return true;
        }

        if ($message instanceof ChatMessage) {
            return $message->toolCalls !== null;
        }

        $toolCalls = $message['tool_calls'] ?? $message['toolCalls'] ?? null;

        return is_array($toolCalls) && $toolCalls !== [];
    }
}
