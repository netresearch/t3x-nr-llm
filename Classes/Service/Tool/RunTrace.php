<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Closure;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;

/**
 * Opt-in recorder that captures each step of a {@see ToolLoopService} run for
 * the admin playground's inspector view.
 *
 * A caller that wants the glass-box view constructs a RunTrace and passes it
 * into {@see ToolLoopService::runLoop()}; the service records a request step
 * (messages sent, tools offered) BEFORE each provider call, a response step
 * (content, timing, token split) after it, and one step per executed tool
 * call. When no RunTrace is passed
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
     * @param bool                          $captureRaw When true the loop asks the provider to retain
     *                                                  the decoded raw response so it can be inspected.
     *                                                  Off by default so production runs never keep it.
     * @param (Closure(RunStep): void)|null $onRecord   Fired the moment each step is recorded, so a
     *                                                  caller can stream steps live as the loop runs.
     *                                                  Null (every production/test caller) ⇒ collect only.
     */
    public function __construct(
        private readonly bool $captureRaw = false,
        private readonly ?Closure $onRecord = null,
    ) {}

    public function capturesRaw(): bool
    {
        return $this->captureRaw;
    }

    /**
     * Record the outbound half of a model round-trip, BEFORE the provider call.
     *
     * Fired through {@see self::add()} immediately, so a live listener streams
     * the request the moment it goes out — the inspector shows activity from
     * second zero instead of only after the first response arrives.
     *
     * @param list<ChatMessage|array<string, mixed>> $messagesSent messages sent this round
     * @param list<string>                           $toolSpecs    names of the tools offered this round
     */
    public function recordRequest(int $round, array $messagesSent, array $toolSpecs): void
    {
        $this->add(new RunStep(
            kind: RunStep::KIND_REQUEST,
            round: $round,
            durationMs: 0.0,
            messagesSent: self::snapshotMessages($messagesSent),
            toolSpecs: $toolSpecs,
        ));
    }

    /**
     * Record the response half of a model round-trip. The messages sent and
     * tools offered live on the preceding {@see self::recordRequest()} step —
     * not here — so the (potentially large) message array is serialised once
     * per round, not twice.
     */
    public function recordLlmCall(
        int $round,
        float $durationMs,
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

        $this->add(new RunStep(
            kind: RunStep::KIND_LLM,
            round: $round,
            durationMs: $durationMs,
            content: $response->content,
            thinking: $response->thinking,
            finishReason: $response->finishReason,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            totalTokens: $response->usage->totalTokens,
            estimatedCost: $response->usage->estimatedCost,
            requestedToolCalls: $requestedToolCalls,
            raw: $raw,
        ));
    }

    /**
     * Record one executed tool call.
     *
     * @param array<string, mixed> $arguments
     * @param list<ToolArtifact>   $artifacts run-only structured artifacts (NEVER provider-facing)
     */
    public function recordToolExecution(
        int $round,
        float $durationMs,
        string $name,
        array $arguments,
        string $result,
        bool $isError,
        array $artifacts = [],
    ): void {
        $this->add(new RunStep(
            kind: RunStep::KIND_TOOL,
            round: $round,
            durationMs: $durationMs,
            toolName: $name,
            toolArguments: $arguments,
            toolResult: $result,
            toolIsError: $isError,
            toolArtifacts: $artifacts === [] ? null : $artifacts,
        ));
    }

    /**
     * Record the fully-assembled message list for a dry run (no provider call).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function recordAssembledMessages(array $messages): void
    {
        $this->add(new RunStep(
            kind: RunStep::KIND_ASSEMBLED,
            round: 0,
            durationMs: 0.0,
            messagesSent: self::snapshotMessages($messages),
        ));
    }

    /**
     * @return list<RunStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Append a step and, if a live listener was provided, hand it the step at
     * once so the caller can stream it before the run finishes.
     */
    private function add(RunStep $step): void
    {
        $this->steps[] = $step;
        if ($this->onRecord !== null) {
            ($this->onRecord)($step);
        }
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
