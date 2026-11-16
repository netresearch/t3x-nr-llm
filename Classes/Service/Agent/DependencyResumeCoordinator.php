<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Service\Tool\DependencyResumingToolLoopInterface;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Psr\Log\LoggerInterface;

/**
 * Picks a run that waited for an external dependency back up (ADR-281, spec 051
 * A2A-R4, A2A-R6, A2A-R11).
 *
 * The caller (the A2A poller) decides WHAT the pending call's result is; this
 * class decides that it is delivered once, to the right call, as the right
 * actor:
 *
 * - The run is claimed with the atomic claim and lease of an approval resume.
 *   Of two workers that saw the same remote result, one wins; the other gets
 *   null and does nothing.
 * - The state is read again after the claim, and its checkpoint must still
 *   name the operation the caller looked at; otherwise the run is failed as
 *   corrupt, never continued with a result meant for another call.
 * - The run continues as its owner, in the workspace it recorded, with its
 *   counters: the tool loop answers exactly the pending call and the
 *   delegation tool is not executed again.
 * - The segment holds the lease, so a worker that loses it writes nothing
 *   further (ADR-141).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class DependencyResumeCoordinator
{
    /** The longest answer to a remote question (spec 060 RQ1). */
    public const MAX_ANSWER = 4000;

    public function __construct(
        private AgentRunPersister $persister,
        private LlmConfigurationRepository $configurations,
        private DependencyResumingToolLoopInterface $toolLoop,
        private AgentRunExecutor $executor,
        private ?AgentRoundLimitsInterface $roundLimits = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * The runs waiting for a dependency, with the checkpoint each one stored.
     * A run whose checkpoint is missing or incomplete comes back with null:
     * the caller fails it rather than guessing what it waits for.
     *
     * @return list<array{AgentRun, DependencyCheckpoint|null}>
     */
    public function waitingRuns(int $limit = 100, int $afterUid = 0): array
    {
        $waiting = [];
        foreach ($this->persister->findWaitingForDependencyRuns($limit, null, $afterUid) ?? [] as $run) {
            $waiting[] = [$run, $this->decode($run)[1] ?? null];
        }

        return $waiting;
    }

    /**
     * Resolve the dependency of a waiting run with $result as the pending
     * call's tool result. Null when another worker or a cancel won the run.
     *
     * $onClaimed runs once, after the claim and before the run continues, in
     * the process that won it: the place for a side effect that must happen
     * exactly once per resolution, such as the CancelTask of a timeout.
     *
     * @param (Closure(): void)|null $onClaimed
     */
    public function resume(AgentRun $run, DependencyCheckpoint $expected, ToolResult $result, ?Closure $onClaimed = null): ?AgentRunResult
    {
        // Literal lease-identity factory; it only builds a bounded process-identity string.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $leaseOwner = ExecutionIdentity::resume();
        if (!$this->persister->claimResumeFromDependency($run, $leaseOwner, time() + AgentRuntime::LEASE_SECONDS)) {
            return null;
        }

        return $this->resumeClaimed($run, $expected, $result, $onClaimed, $leaseOwner);
    }

    /**
     * A run that waits for its initiator's answer to a remote question
     * (spec 060) and whose dependency's deadline passed: resumed like
     * {@see self::resume()} with $result, from its input pause.
     *
     * @param (Closure(): void)|null $onClaimed
     */
    public function expireQuestion(AgentRun $run, DependencyCheckpoint $expected, ToolResult $result, ?Closure $onClaimed = null): ?AgentRunResult
    {
        // Literal lease-identity factory; it only builds a bounded process-identity string.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $leaseOwner = ExecutionIdentity::resume();
        if (!$this->persister->claimResumeFromInput($run, $leaseOwner, time() + AgentRuntime::LEASE_SECONDS)) {
            return null;
        }

        // The deadline was read for this pause: a question answered and
        // asked again since then keeps waiting, unchanged.
        $claimed = $this->persister->findRun($run->uuid);
        if ($claimed instanceof AgentRun && !$this->samePause($run, $claimed)) {
            $handle   = $this->persister->resumeHandle($claimed);
            $state    = $this->decode($claimed)[0] ?? null;
            $question = self::checkpointOf($claimed);
            if (!$handle instanceof AgentRunHandle || !$state instanceof SuspendedRunState || !$question instanceof DependencyCheckpoint
                || !$this->persister->suspendForRemoteQuestion($handle, $state, $question)) {
                $this->fail($handle ?? new AgentRunHandle($run->uid, $run->uuid), 'The replaced question could not be put back', $leaseOwner);
            }

            return null;
        }

        return $this->resumeClaimed($run, $expected, $result, $onClaimed, $leaseOwner);
    }

    /**
     * A run that waits for its initiator's answer to a remote question
     * (spec 060), with its checkpoint; null for any other run.
     *
     * @return array{AgentRun, DependencyCheckpoint}|null
     */
    public function questionRun(string $runUuid): ?array
    {
        $run        = $this->persister->findRun($runUuid);
        $checkpoint = $run instanceof AgentRun && $run->statusEnum() === AgentRunStatus::WAITING_FOR_INPUT ? self::checkpointOf($run) : null;

        return $run instanceof AgentRun && $checkpoint instanceof DependencyCheckpoint ? [$run, $checkpoint] : null;
    }

    /**
     * Turn the run's wait for the remote operation into a wait for its
     * initiator's answer (spec 060 RQ1): claimed like a resume, the
     * checkpoint read again and checked, then suspended for input with the
     * pending call as the input target, the question as the title of its one
     * field, and the checkpoint kept beside it. No model call and no tool
     * runs. $endpoint binds the answer to where the question came from. False
     * when another worker or a cancel won the run, or it was failed because
     * it could not be paused.
     *
     * @param (Closure(): void)|null $onClaimed
     */
    public function askInitiator(AgentRun $run, DependencyCheckpoint $expected, string $question, ?Closure $onClaimed = null, string $endpoint = ''): bool
    {
        // Literal lease-identity factory; it only builds a bounded process-identity string.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $leaseOwner = ExecutionIdentity::resume();
        if (!$this->persister->claimResumeFromDependency($run, $leaseOwner, time() + AgentRuntime::LEASE_SECONDS)) {
            return false;
        }

        $claimed = $this->persister->findRun($run->uuid);
        $handle  = $claimed instanceof AgentRun ? $this->persister->resumeHandle($claimed) : null;
        if (!$claimed instanceof AgentRun || !$handle instanceof AgentRunHandle) {
            if (!$this->persister->releaseDependencyClaim($run, $leaseOwner)) {
                $this->fail(new AgentRunHandle($run->uid, $run->uuid), 'The event-stream position could not be determined after the dependency claim', $leaseOwner);
            }

            return false;
        }

        // The question was read for this wait: a wait that was answered and
        // began again since then goes back, unasked.
        if (!$this->samePause($run, $claimed)) {
            if (!$this->persister->releaseDependencyClaim($run, $leaseOwner)) {
                $this->fail($handle, 'The dependency claim could not be given back', $leaseOwner);
            }

            return false;
        }

        [$state, $checkpoint] = $this->decode($claimed) ?? [null, null];
        if (!$state instanceof SuspendedRunState || !$checkpoint instanceof DependencyCheckpoint
            || $checkpoint->journalUid !== $expected->journalUid || $checkpoint->toolCallId !== $expected->toolCallId
            || !$this->owner($state, $claimed) instanceof AiActorContext) {
            $this->fail($handle, 'The dependency checkpoint could not be read after the claim', $leaseOwner);

            return false;
        }

        if ($onClaimed instanceof Closure) {
            $onClaimed();
        }

        $paused = SuspendedRunState::fromArray([
            'inputToolName' => $checkpoint->toolName,
            'inputCallId'   => $checkpoint->toolCallId,
            // A new marker for every question, even one asked twice.
            'inputSchema' => self::answerSchema($question, bin2hex(random_bytes(16))),
        ] + $state->toArray());
        if ($this->persister->suspendForRemoteQuestion($handle, $paused, $checkpoint->withEndpoint($endpoint))) {
            return true;
        }

        $this->fail($handle, 'The run could not wait for the answer to the remote question', $leaseOwner);

        return false;
    }

    /**
     * The input a remote question asks of the initiator: one text that is
     * not only whitespace, the question as its title, and a marker of which
     * question it is.
     *
     * @return array<string, mixed>
     */
    public static function answerSchema(string $question, string $marker = ''): array
    {
        return [
            'type'       => 'object',
            'required'   => ['answer'],
            'properties' => [
                'answer' => ['type' => 'string', 'title' => $question, 'minLength' => 1, 'maxLength' => self::MAX_ANSWER, 'pattern' => '\\S'],
            ],
            // Which question this is: the turn digest covers the schema, so
            // an answer given for an earlier question with the same wording
            // does not match a later one.
            'x-nr-llm-question' => $marker,
        ];
    }

    /**
     * Whether the claimed row still holds the sealed pause the caller read:
     * every write seals the state anew, so any pause written since differs.
     */
    private function samePause(AgentRun $read, AgentRun $claimed): bool
    {
        return hash_equals(hash('sha256', (string) $read->suspendedState), hash('sha256', (string) $claimed->suspendedState));
    }

    /**
     * The checkpoint a run's sealed state carries, whatever it waits for.
     */
    public static function checkpointOf(AgentRun $run): ?DependencyCheckpoint
    {
        $decoded = json_decode($run->suspendedState ?? '', true);

        return is_array($decoded) ? DependencyCheckpoint::fromStoredState($decoded) : null;
    }

    /**
     * @param (Closure(): void)|null $onClaimed
     */
    private function resumeClaimed(AgentRun $run, DependencyCheckpoint $expected, ToolResult $result, ?Closure $onClaimed, string $leaseOwner): ?AgentRunResult
    {
        $claimed = $this->persister->findRun($run->uuid);
        $handle  = $claimed instanceof AgentRun ? $this->persister->resumeHandle($claimed) : null;
        if (!$claimed instanceof AgentRun || !$handle instanceof AgentRunHandle) {
            // A read that failed for now says nothing about the checkpoint:
            // the claim goes back and a later tick tries again. Only when it
            // cannot be given back does the run fail.
            if ($this->persister->releaseDependencyClaim($run, $leaseOwner)) {
                return null;
            }

            return $this->fail(new AgentRunHandle($run->uid, $run->uuid), 'The event-stream position could not be determined after the dependency claim', $leaseOwner);
        }

        [$state, $checkpoint] = $this->decode($claimed) ?? [null, null];
        if (!$state instanceof SuspendedRunState || !$checkpoint instanceof DependencyCheckpoint
            || $checkpoint->journalUid !== $expected->journalUid || $checkpoint->toolCallId !== $expected->toolCallId) {
            return $this->fail($handle, 'The dependency checkpoint could not be read after the claim', $leaseOwner);
        }

        $owner = $this->owner($state, $claimed);
        if (!$owner instanceof AiActorContext) {
            return $this->fail($handle, 'The suspended run names no valid owner', $leaseOwner);
        }

        $configuration = $this->configurations->findByUid($claimed->configurationUid);
        if (!$configuration instanceof LlmConfiguration || !$configuration->isActive()) {
            return $this->fail($handle, 'The configuration of the waiting run is gone or inactive', $leaseOwner);
        }

        if ($onClaimed instanceof Closure) {
            $onClaimed();
        }

        $maxIterations = $state->maxIterations !== null
            ? ($this->roundLimits ?? new AgentRoundLimits())->roundsFor($state->maxIterations)
            : null;

        return $this->executor->executeResume(
            $handle,
            null,
            $owner,
            fn (ToolExecutionContext $context, RunTrace $trace): ToolLoopResult => $this->toolLoop->resumeWithDependency(
                $state,
                $checkpoint,
                $result,
                $configuration,
                $context,
                $maxIterations,
                $trace,
            ),
            $leaseOwner,
        );
    }

    /**
     * Fail a waiting run whose checkpoint cannot be read: claimed first, so a
     * run another worker is resuming is left alone. True when it was failed.
     */
    public function failUnreadable(AgentRun $run, string $reason): bool
    {
        // Literal lease-identity factory; it only builds a bounded process-identity string.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $leaseOwner = ExecutionIdentity::resume();
        if (!$this->persister->claimResumeFromDependency($run, $leaseOwner, time() + AgentRuntime::LEASE_SECONDS)) {
            return false;
        }

        $this->fail(new AgentRunHandle($run->uid, $run->uuid), $reason, $leaseOwner);

        return true;
    }

    /**
     * @return array{SuspendedRunState, DependencyCheckpoint|null}|null
     */
    private function decode(AgentRun $run): ?array
    {
        if ($run->statusEnum() !== AgentRunStatus::WAITING_FOR_DEPENDENCY && $run->statusEnum() !== AgentRunStatus::RUNNING) {
            return null;
        }

        $decoded = json_decode($run->suspendedState ?? '', true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return [SuspendedRunState::fromArray($decoded), DependencyCheckpoint::fromStoredState($decoded)];
    }

    /**
     * The run's own actor from its sealed state, bound to this run (ADR-083):
     * never the worker's identity.
     */
    private function owner(SuspendedRunState $state, AgentRun $run): ?AiActorContext
    {
        $actor = $state->initiatingActor;
        if (!$actor instanceof AiActorContext || !$actor->isAuthenticated() || $actor->backendUserUid !== $run->beUser || !hash_equals($run->uuid, $state->initiatingRunUuid)) {
            return null;
        }

        return $actor;
    }

    private function fail(AgentRunHandle $handle, string $reason, string $leaseOwner): AgentRunResult
    {
        $error = new RuntimeException($reason, 1799200201);
        $this->persister->settleFailed($handle, $error, $leaseOwner);
        $this->logger?->error('A run waiting for a dependency could not be resumed; it was failed', ['run' => $handle->uuid, 'reason' => $reason]);

        return new AgentRunResult(outcome: AgentRunOutcome::FAILED, runUuid: $handle->uuid, steps: [], error: $error);
    }
}
