<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use RuntimeException;

/**
 * In-memory {@see AgentRunRepositoryInterface} for unit-testing the persister:
 * captures every call for assertion and can be told to throw on any method to
 * exercise the persister's fail-soft behaviour.
 */
final class RecordingAgentRunRepository implements AgentRunRepositoryInterface
{
    public int $nextUid = 1;

    public bool $throwOnStart = false;

    public bool $throwOnRecord = false;

    public bool $throwOnFinish = false;

    /** @var list<array{uuid: string, configurationUid: int, configurationIdentifier: string, beUser: int}> */
    public array $startedRuns = [];

    /** @var list<array{runUid: int, sequence: int, kind: string, round: int, durationMs: float, payloadJson: string}> */
    public array $events = [];

    /** @var array{runUid: int, status: string, iterations: int, truncated: bool, promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: float, errorClass: string, terminationReason: string}|null */
    public ?array $finished = null;

    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser): int
    {
        if ($this->throwOnStart) {
            throw new RuntimeException('startRun failed', 5383517209);
        }
        $this->startedRuns[] = [
            'uuid'                     => $uuid,
            'configurationUid'         => $configurationUid,
            'configurationIdentifier'  => $configurationIdentifier,
            'beUser'                   => $beUser,
        ];

        return $this->nextUid++;
    }

    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void
    {
        if ($this->throwOnRecord) {
            throw new RuntimeException('recordEvent failed', 9973913396);
        }
        $this->events[] = [
            'runUid'      => $runUid,
            'sequence'    => $sequence,
            'kind'        => $kind,
            'round'       => $round,
            'durationMs'  => $durationMs,
            'payloadJson' => $payloadJson,
        ];
    }

    public function finishRun(
        int $runUid,
        string $status,
        int $iterations,
        bool $truncated,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        float $estimatedCost,
        string $errorClass,
        string $terminationReason = '',
    ): bool {
        if ($this->throwOnFinish) {
            throw new RuntimeException('finishRun failed', 7543565687);
        }
        if ($this->refuseFinish) {
            return false;
        }
        $this->finished = [
            'runUid'           => $runUid,
            'status'           => $status,
            'iterations'       => $iterations,
            'truncated'        => $truncated,
            'promptTokens'     => $promptTokens,
            'completionTokens' => $completionTokens,
            'totalTokens'      => $totalTokens,
            'estimatedCost'    => $estimatedCost,
            'errorClass'         => $errorClass,
            'terminationReason'  => $terminationReason,
        ];

        return true;
    }

    /** Simulates a run that is already terminal, so the guarded update matches no row. */
    public bool $refuseFinish = false;

    /** @var array{runUid: int, stateJson: string}|null */
    public ?array $suspended = null;

    public bool $throwOnSuspend = false;

    /** Simulates a run no longer RUNNING (cancelled/settled), so the guarded suspend matches no row. */
    public bool $refuseSuspend = false;

    public function suspendRun(int $runUid, string $stateJson): bool
    {
        if ($this->throwOnSuspend) {
            throw new RuntimeException('suspendRun failed', 1784600401);
        }
        if ($this->refuseSuspend) {
            return false;
        }
        $this->suspended = ['runUid' => $runUid, 'stateJson' => $stateJson];

        return true;
    }

    public bool $throwOnClaim = false;

    /** Number of resume claims that were granted; false is returned after the first. */
    public int $claimsGranted = 0;

    public function claimForResume(int $runUid): bool
    {
        if ($this->throwOnClaim) {
            throw new RuntimeException('claimForResume failed', 1784600400);
        }
        // First claim wins; a second concurrent claim on the same run loses.
        if ($this->claimsGranted > 0) {
            return false;
        }
        ++$this->claimsGranted;

        return true;
    }

    /** Run returned by findByUuid() (default null = unknown). */
    public ?AgentRun $findResult = null;

    public function findByUuid(string $uuid): ?AgentRun
    {
        return $this->findResult;
    }

    /** @var list<AgentRunEvent> */
    public array $eventsToReturn = [];

    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        // Mirrors the SQL predicate: only events past the requested position.
        return array_values(array_filter(
            $this->eventsToReturn,
            static fn(AgentRunEvent $event): bool => $event->sequence > $afterSequence,
        ));
    }

    public bool $throwOnMaxSequence = false;

    /** Highest stored event sequence reported to resumeHandle() (-1 = none). */
    public int $maxSequence = -1;

    /**
     * When non-empty, each maxEventSequence() call shifts the next value —
     * models a stream that grows between two position resolutions (the
     * probe-before-claim vs. resolve-after-claim ordering, ADR-101).
     *
     * @var list<int>
     */
    public array $maxSequenceReturns = [];

    public function maxEventSequence(int $runUid): int
    {
        if ($this->throwOnMaxSequence) {
            throw new RuntimeException('maxEventSequence failed', 1784600403);
        }
        if ($this->maxSequenceReturns !== []) {
            return array_shift($this->maxSequenceReturns);
        }

        return $this->maxSequence;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return 0;
    }

    public function purgeUnfinishedOlderThan(int $timestamp): int
    {
        return 0;
    }
}
