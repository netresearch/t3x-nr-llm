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
 * On an approval pause it may additionally carry a per-call preview of what the
 * pending calls would do (ADR-136), produced at suspend time in the run's actor
 * context. It is optional in every sense: a tool that declares no preview
 * contributes none, and a state persisted before the field existed rehydrates
 * without it.
 *
 * Messages and calls are held in their already-serialised
 * ({@see ChatMessage::toArray()} / {@see ToolCall::toArray()}) form so the state
 * is a plain JSON-encodable structure; {@see self::toolCalls()} rebuilds the
 * typed calls for execution on resume.
 *
 * @api
 */
final readonly class SuspendedRunState
{
    /**
     * @param list<array<string, mixed>>                                               $messages         serialised ChatMessage transcript (ends with the assistant tool-call turn)
     * @param list<array<string, mixed>>                                               $pendingCalls     serialised ToolCall list of the suspended turn
     * @param list<string>|null                                                        $allowedToolNames the run's original tool allow-list, so resume re-applies the SAME per-run constraint (null = the globally-enabled set)
     * @param array<string, mixed>                                                     $options          the run's serialised ToolOptions, so resume continues with the same temperature/max-tokens/think/etc.
     * @param string|null                                                              $inputToolName    on an input pause (ADR-105) the tool whose typed input the user must supply; null on an approval pause
     * @param array<string, mixed>                                                     $inputSchema      on an input pause the tool's declared input schema (a JSON-Schema subset); `[]` on an approval pause
     * @param list<array{index: int, tool: string, lines: list<string>, failed: bool}> $callPreviews     what the pending calls WOULD do, captured at suspend in the run's actor context (ADR-136); `index` points into `$pendingCalls`. Only calls whose tool implements ToolPreviewInterface appear, so `[]` is the normal case
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
        public array $callPreviews = [],
    ) {}

    /**
     * @return array{messages: list<array<string, mixed>>, pendingCalls: list<array<string, mixed>>, iterations: int, promptTokens: int, completionTokens: int, allowedToolNames: list<string>|null, options: array<string, mixed>, inputToolName: string|null, inputSchema: array<string, mixed>, callPreviews: list<array{index: int, tool: string, lines: list<string>, failed: bool}>}
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
            'callPreviews'     => $this->callPreviews,
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

        $rawPendingCalls = $data['pendingCalls'] ?? null;

        return new self(
            self::listOfArrays($data['messages'] ?? null),
            self::listOfArrays($rawPendingCalls),
            is_numeric($data['iterations'] ?? null) ? (int)$data['iterations'] : 0,
            is_numeric($data['promptTokens'] ?? null) ? (int)$data['promptTokens'] : 0,
            is_numeric($data['completionTokens'] ?? null) ? (int)$data['completionTokens'] : 0,
            $allowedToolNames,
            $options,
            $inputToolName,
            $inputSchema,
            // Back-compat (ADR-136): every row suspended before the preview
            // existed lacks this key, and a running installation has such rows
            // in its database. A missing or malformed value degrades to "no
            // preview" — the card then shows the arguments alone, exactly as it
            // did before — and NEVER stops the run from resuming.
            self::previewsFrom($data['callPreviews'] ?? null, self::survivingIndexMap($rawPendingCalls)),
        );
    }

    /**
     * Maps each position in the RAW pending-call list onto its position in the
     * filtered list {@see self::listOfArrays()} produces.
     *
     * A preview records the index the loop wrote, and the loop counts EVERY
     * entry of the turn. Rehydration drops entries that are not arrays and
     * renumbers what is left, so an unusable entry shifts every call behind it
     * by one. Without this map a preview would then be shown next to the
     * arguments of the following call — precisely the mismatch the index was
     * introduced to prevent (ADR-136), and the tool-name guard does not catch it
     * when both calls name the same tool. A preview whose call did not survive
     * has no key here and is dropped.
     *
     * @return array<int, int>
     */
    private static function survivingIndexMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map      = [];
        $original = 0;
        $kept     = 0;
        foreach ($value as $item) {
            if (is_array($item)) {
                $map[$original] = $kept;
                ++$kept;
            }

            ++$original;
        }

        return $map;
    }

    /**
     * The persisted previews, entry by entry, dropping anything that does not
     * carry the full shape. Written by {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService},
     * but read back out of a blob that may predate the field or have been
     * hand-edited, so nothing here is trusted.
     *
     * The stored index counts the RAW pending calls; `$indexMap` translates it
     * onto the filtered list this state exposes, and a preview whose call did
     * not survive that filtering is dropped rather than re-pointed.
     *
     * @param array<int, int> $indexMap raw pending-call position => position after filtering
     *
     * @return list<array{index: int, tool: string, lines: list<string>, failed: bool}>
     */
    private static function previewsFrom(mixed $value, array $indexMap): array
    {
        if (!is_array($value)) {
            return [];
        }

        $previews = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!is_numeric($entry['index'] ?? null)) {
                continue;
            }

            if (!is_string($entry['tool'] ?? null)) {
                continue;
            }

            if (!is_array($entry['lines'] ?? null)) {
                continue;
            }

            $lines = array_values(array_filter($entry['lines'], is_string(...)));
            if ($lines === []) {
                continue;
            }

            $index = $indexMap[(int)$entry['index']] ?? null;
            if ($index === null) {
                continue;
            }

            $previews[] = [
                'index'  => $index,
                'tool'   => $entry['tool'],
                'lines'  => $lines,
                'failed' => (bool)($entry['failed'] ?? false),
            ];
        }

        return $previews;
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
