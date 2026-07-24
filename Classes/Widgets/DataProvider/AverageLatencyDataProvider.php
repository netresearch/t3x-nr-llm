<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Data provider for the "average latency" NumberWithIcon widget.
 *
 * Returns the mean end-to-end provider pipeline latency in milliseconds over
 * the last N days, from tx_nrllm_telemetry. Paired with the success-rate tile:
 * a percent and a millisecond figure are heterogeneous, so they are two tiles,
 * never one misleading shared axis.
 */
final readonly class AverageLatencyDataProvider implements NumberWithIconDataProviderInterface
{
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private TelemetryRepositoryInterface $repository,
        private int $days = self::DEFAULT_DAYS,
    ) {}

    public function getNumber(): int
    {
        $since = (new DateTimeImmutable())
            ->modify(sprintf('-%d days', max(1, $this->days)))
            ->getTimestamp();

        return $this->repository->averageLatencyMs($since);
    }
}
