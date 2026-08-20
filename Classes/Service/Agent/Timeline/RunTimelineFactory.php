<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Timeline;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\ApprovalAttribution;
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
        // The forced sources a run asked for and did not get (ADR-179). On the
        // list because the absence is invisible everywhere else: a source that
        // never arrived leaves no mark in the transcript, which is exactly why
        // the operator cannot see it today.
        'droppedSources',
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
                approvalAttribution: $this->approvalAttribution($run, $event),
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
     * Who decided this row, relative to who started the run (ADR-173) — `''` for
     * every row that is not a GRANTED approval.
     *
     * `decidedBy` is already in {@see self::STEP_FACTS}, so the uid was on the
     * page; what was missing is the comparison against the run's own initiator.
     * Among the surfaces that PRESENT it, {@see ApprovalAttribution} is the only
     * place it is made, which is what lets the inbox state the same fact without
     * a second definition of it. POLICY compares the same two uids again, in
     * {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::isInitiatorOf()}
     * (ADR-172's four-eyes gate) — by a stricter rule that also excludes service
     * accounts, so it is not this comparison duplicated.
     *
     * A denial carries no attribution on purpose: ADR-172 allows an initiator to
     * deny their own run, so flagging that would mark the case the design calls
     * correct.
     */
    private function approvalAttribution(AgentRun $run, AgentRunEvent $event): string
    {
        if ($event->kind !== AgentEventKind::APPROVAL->value) {
            return RunTimelineEntry::ATTRIBUTION_NONE;
        }

        if (($event->payload['approved'] ?? null) !== true) {
            return RunTimelineEntry::ATTRIBUTION_NONE;
        }

        $decidedBy = $event->payload['decidedBy'] ?? null;

        return ApprovalAttribution::fromDecision($run->beUser, is_int($decidedBy) ? $decidedBy : 0)->value;
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
