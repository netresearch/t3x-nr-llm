<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Fixture;

use DateTimeInterface;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;

/**
 * In-memory usage tracker for unit tests.
 *
 * Captures each trackUsage() call so assertions verify the arguments a
 * collaborator produced, never a mock return. The read methods return empty
 * shapes — tests that need them should use a dedicated double.
 */
final class RecordingUsageTracker implements UsageTrackerServiceInterface
{
    /**
     * @var list<array{
     *     serviceType: string,
     *     provider: string,
     *     metrics: array<string, mixed>,
     *     configurationUid: ?int,
     *     modelUid: int,
     *     modelId: string,
     *     taskUid: int,
     *     beUserUid: ?int,
     *     countsAsRequest: bool,
     * }>
     */
    public array $calls = [];

    public function trackUsage(
        string $serviceType,
        string $provider,
        array $metrics = [],
        ?int $configurationUid = null,
        int $modelUid = 0,
        string $modelId = '',
        int $taskUid = 0,
        ?int $beUserUid = null,
        bool $countsAsRequest = true,
    ): void {
        $this->calls[] = [
            'serviceType'      => $serviceType,
            'provider'         => $provider,
            'metrics'          => $metrics,
            'configurationUid' => $configurationUid,
            'modelUid'         => $modelUid,
            'modelId'          => $modelId,
            'taskUid'          => $taskUid,
            'beUserUid'        => $beUserUid,
            'countsAsRequest'  => $countsAsRequest,
        ];
    }

    public function getUsageReport(string $serviceType, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [];
    }

    public function getUserUsage(int $beUserUid, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [];
    }

    public function getTodayUsage(string $serviceType, string $provider): ?array
    {
        return null;
    }

    public function getCurrentMonthCost(): float
    {
        return 0.0;
    }
}
