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
use Netresearch\NrLlm\Domain\Enum\FieldFillOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillWriterInterface;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Agent\Queue\QueueRouting;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Vision\VisionActionBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Puts a run on the queue and picks it back up in a worker (ADR-102).
 *
 * The two halves are one protocol and only make sense together: what
 * {@see self::enqueue()} stores — a serialised request on a fail-closed row,
 * dispatched only after the row exists — is what {@see self::runQueued()} has
 * to be able to claim, position and rehydrate. Both are fail-closed in the same
 * direction: a queued run that cannot be stored, dispatched, positioned or
 * rehydrated is settled rather than left as an orphan a worker would later find
 * in an impossible state.
 *
 * Claiming is the ordering guarantee: exactly one worker wins the guarded
 * QUEUED -> RUNNING transition, and the event-stream position is resolved from
 * a FRESH row afterwards so a requeued run continues its stream instead of
 * reusing sequence numbers.
 *
 * What happens once a claimed run executes is not here — the executor drives
 * it, and the failure recovery decides retry-versus-dead-letter.
 */
final readonly class QueuedRunCoordinator
{
    public function __construct(
        private AgentRunPersister $persister,
        private AgentRunRequestCodec $requestCodec,
        private AgentRunExecutor $executor,
        private QueuedRunFailureRecovery $failureRecovery,
        private ?LoggerInterface $logger = null,
        private ?MessageBusInterface $messageBus = null,
        private ?PrivacyPolicyInterface $privacyPolicy = null,
        private ?ActingBackendUserResolverInterface $actingUsers = null,
        private ?VisionActionBuilder $vision = null,
        private ?FieldFillWriterInterface $fieldFill = null,
    ) {}

    public function enqueue(AgentRunRequest $request): string
    {
        if (!$this->messageBus instanceof MessageBusInterface) {
            throw RunEnqueueFailedException::forRun('');
        }

        $handle = $this->store($request);

        try {
            // The wake-up call. On the default SyncTransport the run executes
            // right here; routed to the doctrine transport it executes in
            // messenger:consume. The claim makes a duplicate dispatch harmless.
            $this->messageBus->dispatch(new AgentRunQueuedMessage($handle->uuid));
        } catch (Throwable $e) {
            // On an async transport a failed dispatch would strand the row
            // QUEUED forever (no message will ever arrive) — settle it failed
            // so no orphan is left behind. On the sync transport a handler
            // failure never reaches here (runQueued() settles outcomes itself
            // and does not throw), so this is genuinely the transport failing.
            $this->persister->settleFailed($handle, $e);
            $this->logger?->error('Queued agent run could not be dispatched; the run was failed', ['run' => $handle->uuid, 'exception' => $e]);

            throw RunEnqueueFailedException::forRun($handle->uuid);
        }

        return $handle->uuid;
    }

    /**
     * Store a QUEUED run and leave it there (ADR-234).
     *
     * A consumer is woken only where the message leaves this process: on the
     * synchronous transport a dispatch would execute the run right here, which
     * is what the caller came to avoid. `nrllm:agent:work` sweeps the table, so
     * a dispatch that fails strands nothing and is only logged.
     */
    public function defer(AgentRunRequest $request): string
    {
        $handle = $this->store($request);

        if ($this->messageBus instanceof MessageBusInterface && QueueRouting::leavesTheProcess()) {
            try {
                $this->messageBus->dispatch(new AgentRunQueuedMessage($handle->uuid));
            } catch (Throwable $e) {
                $this->logger?->warning('A deferred agent run could not wake a consumer; the sweep will pick it up', ['run' => $handle->uuid, 'exception' => $e]);
            }
        }

        return $handle->uuid;
    }

    private function store(AgentRunRequest $request): AgentRunHandle
    {
        try {
            $requestJson = json_encode(
                $this->requestCodec->dehydrate($request),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $e) {
            // A non-encodable payload value (INF/NAN in a raw message array)
            // must surface as the interface's documented failure type, not a
            // bare JsonException. Nothing was stored yet — no cleanup needed.
            $this->logger?->error('Agent run request could not be serialised for the queue', ['exception' => $e]);

            throw RunEnqueueFailedException::forRun('');
        }

        // Fail-closed, unlike run(): a live run can proceed unpersisted, but a
        // queued run without a stored row simply does not exist.
        $handle = $this->persister->enqueue($request->configuration, $request->actor->backendUserUid, $requestJson, $request->origin);
        if (!$handle instanceof AgentRunHandle) {
            throw RunEnqueueFailedException::forRun('');
        }

        return $handle;
    }

    /**
     * Claim and execute one queued run, or report that it is not claimable.
     *
     * Returns null when the row is gone, no longer QUEUED, or was won by
     * another worker; an {@see AgentRunResult} in every other case, including
     * the settled-FAILED ones. It does not throw once the claim is won —
     * settling the row is how a failure is reported (ADR-104).
     *
     * @param (Closure(RunStep): void)|null $onStep
     */
    public function runQueued(string $runUuid, ?Closure $onStep = null): ?AgentRunResult
    {
        $run = $this->persister->findRun($runUuid);
        if (!$run instanceof AgentRun || $run->statusEnum() !== AgentRunStatus::QUEUED || $run->queuedRequest === null) {
            return null;
        }

        // A field no longer opted in costs no model call (ADR-237).
        $target = FieldFillTarget::fromOrigin($run->origin);
        if ($target instanceof FieldFillTarget && $this->fieldFill instanceof FieldFillWriterInterface && !$this->fieldFill->accepts($target)) {
            $this->persister->cancel($runUuid);

            return null;
        }

        // Exactly one worker wins the guarded QUEUED -> RUNNING transition; a
        // run cancelled while waiting is terminal and unclaimable (ADR-102).
        $workerIdentity = $this->workerIdentity();
        if (!$this->persister->claimQueued($run, $workerIdentity, time() + AgentRuntime::LEASE_SECONDS)) {
            return null;
        }

        // Resolve the event-stream position from a FRESH row AFTER the claim.
        // A first execution has no events (MAX = -1 -> sequence 0, identical to
        // before ADR-104); a REQUEUED run carries the prior attempt's events, so
        // starting at 0 would reuse sequences and corrupt the stream. Fail-closed
        // like AgentRuntime::approve(): a null position settles the run FAILED rather than
        // stranding it RUNNING (the claim is already won).
        $claimed = $this->persister->findRun($runUuid);
        $handle  = $claimed instanceof AgentRun ? $this->persister->resumeHandle($claimed) : null;
        if (!$handle instanceof AgentRunHandle) {
            $fallback = new AgentRunHandle($run->uid, $run->uuid);
            $this->persister->settleFailed(
                $fallback,
                new RuntimeException(sprintf('The event-stream position of queued run %s could not be determined after the claim', $runUuid), 1784900002),
            );
            $this->logger?->error('Queued agent run position could not be resolved after the claim; the run was failed', ['run' => $runUuid]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::FAILED,
                runUuid: $runUuid,
                steps: [],
                error: new RuntimeException('The event-stream position could not be determined after the queue claim', 1784900002),
            );
        }

        try {
            $request = $this->withImage($this->requestCodec->rehydrate($run));
        } catch (Throwable $e) {
            $this->persister->settleFailed($handle, $e);
            $this->logger?->error('Queued agent run could not be rehydrated; the run was failed', ['run' => $runUuid, 'exception' => $e]);

            return new AgentRunResult(
                outcome: AgentRunOutcome::FAILED,
                runUuid: $runUuid,
                steps: [],
                error: $e,
            );
        }

        // The heartbeat renews the lease under this worker's identity, and the
        // recover closure decides retry-vs-dead-letter for a failure (ADR-104).
        $recover = function (Throwable $e, array $steps) use ($handle, $runUuid, $workerIdentity): AgentRunResult {
            /** @var list<RunStep> $steps the ladder always passes the accumulated step list */
            return $this->failureRecovery->recover($handle, $runUuid, $workerIdentity, $e, $steps);
        };

        $result = $this->executor->executeRequest(
            $request,
            $handle,
            $onStep,
            $workerIdentity,
            $recover,
            initialInvocationHistory: new ToolInvocationHistory(
                complete: $run->requeueCount === 0 && $claimed instanceof AgentRun && $claimed->requeueCount === 0 && $handle->sequence === 0,
            ),
        );

        $this->keepAnswer($request, $handle, $result);
        $this->fillField($request, $handle, $result);

        return $result;
    }

    /**
     * Write the answer of a fill run into its field (ADR-237).
     *
     * The run is settled by now, and it stays completed: the model did its part.
     * What became of the answer is its own line in the history, and a failure
     * here must not reach the sweep, which has the next run to get to.
     */
    private function fillField(AgentRunRequest $request, AgentRunHandle $handle, AgentRunResult $result): void
    {
        $target = FieldFillTarget::fromOrigin($request->origin);
        $answer = $result->loopResult?->finalContent;
        if (!$target instanceof FieldFillTarget || !$this->fieldFill instanceof FieldFillWriterInterface || $answer === null) {
            return;
        }

        try {
            $outcome = $this->fieldFill->write($target, $answer, $request->actor);
        } catch (Throwable $exception) {
            $this->logger?->warning('The answer of a fill run could not be written', ['run' => $handle->uuid, 'exception' => $exception]);
            $outcome = FieldFillOutcome::NOT_WRITTEN;
        }

        $this->persister->recordOutcome($handle, $outcome->value);
    }

    /**
     * The image of a queued vision action, read now, as the acting user
     * (ADR-236). A folder of images waits in the queue as uids, and a file that
     * moved, or that the user lost access to, is decided here and not when the
     * batch was planned. The run fails rather than asking a model to describe
     * an image it does not have.
     *
     * A person who was disabled while the run waited ends it the same way, with
     * or without an image: nothing they queued goes to a model any more.
     */
    private function withImage(AgentRunRequest $request): AgentRunRequest
    {
        if ($request->imageFileUid < 1 && ($request->origin === '' || $request->actor->backendUserUid < 1)) {
            return $request;
        }

        $user = $this->actingUsers?->resolve($request->actor);
        if (!$user instanceof BackendUserAuthentication) {
            throw new RuntimeException('The user of this run is no longer active', 1798000021);
        }

        if ($request->imageFileUid < 1) {
            return $request;
        }

        $message = $this->vision?->maySend($request->imageFileUid, $user) === true ? $this->vision->imageMessage($request->imageFileUid, $user) : null;
        if ($message === null) {
            throw new RuntimeException(sprintf('The image of file %d cannot be sent', $request->imageFileUid), 1798000020);
        }

        return $request->withMessage($message);
    }

    /**
     * What a finished background run keeps of its answer (ADR-234).
     *
     * The answer is what such a run exists for and nobody is watching it arrive,
     * so unlike the event stream it does not follow the default `metadata`
     * level. `none` keeps nothing, `redacted` keeps the redacted text, and the
     * column is sealed and purged with the row either way. A run that went
     * through an approval ends in another process and keeps none: what it did
     * is the write.
     */
    private function keepAnswer(AgentRunRequest $request, AgentRunHandle $handle, AgentRunResult $result): void
    {
        $answer = $result->loopResult?->finalContent;
        if ($request->origin === '' || $result->outcome !== AgentRunOutcome::COMPLETED || $answer === null || $answer === '') {
            return;
        }

        // No policy, no answer: what may be kept is the policy's to say.
        $level = $this->privacyPolicy?->level();
        if (!$level instanceof PrivacyLevel || $level === PrivacyLevel::NONE) {
            return;
        }

        if ($level->requiresRedaction()) {
            $answer = $this->privacyPolicy?->filterContent($answer) ?? '';
        }

        $this->persister->recordAnswer($handle, $answer);
    }

    /**
     * Which worker claimed a queued run, for lease diagnostics. Shared with the
     * synchronous and resume segments (ADR-141) so every lease in the table says
     * which kind of entry point holds it.
     */
    private function workerIdentity(): string
    {
        // Literal lease-identity factory; it only builds a bounded process-identity string.
        // nosemgrep: php.lang.security.exec-use.exec-use
        return ExecutionIdentity::worker();
    }
}
