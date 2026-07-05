<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One recorded step of an inspectable {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
 * run, as gathered by {@see \Netresearch\NrLlm\Service\Tool\RunTrace}.
 *
 * A step is one of three kinds:
 * - {@see self::KIND_LLM}: a single model round-trip — the messages sent, the
 *   tool specs offered, the assistant content + thinking, the tool calls the
 *   model requested, timing, the prompt/completion/total token split, the
 *   estimated cost (when the provider reported it) and — only when raw capture
 *   was requested — the decoded provider response body.
 * - {@see self::KIND_TOOL}: one executed tool call — its name, arguments, the
 *   returned string, an error flag and the execution timing.
 * - {@see self::KIND_ASSEMBLED}: a dry run — the fully assembled message list
 *   (system + snippets + skills + user) that WOULD have been sent, with no
 *   provider call made.
 *
 * A readonly transport object: the mutable collector is
 * {@see \Netresearch\NrLlm\Service\Tool\RunTrace}.
 */
final readonly class RunStep
{
    public const KIND_LLM = 'llm';

    public const KIND_TOOL = 'tool';

    public const KIND_ASSEMBLED = 'assembled';

    /**
     * @param list<array<string, mixed>>|null                                             $messagesSent       Snapshot of the messages sent this round (LLM/assembled).
     * @param list<string>|null                                                           $toolSpecs          Names of the tools offered this round (LLM).
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}>|null $requestedToolCalls Tool calls the model asked for (LLM).
     * @param array<string, mixed>|null                                                   $raw                Decoded raw provider response — only when capture was requested (LLM).
     * @param array<string, mixed>|null                                                   $toolArguments      Arguments the model supplied for a tool call (TOOL).
     */
    public function __construct(
        public string $kind,
        public int $round,
        public float $durationMs,
        public ?array $messagesSent = null,
        public ?array $toolSpecs = null,
        public ?string $content = null,
        public ?string $thinking = null,
        public ?string $finishReason = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
        public ?float $estimatedCost = null,
        public ?array $requestedToolCalls = null,
        public ?array $raw = null,
        public ?string $toolName = null,
        public ?array $toolArguments = null,
        public ?string $toolResult = null,
        public ?bool $toolIsError = null,
    ) {}

    /**
     * Serialise for the playground JSON payload. Null fields are dropped so the
     * client only receives the keys relevant to the step's kind.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'kind'       => $this->kind,
            'round'      => $this->round,
            'durationMs' => round($this->durationMs, 2),
        ];

        $optional = [
            'messagesSent'       => $this->messagesSent,
            'toolSpecs'          => $this->toolSpecs,
            'content'            => $this->content,
            'thinking'           => $this->thinking,
            'finishReason'       => $this->finishReason,
            'promptTokens'       => $this->promptTokens,
            'completionTokens'   => $this->completionTokens,
            'totalTokens'        => $this->totalTokens,
            'estimatedCost'      => $this->estimatedCost,
            'requestedToolCalls' => $this->requestedToolCalls,
            'raw'                => $this->raw,
            'toolName'           => $this->toolName,
            'toolArguments'      => $this->toolArguments,
            'toolResult'         => $this->toolResult,
            'toolIsError'        => $this->toolIsError,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
