<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Timeline;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\ApprovalAttribution;
use Netresearch\NrLlm\Domain\Enum\ToolOutcome;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\SkillEvidence;
use Netresearch\NrLlm\Domain\ValueObject\SkillEvidenceEntry;
use Netresearch\NrLlm\Domain\ValueObject\SkillUsageEntry;
use Netresearch\NrLlm\Service\Evaluation\SkillEvidenceLookupInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Governance\RecordedGovernanceEvent;
use Netresearch\NrLlm\Service\Telemetry\TelemetryCall;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Throwable;

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
        'toolOutcome',
        'finishReason',
        'promptTokens',
        'completionTokens',
        'totalTokens',
        'estimatedCost',
        // Where the time of a model call went: the cached part of the prompt,
        // the HTTP call, and what the provider says it spent of that.
        'cachedPromptTokens',
        'httpMs',
        'providerProcessingMs',
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
        // The record a write produced (ADR-182). An identity, never a field the
        // record holds — and the one fact that lets an operator reading the
        // timeline go and look at what the run actually changed.
        'writeTargetTable',
        'writeTargetUid',
        // Whether that write did all it planned, and whether a hook failed
        // after it (ADR-214): two flags an operator needs before trusting the
        // record named above.
        'writeCompleteness',
        'hookFailedAfterWrite',
        // Why an offered tool did not run (ADR-246): a code, not the text.
        'toolRefusal',
        // How many records a call's DataHandler runs wrote and how far that
        // list reaches (ADR-292): counts and a code, never the records.
        'changeCount',
        'changeCoverage',
        'changesOmitted',
    ];

    public function __construct(
        private TelemetryRepositoryInterface $telemetry,
        private GovernanceEventRepositoryInterface $governance,
        // Whether an evaluation a run recorded as evidence is still stored
        // (ADR-322). Absent, its availability is shown as unknown.
        private ?SkillEvidenceLookupInterface $skillEvidence = null,
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
            static fn (RunTimelineEntry $a, RunTimelineEntry $b): int => [$a->occurredAt, self::orderKey($a)] <=> [$b->occurredAt, self::orderKey($b)],
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
        $swapped = $call->servedProvider !== '' && ($call->servedProvider !== $call->provider || $call->servedModel !== $call->model);
        if ($swapped) {
            $facts['servedProvider'] = $call->servedProvider;
            $facts['servedModel']    = $call->servedModel;
        }

        // The model the provider named on its answer (ADR-320), compared with
        // the model that call was sent to: after a swap that is the serving
        // one. Shown only where it differs, by the same rule as the serving
        // pair: an alias resolved to a dated snapshot is the fact worth seeing,
        // a repetition of the requested name is not.
        $sentTo = $swapped ? $call->servedModel : $call->model;
        if ($call->responseModel !== '' && $call->responseModel !== $sentTo) {
            $facts['reportedModel'] = $call->responseModel;
        }

        $facts['latencyMs'] = (string) $call->latencyMs;

        if ($call->timeToFirstTokenMs !== null) {
            $facts['ttftMs'] = (string) $call->timeToFirstTokenMs;
        }

        if ($call->cacheHit) {
            $facts['cacheHit'] = '1';
        }

        if ($call->fallbackAttempts > 0) {
            $facts['fallbackAttempts'] = (string) $call->fallbackAttempts;
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
            durationMs: (float) $call->latencyMs,
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
     * Why a guardrail stopped the run, in the guardrail's own words.
     *
     * A background run has nobody waiting for the exception, so the governance
     * row is the only place its reason survives. Empty for a run a guardrail
     * did not stop. Oldest first means the last row is the one that ended it.
     */
    public function stopReason(AgentRun $run): string
    {
        $termination = $run->terminationReasonEnum();
        if ($termination !== AgentRunTerminationReason::POLICY_DENIED
            && $termination !== AgentRunTerminationReason::APPROVAL_DENIED) {
            return '';
        }

        $reason = '';
        foreach ($this->governance->findForRun($run->uid, $run->uuid) as $decision) {
            if ($decision->guardrail !== '') {
                $reason = $decision->detail;
            }
        }

        return $reason;
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

        $skills = $this->skillUsage($payload['skillUsage'] ?? null);
        if ($skills !== '') {
            $facts['skills'] = $skills;
        }

        $evidence = $this->skillEvidence($payload['skillEvidence'] ?? null);
        if ($evidence !== '') {
            $facts['evidence'] = $evidence;
        }

        return $this->join($facts);
    }

    /**
     * A skill evidence step (ADR-322), one part per version:
     * `#uid@digest: none`, or each evaluation as
     * `eval#N set model pass=0.90 fit=match`, with `report=gone` when
     * retention purged the evaluation since. The snapshot the run recorded is
     * what is shown; a newer evaluation of the same version never stands in
     * for it.
     */
    private function skillEvidence(mixed $stored): string
    {
        $parts = [];
        foreach (SkillEvidenceEntry::listFrom($stored) as $entry) {
            $items = [];
            foreach ($entry->evidence as $evidence) {
                $items[] = sprintf(
                    'eval#%d %s %s pass=%.2f fit=%s%s',
                    $evidence->evaluationUid,
                    $evidence->setIdentifier,
                    $evidence->reportedModelId !== '' ? $evidence->reportedModelId : ($evidence->modelId !== '' ? $evidence->modelId : '-'),
                    $evidence->passRate,
                    $evidence->fit->value,
                    $this->availability($evidence),
                );
            }

            $parts[] = sprintf(
                '#%d@%s: %s',
                $entry->skillUid,
                $entry->versionDigest === '' ? '-' : substr($entry->versionDigest, 0, 14),
                $items === [] ? 'none' : implode(' | ', $items),
            );
        }

        return implode(', ', $parts);
    }

    private function availability(SkillEvidence $evidence): string
    {
        if (!$this->skillEvidence instanceof SkillEvidenceLookupInterface) {
            return ' report=unknown';
        }

        try {
            return $this->skillEvidence->isAvailable($evidence) ? '' : ' report=gone';
        } catch (Throwable) {
            // The evaluation tables cannot be read: the snapshot still shows,
            // without a claim about whether its evaluation is still stored.
            return ' report=unknown';
        }
    }

    /**
     * A skill usage step's versions (ADR-321), one per skill:
     * `identifier#uid@digest role/inclusion/state approval=N`. The list is
     * structured, which the generic allow-list above would drop, so it is
     * rendered here from {@see SkillUsageEntry}, whose fields are identities,
     * a digest and codes — never a name, description or body.
     */
    private function skillUsage(mixed $stored): string
    {
        $parts = [];
        foreach (SkillUsageEntry::listFrom($stored) as $entry) {
            $parts[] = sprintf(
                '%s#%d@%s %s/%s/%s%s',
                // The identifier is text an administrator or a source chose;
                // the separators of this line are replaced, so it cannot read
                // as a further entry or fact.
                strtr($entry->identifier, [',' => '_', ';' => '_', '=' => '_']),
                $entry->skillUid,
                $entry->versionDigest === '' ? '-' : substr($entry->versionDigest, 0, 14),
                $entry->role->value,
                $entry->inclusion->value,
                $entry->state->value,
                $entry->approvalUid > 0 ? ' approval=' . $entry->approvalUid : ' approval=' . $entry->approvalState->value,
            );
        }

        return implode(', ', $parts);
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
     * A step states an outcome only when it is a tool step.
     *
     * `toolIsError` decides WHETHER there is an outcome to state, because it is
     * the field every tool step has ever carried; `toolOutcome` decides WHICH,
     * and is absent from every row written before ADR-191. Reading the boolean
     * first is what makes those rows render as they always did instead of
     * losing their outcome to a field they never had.
     *
     * @param array<string, mixed> $payload
     */
    private function stepOutcome(array $payload): string
    {
        $isError = $payload['toolIsError'] ?? null;
        if (!is_bool($isError)) {
            return RunTimelineEntry::OUTCOME_NONE;
        }

        $outcome = $payload['toolOutcome'] ?? null;
        if (is_string($outcome) && ToolOutcome::tryFrom($outcome) instanceof ToolOutcome) {
            return $outcome;
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
            return (string) $value;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $parts[] = (string) $item;
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
