<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\ResponseParserTrait;

/**
 * Reads a `/v1/responses` reply (ADR-203).
 *
 * The reply has no `choices` array and no `finish_reason`. What it has is a
 * flat `output` list of typed items and a top-level `status`, so three things
 * are done here that the Chat Completions parser never had to do:
 *
 * - The visible answer is assembled from the `output_text` parts of every
 *   `message` item, because a reply may hold more than one.
 * - `status` plus `incomplete_details.reason` are mapped onto the
 *   `finishReason` vocabulary `CompletionResponse` already uses, so
 *   `wasTruncated()` and `wasFiltered()` keep answering correctly.
 * - The `output` array is kept as it arrived. It is the input of the next
 *   request in a multi-step run and must not be normalised on the way
 *   through — including the reasoning items, whose `encrypted_content` this
 *   extension can neither read nor reproduce. The one item dropped is a
 *   `function_call` too malformed to become a tool call: nothing will answer
 *   it, and a call without its output is a request OpenAI refuses.
 *
 * Usage keys differ too: `input_tokens` and `output_tokens`, not
 * `prompt_tokens` and `completion_tokens`.
 */
final class ResponsesResultParser
{
    use ResponseParserTrait;

    /**
     * @param array<string, mixed> $response
     */
    public function parse(array $response, string $requestedModel): ResponsesResult
    {
        // A reply that failed carries an `error` object and no usable output.
        // Returned as a result it would read as an empty final answer, so it
        // is raised like any other refused request.
        if ($this->getString($response, 'status') === 'failed') {
            $error = $this->getArray($response, 'error');

            throw new ProviderResponseException(
                sprintf(
                    'OpenAI Responses request failed: %s',
                    $this->getString($error, 'message', $this->getString($error, 'code', 'no reason given')),
                ),
                0,
                null,
                '',
                'responses',
            );
        }

        $output = $this->getList($response, 'output');

        $content   = '';
        $toolCalls = [];
        $items     = [];

        foreach ($output as $rawItem) {
            $item    = $this->asArray($rawItem);
            $items[] = $item;

            $type = $this->getString($item, 'type');

            if ($type === 'message') {
                $content .= $this->textOf($item);

                continue;
            }

            if ($type !== 'function_call') {
                continue;
            }

            $call = $this->toolCallOf($item);
            if ($call instanceof ToolCall) {
                $toolCalls[] = $call;

                continue;
            }

            // The call was unusable and is skipped, so no tool result will
            // answer it. Replaying the item anyway would send OpenAI a
            // function_call without its function_call_output.
            array_pop($items);
        }

        $usage = $this->getArray($response, 'usage');

        return new ResponsesResult(
            content: $content,
            model: $this->getString($response, 'model', $requestedModel),
            finishReason: $toolCalls === [] ? $this->finishReasonOf($response) : $this->toolCallFinishReason($response),
            promptTokens: $this->getInt($usage, 'input_tokens'),
            completionTokens: $this->getInt($usage, 'output_tokens'),
            toolCalls: $toolCalls === [] ? null : $toolCalls,
            providerItems: $items,
            appliedEffort: ReasoningEffort::tryFromOption(
                $this->getArray($response, 'reasoning')['effort'] ?? null,
            ),
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function textOf(array $item): string
    {
        $text = '';
        foreach ($this->getList($item, 'content') as $rawPart) {
            $part = $this->asArray($rawPart);
            if ($this->getString($part, 'type') === 'output_text') {
                $text .= $this->getString($part, 'text');
            }
        }

        return $text;
    }

    /**
     * A malformed item is skipped rather than fatal — the same tolerance the
     * Chat Completions path applies through {@see ToolCall::tryFromArray()},
     * for the same reason: provider output is untrusted, and one unusable call
     * must not discard the whole completion.
     *
     * `call_id` is the correlation token here, not `id`. The item carries
     * both, and only `call_id` is the one a `function_call_output` must echo.
     *
     * @param array<string, mixed> $item
     */
    private function toolCallOf(array $item): ?ToolCall
    {
        return ToolCall::tryFromArray([
            'id' => $this->getString($item, 'call_id'),
            'type' => 'function',
            'function' => [
                'name' => $this->getString($item, 'name'),
                'arguments' => $this->getString($item, 'arguments'),
            ],
        ]);
    }

    /**
     * A completed turn that calls tools reports `tool_calls`, as the Chat
     * Completions and Claude adapters do: `stop` would make
     * `CompletionResponse::isComplete()` true for a turn still waiting for its
     * tool results.
     *
     * @param array<string, mixed> $response
     */
    private function toolCallFinishReason(array $response): string
    {
        $reason = $this->finishReasonOf($response);

        return $reason === 'stop' ? 'tool_calls' : $reason;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function finishReasonOf(array $response): string
    {
        $status = $this->getString($response, 'status', 'completed');

        if ($status !== 'incomplete') {
            return $status === 'completed' ? 'stop' : $status;
        }

        $reason = $this->getString($this->getArray($response, 'incomplete_details'), 'reason');

        return match ($reason) {
            'max_output_tokens' => 'length',
            'content_filter'    => 'content_filter',
            default             => 'incomplete',
        };
    }
}
