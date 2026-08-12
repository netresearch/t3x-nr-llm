<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Timeline;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Governance\RecordedGovernanceEvent;
use Netresearch\NrLlm\Service\Telemetry\TelemetryCall;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;

/**
 * Assembles one agent run's three record streams into a single ordered timeline
 * (ADR-153).
 *
 * Until the run's provider calls inherited its uuid as their correlation id,
 * these streams could not be joined at all: the steps were the run's, the
 * telemetry rows had a fresh id per round, and the governance rows had a zero
 * run uid. They are joined here — steps as handed in by the caller (which got
 * them from the authorising {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface}),
 * telemetry by correlation id, governance by run uid OR correlation id.
 *
 * PRIVACY: what a step contributes is an allow-list of non-content keys
 * ({@see self::STEP_FACTS}). {@see \Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter}
 * already drops content before a step is persisted at the default level, and
 * this list means an installation running at REDACTED or FULL does not silently
 * turn this page into a transcript viewer.
 *
 * AUTHORISATION: none happens here. The caller must have obtained the run and
 * its events through the runtime, which authorises; this class is handed the
 * run it may already read and only widens it with rows keyed on that run.
 *
 * @internal
 */
final readonly class RunTimelineFactory
{
    /**
     * Payload keys a step may contribute, in display order. Every one of them is
     * a count, a size, a name, a token figure or a flag — never a message, an
     * answer, a tool argument or a tool result.
     *
     * @var list<string>
     */
    private const STEP_FACTS = [
        'toolName',
        'toolIsError',
        'finishReason',
        'promptTokens',
        'completionTokens',
        'totalTokens',
        'estimatedCost',
        'messagesSentCount',
        'contentLength',
        'thinkingLength',
        'toolResultLength',
        'toolArtifactsCount',
        'toolArtifactTypes',
        'toolSpecs',
        'requestedToolNames',
        'contentRedacted',
        'approved',
        'decidedBy',
    ];

    public function __construct(
        private TelemetryRepositoryInterface $telemetry,
        private GovernanceEventRepositoryInterface $governance,
    ) {}

    /**
     * The run's steps, provider calls and governance decisions in one list,
     * oldest first.
     *
     * @param list<AgentRunEvent> $events the run's persisted step stream, already authorised
     *
     * @return list<RunTimelineEntry>
     */
    public function build(AgentRun $run, array $events): array
    {
        $entries = [];

        foreach ($events as $event) {
            $entries[] = new RunTimelineEntry(
                source: RunTimelineEntry::SOURCE_STEP,
                kind: $event->kind,
                occurredAt: $event->crdate,
                sequence: $event->sequence,
                round: $event->round,
                durationMs: $event->durationMs,
                detail: $this->stepDetail($event->payload),
                outcome: $this->stepOutcome($event->payload),
            );
        }

        foreach ($this->telemetry->findByCorrelation($run->uuid) as $call) {
            $entries[] = $this->callEntry($call);
        }

        foreach ($this->governance->findForRun($run->uid, $run->uuid) as $decision) {
            $entries[] = $this->governanceEntry($decision);
        }

        // Time first, then the step sequence — the only strictly ordered key of
        // the three streams. Rows without one (sequence -1) sort after the steps
        // of the same second, which is where a call and its governance verdict
        // belong: they happen inside the step that triggered them.
        usort(
            $entries,
            static fn(RunTimelineEntry $a, RunTimelineEntry $b): int => [$a->occurredAt, self::orderKey($a)] <=> [$b->occurredAt, self::orderKey($b)],
        );

        return $entries;
    }

    /**
     * The secondary sort key: a step's own sequence, and "last within this
     * second" for a row that has none.
     */
    private static function orderKey(RunTimelineEntry $entry): int
    {
        return $entry->sequence < 0 ? PHP_INT_MAX : $entry->sequence;
    }

    private function callEntry(TelemetryCall $call): RunTimelineEntry
    {
        $facts = [
            'provider' => $call->provider,
            'model'    => $call->model,
        ];

        // Only when a fallback actually swapped: repeating the requested pair as
        // the served one on every row would bury the rescues that matter.
        if ($call->servedProvider !== '' && ($call->servedProvider !== $call->provider || $call->servedModel !== $call->model)) {
            $facts['servedProvider'] = $call->servedProvider;
            $facts['servedModel']    = $call->servedModel;
        }

        $facts['latencyMs'] = (string)$call->latencyMs;

        if ($call->timeToFirstTokenMs !== null) {
            $facts['ttftMs'] = (string)$call->timeToFirstTokenMs;
        }

        if ($call->cacheHit) {
            $facts['cacheHit'] = '1';
        }

        if ($call->fallbackAttempts > 0) {
            $facts['fallbackAttempts'] = (string)$call->fallbackAttempts;
        }

        if ($call->errorClass !== '') {
            $facts['errorClass'] = $call->errorClass;
        }

        return new RunTimelineEntry(
            source: RunTimelineEntry::SOURCE_CALL,
            kind: $call->operation,
            occurredAt: $call->crdate,
            sequence: -1,
            round: 0,
            durationMs: (float)$call->latencyMs,
            detail: $this->join($facts),
            outcome: $call->success ? RunTimelineEntry::OUTCOME_OK : RunTimelineEntry::OUTCOME_FAILED,
        );
    }

    private function governanceEntry(RecordedGovernanceEvent $event): RunTimelineEntry
    {
        $facts = ['reason' => $event->reason];

        if ($event->toolName !== '') {
            $facts['tool'] = $event->toolName;
        }

        if ($event->guardrail !== '') {
            $facts['guardrail'] = $event->guardrail;
        }

        if ($event->detail !== '') {
            $facts['detail'] = $event->detail;
        }

        return new RunTimelineEntry(
            source: RunTimelineEntry::SOURCE_GOVERNANCE,
            kind: $event->decision,
            occurredAt: $event->crdate,
            sequence: -1,
            round: 0,
            durationMs: 0.0,
            detail: $this->join($facts),
            // A governance row is always a refusal, an approval requirement or an
            // observed-only would-be refusal — never a success.
            outcome: RunTimelineEntry::OUTCOME_FAILED,
        );
    }

    /**
     * The allow-listed metadata of a step payload, as display pairs.
     *
     * @param array<string, mixed> $payload
     */
    private function stepDetail(array $payload): string
    {
        $facts = [];
        foreach (self::STEP_FACTS as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $this->scalarise($payload[$key]);
            if ($value !== null) {
                $facts[$key] = $value;
            }
        }

        return $this->join($facts);
    }

    /**
     * A step states an outcome only when it is a tool step: `toolIsError` is the
     * one success/failure flag a step payload carries.
     *
     * @param array<string, mixed> $payload
     */
    private function stepOutcome(array $payload): string
    {
        $isError = $payload['toolIsError'] ?? null;
        if (!is_bool($isError)) {
            return RunTimelineEntry::OUTCOME_NONE;
        }

        return $isError ? RunTimelineEntry::OUTCOME_FAILED : RunTimelineEntry::OUTCOME_OK;
    }

    /**
     * Render one allow-listed value. Scalars become their string form; a list of
     * scalars becomes a comma-joined string (tool names, artifact types).
     * Anything else — a nested structure the allow-list did not anticipate — is
     * dropped rather than serialised, so an unexpected shape cannot leak.
     */
    private function scalarise(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $parts[] = (string)$item;
                }
            }

            return $parts === [] ? null : implode(', ', $parts);
        }

        return null;
    }

    /**
     * @param array<string, string> $facts
     */
    private function join(array $facts): string
    {
        $parts = [];
        foreach ($facts as $key => $value) {
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return implode('; ', $parts);
    }
}
