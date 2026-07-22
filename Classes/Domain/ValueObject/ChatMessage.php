<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Enum\MessageRole;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use stdClass;

/**
 * Value Object representing a chat message.
 *
 * Immutable representation of a message in a chat conversation,
 * consisting of a role (system, user, assistant, tool) and content.
 *
 * The two tool-loop turns are first-class citizens: an assistant turn may
 * carry the model's `$toolCalls` (a list of {@see ToolCall}), and a tool
 * turn carries the `$toolCallId` it answers. Use the
 * {@see self::assistantToolCalls()} and {@see self::toolResult()} factories
 * for those shapes — the constructor rejects the fields on any other role.
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
     * Tool calls carried by an assistant turn; `null` on every other shape.
     *
     * @var list<ToolCall>|null
     */
    public ?array $toolCalls;

    /**
     * @param string|MessageRole           $role       Either a backed enum value
     *                                                 or its string equivalent —
     *                                                 the legacy string form is
     *                                                 preserved so existing call
     *                                                 sites do not need to
     *                                                 migrate immediately.
     * @param array<array-key, mixed>|null $toolCalls  Tool calls of an assistant
     *                                                 turn; every element must be
     *                                                 a {@see ToolCall}.
     * @param string|null                  $toolCallId The call a tool turn
     *                                                 answers; must be non-empty
     *                                                 when given.
     */
    public function __construct(
        string|MessageRole $role,
        public string $content,
        ?array $toolCalls = null,
        public ?string $toolCallId = null,
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

        if ($toolCalls !== null && $resolved !== MessageRole::ASSISTANT) {
            throw new InvalidArgumentException(
                sprintf('Tool calls are only allowed on assistant messages, got role "%s".', $resolved->value),
                1752400001,
            );
        }
        if ($toolCalls === []) {
            throw new InvalidArgumentException(
                'An assistant tool-call message requires at least one tool call.',
                1752400002,
            );
        }

        if ($toolCalls === null) {
            $this->toolCalls = null;
        } else {
            $validated = [];
            foreach ($toolCalls as $call) {
                if (!$call instanceof ToolCall) {
                    throw new InvalidArgumentException(
                        'Every element of $toolCalls must be a ToolCall instance.',
                        1752400003,
                    );
                }
                $validated[] = $call;
            }
            $this->toolCalls = $validated;
        }

        if ($this->toolCallId !== null && $resolved !== MessageRole::TOOL) {
            throw new InvalidArgumentException(
                sprintf('A tool_call_id is only allowed on tool messages, got role "%s".', $resolved->value),
                1752400004,
            );
        }
        if ($this->toolCallId === '') {
            throw new InvalidArgumentException(
                'A tool message requires a non-empty tool_call_id.',
                1752400005,
            );
        }
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
        return new self(MessageRole::SYSTEM, $content);
    }

    /**
     * Create a user message.
     */
    public static function user(string $content): self
    {
        return new self(MessageRole::USER, $content);
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(string $content): self
    {
        return new self(MessageRole::ASSISTANT, $content);
    }

    /**
     * Create a tool message.
     */
    public static function tool(string $content): self
    {
        return new self(MessageRole::TOOL, $content);
    }

    /**
     * Create the assistant turn that carries the model's tool calls.
     *
     * This is the first half of a tool round-trip: the model answered with
     * tool calls, and the conversation must echo that turn back before the
     * tool results. `$content` is optional because providers commonly send
     * `content: null` alongside tool calls — the non-nullable `$content`
     * property stores it as an empty string.
     *
     * @param list<ToolCall> $toolCalls
     */
    public static function assistantToolCalls(array $toolCalls, ?string $content = null): self
    {
        return new self(MessageRole::ASSISTANT, $content ?? '', $toolCalls);
    }

    /**
     * Create the tool turn that answers one tool call.
     *
     * `$toolCallId` echoes {@see ToolCall::$id} so the provider can correlate
     * the result with the call; the constructor rejects an empty id.
     */
    public static function toolResult(string $toolCallId, string $content): self
    {
        return new self(MessageRole::TOOL, $content, null, $toolCallId);
    }

    /**
     * Create from array.
     *
     * Guards the raw input: both keys must be present and `content` must be a
     * string. An invalid `role` value is rejected by the constructor with the
     * shared `InvalidArgumentException` code so downstream catches stay stable.
     *
     * Accepts the OpenAI wire keys of the two tool-loop turns so
     * {@see self::toArray()} round-trips: `tool_calls` elements are rebuilt
     * via {@see ToolCall::fromArray()} (which takes `function.arguments` as a
     * JSON string or an already-decoded map), and `tool_call_id` is passed
     * through. A `content` of `null` is accepted alongside `tool_calls` —
     * providers send exactly that — and stored as an empty string.
     *
     * @param array{role?: mixed, content?: mixed, tool_calls?: mixed, tool_call_id?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role']) || !is_string($data['role'])) {
            throw new InvalidArgumentException(
                'ChatMessage::fromArray() requires a string "role" key.',
                1736502002,
            );
        }

        $rawToolCalls = $data['tool_calls'] ?? null;
        if ($rawToolCalls !== null && !is_array($rawToolCalls)) {
            throw new InvalidArgumentException(
                'ChatMessage::fromArray() requires "tool_calls" to be a list of tool-call arrays.',
                1752400006,
            );
        }

        $toolCalls = null;
        if (is_array($rawToolCalls)) {
            $toolCalls = [];
            foreach ($rawToolCalls as $call) {
                if ($call instanceof ToolCall) {
                    $toolCalls[] = $call;

                    continue;
                }
                if (!is_array($call)) {
                    throw new InvalidArgumentException(
                        'Every "tool_calls" element must be an array or a ToolCall instance.',
                        1752400007,
                    );
                }
                /** @var array{id?: string, type?: string, function?: array{name?: string, arguments?: string|array<string, mixed>}} $call */
                $toolCalls[] = ToolCall::fromArray($call);
            }
        }

        $toolCallId = $data['tool_call_id'] ?? null;
        if ($toolCallId !== null && !is_string($toolCallId)) {
            throw new InvalidArgumentException(
                'ChatMessage::fromArray() requires "tool_call_id" to be a string.',
                1752400008,
            );
        }

        $content = $data['content'] ?? null;
        if (!is_string($content)) {
            if (!($content === null && $toolCalls !== null)) {
                throw new InvalidArgumentException(
                    'ChatMessage::fromArray() requires a string "content" key.',
                    1736502003,
                );
            }
            $content = '';
        }

        return new self(
            role: $data['role'],
            content: $content,
            toolCalls: $toolCalls,
            toolCallId: $toolCallId,
        );
    }

    /**
     * Convert to the OpenAI-compatible wire array.
     *
     * When the message carries tool calls, each is emitted in the request
     * wire shape — `function.arguments` as a JSON **string**, with empty
     * arguments encoding to `{}` (an object), never `[]`. This mirrors what
     * `ToolLoopService` previously hand-built and differs deliberately from
     * {@see ToolCall::toArray()}, which keeps the legacy decoded-map form
     * for `CompletionResponse` consumers; {@see ToolCall::fromArray()}
     * accepts both, so the two shapes round-trip.
     *
     * @return array{
     *     role: string,
     *     content: string,
     *     tool_calls?: list<array{id: string, type: string, function: array{name: string, arguments: string}}>,
     *     tool_call_id?: string,
     * }
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->toolCalls !== null) {
            $data['tool_calls'] = array_map(
                static fn(ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => $call->type,
                    'function' => [
                        'name' => $call->name,
                        'arguments' => json_encode(
                            $call->arguments !== [] ? $call->arguments : new stdClass(),
                            JSON_THROW_ON_ERROR,
                        ),
                    ],
                ],
                $this->toolCalls,
            );
        }

        if ($this->toolCallId !== null) {
            $data['tool_call_id'] = $this->toolCallId;
        }

        return $data;
    }

    /**
     * @return array{
     *     role: string,
     *     content: string,
     *     tool_calls?: list<array{id: string, type: string, function: array{name: string, arguments: string}}>,
     *     tool_call_id?: string,
     * }
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
        return $this->role === MessageRole::SYSTEM->value;
    }

    /**
     * Check if this is a user message.
     */
    public function isUser(): bool
    {
        return $this->role === MessageRole::USER->value;
    }

    /**
     * Check if this is an assistant message.
     */
    public function isAssistant(): bool
    {
        return $this->role === MessageRole::ASSISTANT->value;
    }

    /**
     * Check if this is a tool message.
     */
    public function isTool(): bool
    {
        return $this->role === MessageRole::TOOL->value;
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
