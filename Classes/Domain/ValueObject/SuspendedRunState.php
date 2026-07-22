<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The serialisable state of a tool-loop run suspended for human approval
 * (ADR-084) or typed user input (ADR-105).
 *
 * Captured the moment {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
 * reaches an approval-required OR input-required tool call: the full message
 * transcript up to and including the assistant's tool-call turn, the pending
 * calls of that turn (the whole turn is held so a multi-call turn stays
 * consistent), and the iteration/token counters accumulated so far. Stored as
 * JSON on the AgentRun and rehydrated on resume. On an input pause it also
 * carries the target tool name and its declared input schema; on an approval
 * pause both stay at their null/empty defaults.
 *
 * Messages and calls are held in their already-serialised
 * ({@see ChatMessage::toArray()} / {@see ToolCall::toArray()}) form so the state
 * is a plain JSON-encodable structure; {@see self::toolCalls()} rebuilds the
 * typed calls for execution on resume.
 */
final readonly class SuspendedRunState
{
    /**
     * @param list<array<string, mixed>> $messages         serialised ChatMessage transcript (ends with the assistant tool-call turn)
     * @param list<array<string, mixed>> $pendingCalls     serialised ToolCall list of the suspended turn
     * @param list<string>|null          $allowedToolNames the run's original tool allow-list, so resume re-applies the SAME per-run constraint (null = the globally-enabled set)
     * @param array<string, mixed>       $options          the run's serialised ToolOptions, so resume continues with the same temperature/max-tokens/think/etc.
     * @param string|null                $inputToolName    on an input pause (ADR-105) the tool whose typed input the user must supply; null on an approval pause
     * @param array<string, mixed>       $inputSchema      on an input pause the tool's declared input schema (a JSON-Schema subset); `[]` on an approval pause
     */
    public function __construct(
        public array $messages,
        public array $pendingCalls,
        public int $iterations,
        public int $promptTokens,
        public int $completionTokens,
        public ?array $allowedToolNames = null,
        public array $options = [],
        public ?string $inputToolName = null,
        public array $inputSchema = [],
    ) {}

    /**
     * @return array{messages: list<array<string, mixed>>, pendingCalls: list<array<string, mixed>>, iterations: int, promptTokens: int, completionTokens: int, allowedToolNames: list<string>|null, options: array<string, mixed>, inputToolName: string|null, inputSchema: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'messages'         => $this->messages,
            'pendingCalls'     => $this->pendingCalls,
            'iterations'       => $this->iterations,
            'promptTokens'     => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'allowedToolNames' => $this->allowedToolNames,
            'options'          => $this->options,
            'inputToolName'    => $this->inputToolName,
            'inputSchema'      => $this->inputSchema,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $allowed          = $data['allowedToolNames'] ?? null;
        $allowedToolNames = is_array($allowed) ? array_values(array_filter($allowed, is_string(...))) : null;

        $options = $data['options'] ?? null;
        /** @var array<string, mixed> $options */
        $options = is_array($options) ? $options : [];

        // Back-compat: an approval-era row has neither key. The degradation to
        // null/[] is intentional; the fail-open risk an empty schema would
        // otherwise create is closed by AgentRuntime's well-formedness gate
        // before validation (ADR-105 M2), never here.
        $inputToolName = is_string($data['inputToolName'] ?? null) ? $data['inputToolName'] : null;

        $inputSchema = $data['inputSchema'] ?? null;
        /** @var array<string, mixed> $inputSchema */
        $inputSchema = is_array($inputSchema) ? $inputSchema : [];

        return new self(
            self::listOfArrays($data['messages'] ?? null),
            self::listOfArrays($data['pendingCalls'] ?? null),
            is_numeric($data['iterations'] ?? null) ? (int)$data['iterations'] : 0,
            is_numeric($data['promptTokens'] ?? null) ? (int)$data['promptTokens'] : 0,
            is_numeric($data['completionTokens'] ?? null) ? (int)$data['completionTokens'] : 0,
            $allowedToolNames,
            $options,
            $inputToolName,
            $inputSchema,
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
            /** @var array{id?: string, type?: string, function?: array{name?: string, arguments?: array<string, mixed>|string}} $call */
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
