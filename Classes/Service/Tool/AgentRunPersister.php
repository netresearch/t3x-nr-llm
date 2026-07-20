<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Persists an agent run and its event stream (ADR-081).
 *
 * A thin, fail-soft orchestrator over {@see AgentRunRepositoryInterface}. It is
 * driven from the same {@see RunTrace} `onRecord` hook the playground already
 * uses to stream steps, so persistence is purely additive — the tool loop is
 * untouched and unaware of it.
 *
 * Every method is fail-soft: a persistence error is logged and swallowed so a
 * database hiccup can never break an otherwise-successful run. {@see self::begin()}
 * returns null on failure, which the caller treats as "do not record" — exactly
 * as a null {@see RunTrace} callback would.
 *
 * What a step actually stores is governed by the central privacy level via
 * {@see RunStepPrivacyFilter}: metadata-only by default, so persistence does not
 * quietly turn the event stream into a prompt archive (ADR-064).
 *
 * A run that exhausts its iteration cap and one the budget guard denied are
 * both COMPLETED with `truncated = true` — the loop swallows the denial and
 * returns a normal result — but they are no longer indistinguishable: the
 * result carries an {@see AgentRunTerminationReason} that is stored alongside
 * the status (ADR-092).
 */
final readonly class AgentRunPersister
{
    public function __construct(
        private AgentRunRepositoryInterface $repository,
        private RunStepPrivacyFilter $privacyFilter,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Open a new run in the RUNNING state. Returns a handle to thread back into
     * {@see self::recordStep()} and the settle methods, or null when the run
     * could not be started (the caller then records nothing).
     */
    public function begin(?LlmConfiguration $configuration, int $beUser): ?AgentRunHandle
    {
        try {
            $uuid   = Uuid::v4()->toRfc4122();
            $runUid = $this->repository->startRun(
                $uuid,
                $configuration?->getUid() ?? 0,
                $configuration?->getIdentifier() ?? '',
                $beUser,
            );

            return new AgentRunHandle($runUid, $uuid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be started; the run will not be persisted', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Persist one recorded step as the next event in the run's stream.
     */
    public function recordStep(AgentRunHandle $handle, RunStep $step): void
    {
        try {
            // The persisted copy follows the central privacy level; the live
            // playground stream renders the unfiltered step from memory.
            $payload = json_encode(
                $this->privacyFilter->filter($step->toArray()),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
            $this->repository->recordEvent(
                $handle->runUid,
                $handle->sequence,
                $step->kind,
                $step->round,
                $step->durationMs,
                $payload,
            );
            ++$handle->sequence;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun step could not be persisted', ['exception' => $exception]);
        }
    }

    /**
     * Settle a run that finished normally. The status is always COMPLETED; the
     * result carries whether the loop was cut short and — since ADR-092 — why:
     * an iteration cap and an exhausted budget both truncate and are otherwise
     * indistinguishable in the stored row.
     */
    public function settleCompleted(AgentRunHandle $handle, ToolLoopResult $result): void
    {
        $this->finish(
            $handle,
            AgentRunStatus::COMPLETED,
            $result->iterations,
            $result->truncated,
            $result->usage->promptTokens,
            $result->usage->completionTokens,
            $result->usage->totalTokens,
            $result->usage->estimatedCost ?? 0.0,
            '',
            $result->terminationReason,
        );
    }

    /**
     * Settle a run that ended in an uncaught throwable (a provider failure that
     * exhausted every fallback, or an unexpected error). The exception FQCN is
     * stored; the message is never persisted (it can carry payload fragments).
     */
    public function settleFailed(AgentRunHandle $handle, Throwable $error): void
    {
        $this->finish($handle, AgentRunStatus::FAILED, 0, false, 0, 0, 0, 0.0, $error::class, AgentRunTerminationReason::PROVIDER_FAILED);
    }

    /**
     * Settle a run a guardrail stopped (ADR-085 ff.): FAILED, but with the
     * policy reason rather than a provider failure, so a denial is not read as
     * an outage — and an approval that was required but never obtained is not
     * read as a denial.
     */
    public function settlePolicyStopped(AgentRunHandle $handle, Throwable $error, AgentRunTerminationReason $reason): void
    {
        $this->finish($handle, AgentRunStatus::FAILED, 0, false, 0, 0, 0, 0.0, $error::class, $reason);
    }

    /**
     * Settle a run an operator cancelled. Distinct from a failure: nothing went
     * wrong, somebody stopped it.
     */
    public function settleCancelled(AgentRunHandle $handle): void
    {
        $this->finish($handle, AgentRunStatus::CANCELLED, 0, false, 0, 0, 0, 0.0, '', AgentRunTerminationReason::CANCELLED);
    }

    /**
     * Cancel a run by uuid — the operator-facing entry point behind
     * `nrllm:agent:cancel`.
     *
     * Cancels a run that is still queued, running or waiting for a decision:
     * the row moves to CANCELLED with its resumable state dropped, so a
     * stranded run stops occupying an approval queue. Returns false when the
     * run is unknown or already terminal; the guarded transition in the
     * repository decides, so two concurrent cancels cannot both win.
     */
    public function cancel(string $uuid): bool
    {
        $run = $this->findRun($uuid);
        if ($run === null) {
            return false;
        }

        try {
            return $this->repository->finishRun(
                $run->uid,
                AgentRunStatus::CANCELLED->value,
                $run->iterations,
                $run->truncated,
                $run->totalPromptTokens,
                $run->totalCompletionTokens,
                $run->totalTokens,
                $run->estimatedCost,
                '',
                AgentRunTerminationReason::CANCELLED->value,
            );
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be cancelled', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Suspend a run for human approval (ADR-084): persist the transcript and
     * pending tool calls and move the run to WAITING_FOR_APPROVAL.
     *
     * Returns false when the state could not be stored. Unlike the other
     * methods here, a caller must NOT ignore that: an approval-gated tool is by
     * definition side-effecting, and telling the client "awaiting approval"
     * when nothing was persisted promises a resume that can never happen. The
     * caller settles the run as failed instead (ADR-092).
     */
    public function suspend(AgentRunHandle $handle, SuspendedRunState $state): bool
    {
        try {
            $stateJson = json_encode($state->toArray(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $this->repository->suspendRun($handle->runUid, $stateJson);

            return true;
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be suspended', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Load a persisted run by uuid. Exposed on the persister (a service) so a
     * controller can reach a run without depending on the repository directly
     * (the layered-architecture rule). Null when unknown or unavailable.
     */
    public function findRun(string $uuid): ?AgentRun
    {
        try {
            return $this->repository->findByUuid($uuid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be loaded', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Atomically claim a suspended run before resuming it (ADR-084). Fail-closed:
     * returns false — refusing the resume — if the claim is lost to a concurrent
     * approval or the store errors, so the gated tool is never double-executed.
     */
    public function claimResume(AgentRun $run): bool
    {
        try {
            return $this->repository->claimForResume($run->uid);
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be claimed for resume', ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Rebuild a live handle for an existing (suspended) run so a resume continues
     * its event stream at the right sequence.
     */
    public function resumeHandle(AgentRun $run): AgentRunHandle
    {
        $handle = new AgentRunHandle($run->uid, $run->uuid);
        try {
            $handle->sequence = count($this->repository->findEvents($run->uid));
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun events could not be counted for resume; sequence restarts at 0', ['exception' => $exception]);
        }

        return $handle;
    }

    private function finish(
        AgentRunHandle $handle,
        AgentRunStatus $status,
        int $iterations,
        bool $truncated,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        float $estimatedCost,
        string $errorClass,
        AgentRunTerminationReason $reason,
    ): void {
        try {
            $transitioned = $this->repository->finishRun(
                $handle->runUid,
                $status->value,
                $iterations,
                $truncated,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $estimatedCost,
                $errorClass,
                $reason->value,
            );

            if (!$transitioned) {
                // The run was already terminal: a duplicate or late settle. The
                // guard kept the first outcome, which is the correct one.
                $this->logger?->notice('AgentRun was already settled; the later outcome was discarded', [
                    'run'    => $handle->uuid,
                    'status' => $status->value,
                    'reason' => $reason->value,
                ]);
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be settled', ['exception' => $exception]);
        }
    }
}
