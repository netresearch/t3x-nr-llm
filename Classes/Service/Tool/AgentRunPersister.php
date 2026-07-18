<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
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
 * Note (ADR-081): a run that exhausts its iteration cap OR is denied by the
 * budget guard both surface as COMPLETED with `truncated = true`, because
 * {@see ToolLoopService} swallows the budget denial internally and returns a
 * normal result. Distinguishing "cap hit" from "budget denied" as a FAILED run
 * is deferred to the human-in-the-loop epic, which reshapes the loop's exit
 * paths.
 */
final readonly class AgentRunPersister
{
    public function __construct(
        private AgentRunRepositoryInterface $repository,
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
            $payload = json_encode($step->toArray(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
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
     * `truncated` flag on the result carries whether the loop hit its cap.
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
        );
    }

    /**
     * Settle a run that ended in an uncaught throwable (a provider failure that
     * exhausted every fallback, or an unexpected error). The exception FQCN is
     * stored; the message is never persisted (it can carry payload fragments).
     */
    public function settleFailed(AgentRunHandle $handle, Throwable $error): void
    {
        $this->finish($handle, AgentRunStatus::FAILED, 0, false, 0, 0, 0, 0.0, $error::class);
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
    ): void {
        try {
            $this->repository->finishRun(
                $handle->runUid,
                $status->value,
                $iterations,
                $truncated,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $estimatedCost,
                $errorClass,
            );
        } catch (Throwable $exception) {
            $this->logger?->warning('AgentRun could not be settled', ['exception' => $exception]);
        }
    }
}
