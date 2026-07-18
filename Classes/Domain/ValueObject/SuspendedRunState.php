<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The serialisable state of a tool-loop run suspended for human approval
 * (ADR-084).
 *
 * Captured the moment {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
 * reaches an approval-required tool call: the full message transcript up to and
 * including the assistant's tool-call turn, the pending calls of that turn (the
 * whole turn is held so a multi-call turn stays consistent), and the
 * iteration/token counters accumulated so far. Stored as JSON on the AgentRun
 * and rehydrated on resume.
 *
 * Messages and calls are held in their already-serialised
 * ({@see ChatMessage::toArray()} / {@see ToolCall::toArray()}) form so the state
 * is a plain JSON-encodable structure; {@see self::toolCalls()} rebuilds the
 * typed calls for execution on resume.
 */
final readonly class SuspendedRunState
{
    /**
     * @param list<array<string, mixed>> $messages     serialised ChatMessage transcript (ends with the assistant tool-call turn)
     * @param list<array<string, mixed>> $pendingCalls serialised ToolCall list of the suspended turn
     */
    public function __construct(
        public array $messages,
        public array $pendingCalls,
        public int $iterations,
        public int $promptTokens,
        public int $completionTokens,
    ) {}

    /**
     * @return array{messages: list<array<string, mixed>>, pendingCalls: list<array<string, mixed>>, iterations: int, promptTokens: int, completionTokens: int}
     */
    public function toArray(): array
    {
        return [
            'messages'         => $this->messages,
            'pendingCalls'     => $this->pendingCalls,
            'iterations'       => $this->iterations,
            'promptTokens'     => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::listOfArrays($data['messages'] ?? null),
            self::listOfArrays($data['pendingCalls'] ?? null),
            is_numeric($data['iterations'] ?? null) ? (int)$data['iterations'] : 0,
            is_numeric($data['promptTokens'] ?? null) ? (int)$data['promptTokens'] : 0,
            is_numeric($data['completionTokens'] ?? null) ? (int)$data['completionTokens'] : 0,
        );
    }

    /**
     * The pending turn's calls, rebuilt as typed {@see ToolCall} objects for
     * execution on resume.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->pendingCalls as $call) {
            $calls[] = ToolCall::fromArray($call);
        }

        return $calls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}
