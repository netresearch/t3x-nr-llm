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
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

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
    ) {}

    public function enqueue(AgentRunRequest $request): string
    {
        if ($this->messageBus === null) {
            throw RunEnqueueFailedException::forRun('');
        }

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
        $handle = $this->persister->enqueue($request->configuration, $request->actor->backendUserUid, $requestJson);
        if ($handle === null) {
            throw RunEnqueueFailedException::forRun('');
        }

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
        if ($run === null || $run->statusEnum() !== AgentRunStatus::QUEUED || $run->queuedRequest === null) {
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
        $handle  = $claimed !== null ? $this->persister->resumeHandle($claimed) : null;
        if ($handle === null) {
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
            $request = $this->requestCodec->rehydrate($run);
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

        return $this->executor->executeRequest($request, $handle, $onStep, $workerIdentity, $recover);
    }

    /**
     * Which worker claimed a queued run — host + pid, for lease diagnostics.
     */
    private function workerIdentity(): string
    {
        $host = gethostname();

        return substr(($host !== false ? $host : 'unknown') . ':' . getmypid(), 0, 64);
    }
}
