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
 * A step is one of five kinds:
 * - {@see self::KIND_CONTEXT}: the context-window accounting for the round
 *   that is about to be sent (ADR-151) — recorded BEFORE the request step,
 *   because the fit is what decides which messages the request carries.
 * - {@see self::KIND_REQUEST}: the outbound half of a model round-trip,
 *   recorded (and streamed) BEFORE the provider call — the messages sent and
 *   the tool specs offered this round. Carries no timing/tokens; those belong
 *   to the response.
 * - {@see self::KIND_LLM}: the response half of a model round-trip — the
 *   assistant content + thinking, the tool calls the model requested, timing,
 *   the prompt/completion/total token split, the estimated cost (when the
 *   provider reported it) and — only when raw capture was requested — the
 *   decoded provider response body.
 * - {@see self::KIND_TOOL}: one executed tool call — its name, arguments, the
 *   returned string, an error flag, the execution timing and any run-only
 *   structured artifacts.
 * - {@see self::KIND_ASSEMBLED}: a dry run — the fully assembled message list
 *   (system + snippets + skills + user) that WOULD have been sent, with no
 *   provider call made.
 *
 * A readonly transport object: the mutable collector is
 * {@see \Netresearch\NrLlm\Service\Tool\RunTrace}.
 */
final readonly class RunStep
{
    public const KIND_REQUEST = 'request';

    public const KIND_LLM = 'llm';

    public const KIND_TOOL = 'tool';

    public const KIND_ASSEMBLED = 'assembled';

    public const KIND_CONTEXT = 'context';

    /**
     * Forced sources the run asked for and did not get (ADR-179).
     *
     * Written once, before the first round, and only when something was
     * actually dropped — a run whose sources all resolved records no step, so
     * the step's presence is itself the signal.
     */
    public const KIND_DROPPED = 'dropped';

    /**
     * The record one tool write produced (ADR-182).
     *
     * Its own kind rather than a field on {@see self::KIND_TOOL}, for the same
     * reason {@see self::KIND_DROPPED} is its own: the step's PRESENCE is the
     * signal a later reader queries for, and a nullable field on every tool step
     * would make "no write" and "write not recorded" the same row.
     *
     * It carries an identity and nothing the record holds — see
     * {@see \Netresearch\NrLlm\Domain\ValueObject\RecordReference}.
     */
    public const KIND_WRITE = 'tool_write';

    /**
     * Every kind a step can carry, as the class's own statement of its
     * vocabulary.
     *
     * It exists because that vocabulary has a second reader —
     * {@see \Netresearch\NrLlm\Domain\Enum\AgentEventKind}, which persists
     * these values and has twice fallen behind them (#900). The check that
     * holds the two together needs the list from here; asking reflection for it
     * reads the class's internals to learn something the class can simply say.
     *
     * This does not make forgetting impossible — a constant added without a
     * line here is still a gap. It moves the gap from two files and two ADRs
     * apart to three lines apart, and the enum check then fails rather than the
     * absence going unnoticed for a release.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_REQUEST,
            self::KIND_LLM,
            self::KIND_TOOL,
            self::KIND_ASSEMBLED,
            self::KIND_CONTEXT,
            self::KIND_DROPPED,
            self::KIND_WRITE,
        ];
    }

    /**
     * @param list<array<string, mixed>>|null                                             $messagesSent       Snapshot of the messages sent this round (REQUEST/assembled).
     * @param list<string>|null                                                           $toolSpecs          Names of the tools offered this round (REQUEST).
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}>|null $requestedToolCalls Tool calls the model asked for (LLM).
     * @param array<string, mixed>|null                                                   $raw                Decoded raw provider response — only when capture was requested (LLM).
     * @param array<string, mixed>|null                                                   $toolArguments      Arguments the model supplied for a tool call (TOOL).
     * @param list<ToolArtifact>|null                                                     $toolArtifacts      Run-only structured artifacts a tool attached (TOOL); NEVER provider-facing.
     * @param ContextBudgetBreakdown|null                                                 $contextBudget      Where the window went for this round (CONTEXT).
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
        public ?array $toolArtifacts = null,
        public ?ContextBudgetBreakdown $contextBudget = null,
        /**
         * @var list<DroppedSource>|null the sources this run asked for and did
         *                               not get; null on every other kind
         */
        public ?array $droppedSources = null,
        /**
         * @var RecordReference|null the record this tool write produced; null
         *                           on every other kind (ADR-182)
         */
        public ?RecordReference $writeTarget = null,
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
            // Flattened to "kind#uid reason" strings rather than nested objects:
            // the timeline's allow-list renders scalars and simple lists, and a
            // count alone would flatten the two reasons ADR-179 keeps apart.
            // uid and reason are metadata, not content — the privacy filter's
            // concern is the transcript, and nothing here carries prose.
            'droppedSources'     => $this->droppedSources === null ? null : array_map(
                static fn(DroppedSource $d): string => sprintf('%s#%d %s', $d->kind, $d->uid, $d->reason->value),
                $this->droppedSources,
            ),
            'toolName'           => $this->toolName,
            'toolArguments'      => $this->toolArguments,
            'toolResult'         => $this->toolResult,
            'toolIsError'        => $this->toolIsError,
            'toolArtifacts'      => $this->toolArtifacts === null
                ? null
                : array_map(static fn(ToolArtifact $a): array => $a->toArray(), $this->toolArtifacts),
            'contextBudget'      => $this->contextBudget?->toArray(),
            // Identity only: table and uid, never a field the record holds
            // (ADR-182, ADR-064). Two scalars rather than one nested pair, for
            // the reason droppedSources is flattened above: the timeline renders
            // scalars and simple lists, and the outcome join wants a value it can
            // read without parsing a composite string.
            'writeTargetTable'   => $this->writeTarget?->table,
            'writeTargetUid'     => $this->writeTarget?->uid,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
