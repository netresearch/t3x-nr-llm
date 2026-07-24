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
 * Data provider for the "request success rate" NumberWithIcon widget.
 *
 * Returns the share (integer percent, 0-100) of provider pipeline runs that
 * succeeded over the last N days, from tx_nrllm_telemetry. The tile is an
 * at-a-glance health indicator, not an SLA report.
 */
final readonly class RequestSuccessRateDataProvider implements NumberWithIconDataProviderInterface
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

        return $this->repository->successRatePercent($since);
    }
}
