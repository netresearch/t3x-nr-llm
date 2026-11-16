<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aEndCheckedHandlerInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aOutputReports;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aResultReporterInterface;
use Netresearch\NrLlm\Service\Editorial\ExternalTextFinding;
use Netresearch\NrLlm\Service\Editorial\ExternalTextReport;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use Symfony\Component\Uid\Uuid;

use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * The report artifact of an editorial A2A task (ADR-301).
 *
 * A second artifact `report` with one data part, beside the content artifact
 * that holds only the text: a client that joins every text part takes nothing
 * from the report. It states the check of the end-of-run check
 * (ADR-302), bound to the delivered text by its digest, the rule state at
 * the start and at the check, and an editorial state apart from the task
 * state, because a completed task is no approval to publish.
 *
 * It reads the run's records only, inside the completing transaction. The
 * check is the latest `output_check` event of the run; its passages come
 * from the hand-over of the same process and are kept at the answer level:
 * as they are at `full`, redacted like the answer at `redacted`, none below.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aEditorialReporter implements A2aResultReporterInterface
{
    public const KIND = 'nr_llm.editorial_report';

    public const STATE_DRAFT = 'draft';

    public const STATE_NEEDS_REVISION = 'needs_revision';

    public const STATE_NEEDS_HUMAN_REVIEW = 'needs_human_review';

    public function __construct(
        private A2aSkillProfileRepository $profiles,
        private A2aDomainHandlerRegistry $handlers,
        private AgentRunRepository $runs,
        private A2aOutputReports $handOver,
        private RunContentPrivacyGuard $privacy,
    ) {}

    public function completed(int $runUid, int $profileUid, array $contentArtifacts, PrivacyLevel $ceiling): ?array
    {
        $handler = $this->endChecked($profileUid);
        if (!$handler instanceof A2aEndCheckedHandlerInterface) {
            return null;
        }

        $content   = $contentArtifacts[0] ?? [];
        $delivered = $this->textOf($content);
        $digest    = $delivered === null ? '' : ExternalTextReport::digestOf($delivered);

        // A check-only run judged the client's text as the answer level
        // keeps it; the client publishes its own text, so below `full` the
        // report vouches for nothing the client holds (ADR-301).
        $vouches = $ceiling === PrivacyLevel::FULL || !$handler->checksOnly();

        return $this->artifact($runUid, $ceiling, $digest, is_string($content['artifactId'] ?? null) ? $content['artifactId'] : '', false, $vouches);
    }

    public function stopped(int $runUid, int $profileUid, PrivacyLevel $ceiling, string $terminationReason): ?array
    {
        // Below `redacted` the task keeps no result at all (ADR-282): even the
        // terms a report names would say what the withheld text contained.
        if (!$ceiling->persistsContent() || !$this->endChecked($profileUid) instanceof A2aEndCheckedHandlerInterface) {
            // The report handed over stays with no later completion.
            $this->handOver->take($runUid);

            return null;
        }

        // Only a stop by the end-of-run check: a policy stop whose latest
        // check blocked, or a check that could not run.
        $check  = $this->runs->findLatestEvent($runUid, AgentEventKind::OUTPUT_CHECK->value);
        $blocks = $check instanceof AgentRunEvent
            && (($check->payload['blocks'] ?? false) === true || ($check->payload['status'] ?? '') === 'failed');
        if (!$blocks || $terminationReason !== AgentRunTerminationReason::POLICY_DENIED->value) {
            $this->handOver->take($runUid);

            return null;
        }

        return $this->artifact($runUid, $ceiling, '', '', true, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(int $runUid, PrivacyLevel $ceiling, string $deliveredDigest, string $contentArtifactId, bool $stopped, bool $vouches): array
    {
        $event  = $this->runs->findLatestEvent($runUid, AgentEventKind::OUTPUT_CHECK->value);
        $record = $event instanceof AgentRunEvent ? $event->payload : [];
        $full   = $this->handOver->take($runUid);

        // The record is a run event and follows the run's event level, which
        // may have blanked its digest and terms; the report of this process
        // follows the answer level. The record answers only where that
        // report is gone (another process).
        $checkedDigest = $full instanceof ExternalTextReport
            ? $full->textDigest
            : (is_string($record['textDigest'] ?? null) ? $record['textDigest'] : '');
        $status = is_string($record['status'] ?? null) ? $record['status'] : 'not_checked';
        $bound  = $stopped || ($checkedDigest !== '' && $checkedDigest === $deliveredDigest);
        if (!$bound) {
            // A text other than the checked one inherits nothing (X19).
            $status = 'not_checked';
        }

        $atStart = $this->ruleStateAtStart($runUid);
        $atCheck = is_string($record['ruleStateDigest'] ?? null) ? $record['ruleStateDigest'] : '';
        $changed = $atStart === '' || $atCheck === '' || $atStart !== $atCheck;
        // A term found in a text names what that text held: findings and
        // passages are only kept for the text the report is bound to, at the
        // level the check read. Otherwise a report of another text, a level
        // that tightened since, or a record whose terms were scrubbed
        // withholds them (and says so).
        $withheld = !$bound
            || ($record['answerLevel'] ?? '') !== $ceiling->value
            || (!$full instanceof ExternalTextReport && ($record['findingsWithheld'] ?? false) === true);
        $stored = $full instanceof ExternalTextReport ? $full->toArray()['findings'] : ($record['findings'] ?? []);

        $report = [
            'kind'           => self::KIND,
            'version'        => 1,
            'editorialState' => $this->editorialState($status, $record, !$stopped && $vouches, $bound, $changed),
            'check'          => [
                'status'           => $status,
                'blocks'           => is_bool($record['blocks'] ?? null) ? $record['blocks'] : $stopped,
                'reasons'          => $this->strings($record['reasons'] ?? []),
                'findings'         => $withheld ? [] : $this->findings($stored, $full, $ceiling),
                'findingsWithheld' => $withheld,
            ],
            // A digest of a text the report withholds lets a client confirm
            // a guessed text, which the answer level must not allow.
            'checkedTextDigest'   => $withheld ? '' : $checkedDigest,
            'deliveredTextDigest' => $deliveredDigest,
            'contentArtifactId'   => $contentArtifactId,
            'ruleState'           => ['atStart' => $atStart, 'atCheck' => $atCheck, 'changed' => $changed],
            'generator'           => ['configuration' => is_string($record['configuration'] ?? null) ? $record['configuration'] : ''],
            'judges'              => is_array($record['judges'] ?? null) ? array_values($record['judges']) : [],
            'answerLevel'         => $ceiling->value,
        ];

        return ['artifactId' => Uuid::v7()->toRfc4122(), 'name' => 'report', 'parts' => [['data' => $report, 'mediaType' => 'application/json']]];
    }

    /**
     * Separate from the task state: a completed task is no approval to
     * publish. Only a text that passed, is the delivered one, was checked
     * against the rules of the start and is the text the client holds (not
     * a stopped one, not a client text read redacted) is a draft; a broken
     * rule asks for a revision; everything else asks for a person.
     *
     * @param array<array-key, mixed> $record
     */
    private function editorialState(string $status, array $record, bool $mayBeDraft, bool $bound, bool $ruleStateChanged): string
    {
        if ($status === 'violation' && ($record['checkFailed'] ?? false) !== true) {
            return $ruleStateChanged ? self::STATE_NEEDS_HUMAN_REVIEW : self::STATE_NEEDS_REVISION;
        }

        if ($mayBeDraft && $bound && $status === 'passed' && !$ruleStateChanged) {
            return self::STATE_DRAFT;
        }

        return self::STATE_NEEDS_HUMAN_REVIEW;
    }

    /**
     * The stored findings, with the passage of each rule finding from the
     * hand-over at the answer level; none where nothing was handed over.
     *
     * @return list<array<string, mixed>>
     */
    private function findings(mixed $stored, ?ExternalTextReport $full, PrivacyLevel $ceiling): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $findings = [];
        foreach (array_values($stored) as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $passage = '';
            $source  = $full?->findings[$index] ?? null;
            if ($source instanceof ExternalTextFinding && $source->kind === ExternalTextFinding::KIND_RULE && $ceiling->persistsContent()) {
                $passage = $this->privacy->keptAt($source->passage, $ceiling);
            }

            $findings[] = [
                'kind'       => is_string($finding['kind'] ?? null) ? $finding['kind'] : '',
                'source'     => is_string($finding['source'] ?? null) ? $finding['source'] : '',
                'subject'    => is_string($finding['subject'] ?? null) ? $finding['subject'] : '',
                'ruleNumber' => is_int($finding['ruleNumber'] ?? null) ? $finding['ruleNumber'] : 0,
                'passage'    => $passage,
            ];
        }

        return $findings;
    }

    private function ruleStateAtStart(int $runUid): string
    {
        foreach ($this->runs->findEvents($runUid) as $event) {
            if ($event->kind === AgentEventKind::RULE_STATE->value) {
                return is_string($event->payload['ruleStateDigest'] ?? null) ? $event->payload['ruleStateDigest'] : '';
            }
        }

        return '';
    }

    /**
     * The one text part of a content artifact, or null when it has another
     * shape: a report can only vouch for exactly one text.
     *
     * @param array<array-key, mixed>|mixed $artifact
     */
    private function textOf(mixed $artifact): ?string
    {
        $parts = is_array($artifact) && is_array($artifact['parts'] ?? null) ? $artifact['parts'] : [];
        if (count($parts) !== 1 || !is_array($parts[0] ?? null) || !is_string($parts[0]['text'] ?? null)) {
            return null;
        }

        return $parts[0]['text'];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $values)) : [];
    }

    private function endChecked(int $profileUid): ?A2aEndCheckedHandlerInterface
    {
        $profile = $profileUid > 0 ? $this->profiles->findByUid($profileUid) : null;
        $handler = $profile instanceof A2aSkillProfile ? $this->handlers->get($profile->handler) : null;

        return $handler instanceof A2aEndCheckedHandlerInterface ? $handler : null;
    }
}
