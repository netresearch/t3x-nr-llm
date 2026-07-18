<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
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

    /** @var array{runUid: int, status: string, iterations: int, truncated: bool, promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: float, errorClass: string}|null */
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
    ): void {
        if ($this->throwOnFinish) {
            throw new RuntimeException('finishRun failed', 7543565687);
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
            'errorClass'       => $errorClass,
        ];
    }

    /** @var array{runUid: int, stateJson: string}|null */
    public ?array $suspended = null;

    public function suspendRun(int $runUid, string $stateJson): void
    {
        $this->suspended = ['runUid' => $runUid, 'stateJson' => $stateJson];
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

    public function findEvents(int $runUid): array
    {
        return [];
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return 0;
    }
}
