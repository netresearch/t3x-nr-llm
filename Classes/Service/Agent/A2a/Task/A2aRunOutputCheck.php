<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aEndCheckedHandlerInterface;
use Netresearch\NrLlm\Service\Agent\RunOutputCheckInterface;
use Netresearch\NrLlm\Service\Agent\RunOutputCheckPlan;
use Netresearch\NrLlm\Service\Agent\RunOutputVerdict;
use Netresearch\NrLlm\Service\Editorial\ExternalTextCheck;
use Netresearch\NrLlm\Service\Editorial\ExternalTextCheckScope;
use Netresearch\NrLlm\Service\Editorial\ExternalTextFinding;
use Netresearch\NrLlm\Service\Editorial\ExternalTextReport;
use Netresearch\NrLlm\Service\Editorial\RuleStateDigest;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function array_map;
use function implode;
use function is_numeric;
use function sprintf;

/**
 * The end-of-run check of an A2A task whose profile names an end-checked
 * handler (ADR-302).
 *
 * The text checked is the text the content artifact will carry: the answer as
 * the current answer level keeps it, shaped by the handler. The level is read
 * again under the lock at completion; a level changed in between makes the
 * delivered text another one than the checked one, which the report of the
 * completion has to state (ADR-301). Where the level keeps no answer nothing is delivered and nothing
 * is judged.
 *
 * The result is kept as a run event without a passage of the text, so it may
 * stay at every privacy level.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRunOutputCheck implements RunOutputCheckInterface
{
    /** The answer level keeps no content: nothing is delivered, nothing judged. */
    public const REASON_NOT_RETAINED = 'not_retained';

    public function __construct(
        private ConnectionPool $connectionPool,
        private A2aSkillProfileRepository $profiles,
        private A2aDomainHandlerRegistry $handlers,
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurations,
        private RunContentPrivacyGuard $privacy,
        private ExternalTextCheck $check,
        private RuleStateDigest $ruleState,
        private LoggerInterface $logger,
    ) {}

    public function planFor(AgentRunHandle $handle, bool $start): RunOutputCheckPlan
    {
        $handler = $this->handlerOf($handle->runUid);
        if (!$handler instanceof A2aEndCheckedHandlerInterface) {
            // A run checked at its start stays checked: a profile switched to
            // another handler meanwhile does not let a later segment, or the
            // same run claimed again as a start, deliver unchecked; the run
            // fails instead.
            if ($this->persister->findLatestRuntimeRecord($handle, AgentEventKind::RULE_STATE) !== null) {
                throw new A2aResultNotRetainableException(sprintf('Run %s was checked at its start and its profile no longer names an end-checked handler.', $handle->uuid), 1799430303);
            }

            return RunOutputCheckPlan::none();
        }

        if ($start) {
            $this->recordRuleState($handle);
        }

        return RunOutputCheckPlan::check($handler->checksOnly());
    }

    public function check(AgentRunHandle $handle, string $answer): RunOutputVerdict
    {
        try {
            $report = $this->report($handle, $answer);
        } catch (Throwable $failure) {
            $this->logger->error('The end-of-run check could not run; the answer is not delivered.', ['run' => $handle->uuid, 'exception' => $failure]);
            $this->persister->recordRuntimeRecord($handle, AgentEventKind::OUTPUT_CHECK, [
                'status'  => 'failed',
                'reasons' => [ExternalTextReport::REASON_CHECK_FAILED],
            ]);

            return RunOutputVerdict::block('The editorial check could not run.');
        }

        if (!$report instanceof ExternalTextReport) {
            return RunOutputVerdict::deliver();
        }

        return $report->blocks() ? RunOutputVerdict::block($this->reason($report)) : RunOutputVerdict::deliver();
    }

    /**
     * The report of the delivered text, kept with the run; null where the
     * answer level keeps nothing to deliver.
     */
    private function report(AgentRunHandle $handle, string $answer): ?ExternalTextReport
    {
        $handler = $this->handlerOf($handle->runUid);
        $run     = $this->persister->findRun($handle->uuid);
        if (!$handler instanceof A2aEndCheckedHandlerInterface || !$run instanceof AgentRun) {
            throw new A2aResultNotRetainableException(sprintf('The run %s or its handler is gone.', $handle->uuid), 1799430301);
        }

        $configuration = $this->configurations->findByUid($run->configurationUid);
        if (!$configuration instanceof LlmConfiguration) {
            throw new A2aResultNotRetainableException(sprintf('The configuration of run %s is gone.', $handle->uuid), 1799430302);
        }

        $ceiling = $this->privacy->withAnswerCeiling($handle->runUid, static fn (PrivacyLevel $level): PrivacyLevel => $level);
        if (!$ceiling->persistsContent()) {
            $this->persister->recordRuntimeRecord($handle, AgentEventKind::OUTPUT_CHECK, [
                'status'      => 'not_checked',
                'reasons'     => [self::REASON_NOT_RETAINED],
                'answerLevel' => $ceiling->value,
            ]);

            return null;
        }

        $delivered = $handler->deliverableText($this->privacy->keptAt($answer, $ceiling));
        $report    = $this->check->check($delivered, $configuration, new ExternalTextCheckScope(
            beUserUid: $run->beUser,
            correlationId: $handle->uuid,
        ));

        $stored = $this->persister->recordRuntimeRecord($handle, AgentEventKind::OUTPUT_CHECK, [
            ...$this->withoutPassages($report),
            'answerLevel' => $ceiling->value,
        ]);
        if (!$stored) {
            // Without the record the result cannot say what was checked.
            throw new A2aResultNotRetainableException(sprintf('The check of run %s could not be stored.', $handle->uuid), 1799430304);
        }

        return $report;
    }

    private function recordRuleState(AgentRunHandle $handle): void
    {
        try {
            $run           = $this->persister->findRun($handle->uuid);
            $configuration = $run instanceof AgentRun ? $this->configurations->findByUid($run->configurationUid) : null;
            $digest        = $configuration instanceof LlmConfiguration ? $this->ruleState->of($configuration) : '';
        } catch (Throwable $failure) {
            $this->logger->warning('The rule state at the start of a run could not be read.', ['run' => $handle->uuid, 'exception' => $failure]);
            $digest = '';
        }

        // Unknown is recorded as unknown: the report then cannot say the
        // rules held, and asks for a person (ADR-301). Without the record a
        // later segment could not tell that the run is checked: fail closed.
        if (!$this->persister->recordRuntimeRecord($handle, AgentEventKind::RULE_STATE, ['ruleStateDigest' => $digest])) {
            throw new A2aResultNotRetainableException(sprintf('The rule state of run %s could not be stored.', $handle->uuid), 1799430305);
        }
    }

    /**
     * The report as a run event keeps it: rules, terms, statuses and digests,
     * never a passage of the text.
     *
     * @return array<string, mixed>
     */
    private function withoutPassages(ExternalTextReport $report): array
    {
        $record             = $report->toArray();
        $record['findings'] = array_map(
            static function (array $finding): array {
                $finding['passage'] = '';

                return $finding;
            },
            $record['findings'],
        );

        return $record;
    }

    /**
     * Why the text is not delivered: the rules and terms, never a passage.
     */
    private function reason(ExternalTextReport $report): string
    {
        $parts = array_map(
            static fn (ExternalTextFinding $finding): string => match ($finding->kind) {
                ExternalTextFinding::KIND_FORBIDDEN_TERM => sprintf('forbidden term "%s" (list "%s")', $finding->subject, $finding->source),
                ExternalTextFinding::KIND_MISSING_TERM   => sprintf('required term "%s" missing (list "%s")', $finding->subject, $finding->source),
                default                                  => sprintf('rule %d "%s" (snippet "%s")', $finding->ruleNumber, $finding->subject, $finding->source),
            },
            $report->findings,
        );
        if ($report->checkFailed) {
            $parts[] = 'the check did not complete (' . implode(', ', $report->reasons) . ')';
        }

        return 'The editorial check stopped the text: ' . implode('; ', $parts) . '.';
    }

    private function handlerOf(int $runUid): ?A2aEndCheckedHandlerInterface
    {
        $profileUid = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['profile_uid'], A2aTaskStore::TABLE, ['run_uid' => $runUid])
            ->fetchOne();
        if (!is_numeric($profileUid) || (int) $profileUid <= 0) {
            return null;
        }

        $profile = $this->profiles->findByUid((int) $profileUid);
        $handler = $profile instanceof A2aSkillProfile ? $this->handlers->get($profile->handler) : null;

        return $handler instanceof A2aEndCheckedHandlerInterface ? $handler : null;
    }
}
