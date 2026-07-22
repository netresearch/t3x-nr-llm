<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

/**
 * Estimates the token size of an agent transcript, erring HIGH by construction
 * (ADR-107).
 *
 * No BPE tokenizer (no runtime dependency): a content-class-aware chars/N
 * approximation. Ordinary prose divides by a generous 3.5 (over-counts typical
 * Latin text vs the house 4.0), while DENSE segments — tool-call JSON arguments,
 * tool_result payloads, ids and the tool-schema block — divide by 2.5, because
 * minified JSON and code tokenize denser and were the previous overflow vector.
 * A per-message and per-tool-call overhead covers the role/wrapper tokens the
 * provider adds. The whole estimate is finally scaled by a calibration factor
 * (>= 1.0) the manager grows toward the real prompt-token counts.
 */
final class TranscriptEstimator
{
    private const CHARS_PER_TOKEN_PROSE = 3.5;

    private const CHARS_PER_TOKEN_DENSE = 2.5;

    private const MESSAGE_OVERHEAD_TOKENS = 8;

    private const TOOL_CALL_OVERHEAD_TOKENS = 12;

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<array<string, mixed>>             $toolSpecs empty for a plain-completion send
     */
    public function estimate(array $messages, array $toolSpecs, float $calibration): int
    {
        $proseChars = 0;
        $denseChars = 0;
        $tokens     = 0;

        foreach ($messages as $message) {
            $data = $message instanceof ChatMessage ? $message->toArray() : $message;
            $tokens += self::MESSAGE_OVERHEAD_TOKENS;

            $content = is_string($data['content'] ?? null) ? $data['content'] : '';
            // tool_result content is dense (JSON/data); assistant/user text is prose.
            if (($data['role'] ?? '') === 'tool') {
                $denseChars += strlen($content);
            } else {
                $proseChars += strlen($content);
            }
            $denseChars += strlen(is_string($data['tool_call_id'] ?? null) ? $data['tool_call_id'] : '');

            $toolCalls = is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [];
            foreach ($toolCalls as $call) {
                $tokens += self::TOOL_CALL_OVERHEAD_TOKENS;
                $function = is_array($call) && is_array($call['function'] ?? null) ? $call['function'] : [];
                $proseChars += strlen(is_string($function['name'] ?? null) ? $function['name'] : '');
                $denseChars += strlen(is_string($function['arguments'] ?? null) ? $function['arguments'] : '');
                $denseChars += strlen(is_array($call) && is_string($call['id'] ?? null) ? $call['id'] : '');
            }
        }

        // The tool JSON-schema block is on the wire for every tool-enabled call.
        $denseChars += strlen((string)json_encode($toolSpecs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $raw = (int)ceil($proseChars / self::CHARS_PER_TOKEN_PROSE)
            + (int)ceil($denseChars / self::CHARS_PER_TOKEN_DENSE)
            + $tokens;

        return (int)ceil($raw * max(1.0, $calibration));
    }
}
