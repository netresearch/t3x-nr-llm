<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use DateTimeImmutable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js bar-chart data provider for "requests by provider (last N days)".
 *
 * Aggregates tx_nrllm_service_usage rows by service_provider. Unlike
 * UsageTrackerService::getUsageReport() this spans every service_type
 * (chat, vision, translation, ...) because the widget gives an overall
 * provider-traffic view, not a per-service breakdown.
 */
final readonly class RequestsByProviderDataProvider implements ChartDataProviderInterface
{
    private const TABLE = 'tx_nrllm_service_usage';
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private ConnectionPool $connectionPool,
        private int $days = self::DEFAULT_DAYS,
    ) {}

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, backgroundColor?: list<string>}>}
     */
    public function getChartData(): array
    {
        $since = (new DateTimeImmutable())->modify(sprintf('-%d days', max(1, $this->days)));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('service_provider')
            ->addSelectLiteral('SUM(request_count) as total_requests')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('request_date', $queryBuilder->createNamedParameter($since->getTimestamp())),
            )
            ->groupBy('service_provider')
            ->orderBy('total_requests', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return self::shapeChartData($rows);
    }

    /**
     * Shape the SQL rows into chart.js bar-chart format.
     *
     * Extracted for unit-testability — the ConnectionPool-driven query
     * path is covered by functional tests.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, backgroundColor?: list<string>}>}
     */
    public static function shapeChartData(array $rows): array
    {
        $labels = [];
        $data = [];
        foreach ($rows as $row) {
            $provider = is_string($row['service_provider'] ?? null) ? $row['service_provider'] : '';
            if ($provider === '') {
                continue;
            }
            $labels[] = $provider;
            /** @var mixed $rawCount */
            $rawCount = $row['total_requests'] ?? 0;
            $data[] = is_numeric($rawCount) ? (int)$rawCount : 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Requests',
                    'data' => $data,
                    'backgroundColor' => array_fill(0, count($data), '#2F99A4'),
                ],
            ],
        ];
    }
}
