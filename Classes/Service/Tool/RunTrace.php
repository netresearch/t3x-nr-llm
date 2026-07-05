<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;

/**
 * Opt-in recorder that captures each step of a {@see ToolLoopService} run for
 * the admin playground's inspector view.
 *
 * A caller that wants the glass-box view constructs a RunTrace and passes it
 * into {@see ToolLoopService::runLoop()}; the service records one step per
 * model round-trip (messages sent, tools offered, the response, timing and the
 * token split) and one per executed tool call. When no RunTrace is passed
 * (every production caller) the loop does no recording at all — the trace is
 * pure overhead-free instrumentation, never part of the loop's control flow.
 *
 * It is a mutable collector, deliberately NOT a readonly value object: the
 * readonly transport type is {@see RunStep}, which this hands out via
 * {@see self::getSteps()}.
 */
final class RunTrace
{
    /** @var list<RunStep> */
    private array $steps = [];

    /**
     * @param bool $captureRaw When true the loop asks the provider to retain
     *                         the decoded raw response so it can be inspected.
     *                         Off by default so production runs never keep it.
     */
    public function __construct(private readonly bool $captureRaw = false) {}

    public function capturesRaw(): bool
    {
        return $this->captureRaw;
    }

    /**
     * Record one model round-trip.
     *
     * @param list<ChatMessage|array<string, mixed>> $messagesSent messages sent this round
     * @param list<string>                           $toolSpecs    names of the tools offered this round
     */
    public function recordLlmCall(
        int $round,
        float $durationMs,
        array $messagesSent,
        array $toolSpecs,
        CompletionResponse $response,
    ): void {
        $raw = null;
        if (is_array($response->metadata)
            && array_key_exists('_raw', $response->metadata)
            && is_array($response->metadata['_raw'])
        ) {
            /** @var array<string, mixed> $raw */
            $raw = $response->metadata['_raw'];
        }

        $requestedToolCalls = null;
        if ($response->toolCalls !== null) {
            $requestedToolCalls = array_map(
                static fn(ToolCall $call): array => [
                    'id'        => $call->id,
                    'name'      => $call->name,
                    'arguments' => $call->arguments,
                ],
                $response->toolCalls,
            );
        }

        $this->steps[] = new RunStep(
            kind: RunStep::KIND_LLM,
            round: $round,
            durationMs: $durationMs,
            messagesSent: self::snapshotMessages($messagesSent),
            toolSpecs: $toolSpecs,
            content: $response->content,
            thinking: $response->thinking,
            finishReason: $response->finishReason,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            totalTokens: $response->usage->totalTokens,
            estimatedCost: $response->usage->estimatedCost,
            requestedToolCalls: $requestedToolCalls,
            raw: $raw,
        );
    }

    /**
     * Record one executed tool call.
     *
     * @param array<string, mixed> $arguments
     */
    public function recordToolExecution(
        int $round,
        float $durationMs,
        string $name,
        array $arguments,
        string $result,
        bool $isError,
    ): void {
        $this->steps[] = new RunStep(
            kind: RunStep::KIND_TOOL,
            round: $round,
            durationMs: $durationMs,
            toolName: $name,
            toolArguments: $arguments,
            toolResult: $result,
            toolIsError: $isError,
        );
    }

    /**
     * Record the fully-assembled message list for a dry run (no provider call).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function recordAssembledMessages(array $messages): void
    {
        $this->steps[] = new RunStep(
            kind: RunStep::KIND_ASSEMBLED,
            round: 0,
            durationMs: 0.0,
            messagesSent: self::snapshotMessages($messages),
        );
    }

    /**
     * @return list<RunStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Normalise a mixed message list (ChatMessage objects and raw
     * OpenAI-shaped arrays) into plain arrays for the JSON snapshot.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private static function snapshotMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $message instanceof ChatMessage ? $message->toArray() : $message;
        }

        return $out;
    }
}
