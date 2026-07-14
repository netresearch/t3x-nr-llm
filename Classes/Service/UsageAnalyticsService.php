<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Aggregate reporting over tx_nrllm_service_usage for the Analytics module.
 *
 * The table has no TCA / enable fields, so no QueryBuilder restrictions apply
 * (mirrors UsageTrackerService / UserBudgetUsageWindows). Cost is the recorded
 * point-in-time estimate; the UI labels it "estimated".
 */
final readonly class UsageAnalyticsService implements UsageAnalyticsServiceInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_service_usage';

    /**
     * Columns the grouped-by/breakdown queries may select and GROUP BY. All
     * current callers pass hardcoded literals; this whitelist is a defence in
     * depth so a future refactor cannot feed untrusted input into the SQL
     * identifier position (which QueryBuilder does not parameterise).
     */
    private const GROUPABLE_COLUMNS = [
        'service_provider',
        'model_id',
        'service_type',
        'model_uid',
        'configuration_uid',
        'task_uid',
    ];

    private const SUM_COST = 'SUM(estimated_cost) AS cost';

    private const SUM_REQUESTS = 'SUM(request_count) AS requests';

    private const SUM_TOKENS = 'SUM(tokens_used) AS tokens';

    public function __construct(
        private ConnectionPool $connectionPool,
        private UserBudgetRepository $userBudgetRepository,
        private BudgetUsageWindowsInterface $budgetUsageWindows,
    ) {}

    public function getKpiTotals(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->addSelectLiteral('COALESCE(SUM(estimated_cost), 0) AS cost')
            ->addSelectLiteral('COALESCE(SUM(request_count), 0) AS requests')
            ->addSelectLiteral('COALESCE(SUM(tokens_used), 0) AS tokens')
            ->addSelectLiteral('COUNT(DISTINCT service_provider) AS providers')
            ->addSelectLiteral("COUNT(DISTINCT NULLIF(model_id, '')) AS models")
            ->from(self::TABLE)
            ->where(
                $qb->expr()->gte('request_date', $qb->createNamedParameter($from->getTimestamp())),
                $qb->expr()->lte('request_date', $qb->createNamedParameter($to->getTimestamp())),
            )
            ->executeQuery()
            ->fetchAssociative();

        $row = is_array($row) ? $row : [];

        return [
            'cost'      => is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : 0.0,
            'requests'  => is_numeric($row['requests'] ?? null) ? (int)$row['requests'] : 0,
            'tokens'    => is_numeric($row['tokens'] ?? null) ? (int)$row['tokens'] : 0,
            'providers' => is_numeric($row['providers'] ?? null) ? (int)$row['providers'] : 0,
            'models'    => is_numeric($row['models'] ?? null) ? (int)$row['models'] : 0,
        ];
    }

    public function getDailyTrend(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('request_date')->from(self::TABLE);
        $this->selectUsageSums($qb, $from, $to);
        $rows = $qb
            ->groupBy('request_date')
            ->orderBy('request_date', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return self::fillDailyGaps(
            $rows,
            DateTimeImmutable::createFromInterface($from),
            DateTimeImmutable::createFromInterface($to),
        );
    }

    public function getBreakdownByProvider(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->breakdown('service_provider', $from, $to);
    }

    public function getBreakdownByModel(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->breakdown('model_id', $from, $to);
    }

    public function getBreakdownByService(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->breakdown('service_type', $from, $to);
    }

    public function getTotalsGroupedBy(string $column, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $this->assertGroupableColumn($column);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select($column)->from(self::TABLE);
        $this->selectUsageSums($qb, $from, $to);
        $rows = $qb
            ->groupBy($column)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $key = $row[$column] ?? null;
            if (!is_string($key) && !is_int($key)) {
                if (is_numeric($key)) {
                    $key = (int)$key;
                } else {
                    continue;
                }
            }
            $out[$key] = [
                'cost'     => is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : 0.0,
                'requests' => is_numeric($row['requests'] ?? null) ? (int)$row['requests'] : 0,
                'tokens'   => is_numeric($row['tokens'] ?? null) ? (int)$row['tokens'] : 0,
            ];
        }

        return $out;
    }

    public function getPerUserUsage(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('be_user')
            ->addSelectLiteral('SUM(estimated_cost) AS cost')
            ->addSelectLiteral('SUM(request_count) AS requests')
            ->addSelectLiteral('SUM(tokens_used) AS tokens')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->gte('request_date', $qb->createNamedParameter($from->getTimestamp())),
                $qb->expr()->lte('request_date', $qb->createNamedParameter($to->getTimestamp())),
            )
            ->groupBy('be_user')
            ->orderBy('cost', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $merged = self::mergeUsernames($rows, $this->resolveUsernames($rows));

        // Batch-load every per-user budget in one query (keyed by uid) instead
        // of one findOneByBeUser() round-trip per row inside the loop (N+1).
        // Mirrors how resolveUsernames() batches the be_users lookup above.
        $budgets = $this->userBudgetRepository->findByBeUsers(
            array_map(static fn(array $entry): int => $entry['beUserUid'], $merged),
        );

        $now = new DateTimeImmutable();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();
        $result = [];
        foreach ($merged as $entry) {
            $entry['budget'] = $this->budgetConsumption(
                $entry['beUserUid'],
                $budgets[$entry['beUserUid']] ?? null,
                $monthStart,
                $now->getTimestamp(),
            );
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows raw DB rows ordered by request_date ASC
     *
     * @return list<array{date: string, cost: float, requests: int, tokens: int}>
     */
    public static function fillDailyGaps(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $ts = is_numeric($row['request_date'] ?? null) ? (int)$row['request_date'] : null;
            if ($ts === null) {
                continue;
            }
            // setTimestamp() keeps the object's default (local) timezone; '@'.$ts
            // would force UTC and misbucket local-midnight request_date values.
            $key = (new DateTimeImmutable())->setTimestamp($ts)->format('Y-m-d');
            $byDate[$key] = [
                'cost'     => is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : 0.0,
                'requests' => is_numeric($row['requests'] ?? null) ? (int)$row['requests'] : 0,
                'tokens'   => is_numeric($row['tokens'] ?? null) ? (int)$row['tokens'] : 0,
            ];
        }

        $series = [];
        $cursor = $from->setTime(0, 0, 0);
        $end = $to->setTime(0, 0, 0);
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date'     => $key,
                'cost'     => $byDate[$key]['cost'] ?? 0.0,
                'requests' => $byDate[$key]['requests'] ?? 0,
                'tokens'   => $byDate[$key]['tokens'] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * @param array<int, array<string, mixed>> $usageRows rows with be_user/cost/requests/tokens
     * @param array<int, string>               $userMap   beUserUid => username
     *
     * @return list<array{beUserUid: int, label: string, cost: float, requests: int, tokens: int}>
     */
    public static function mergeUsernames(array $usageRows, array $userMap): array
    {
        $out = [];
        foreach ($usageRows as $row) {
            $uid = is_numeric($row['be_user'] ?? null) ? (int)$row['be_user'] : 0;
            if ($uid === 0) {
                $label = 'system';
            } elseif (isset($userMap[$uid]) && $userMap[$uid] !== '') {
                $label = $userMap[$uid];
            } else {
                $label = 'user #' . $uid;
            }
            $out[] = [
                'beUserUid' => $uid,
                'label'     => $label,
                'cost'      => is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : 0.0,
                'requests'  => is_numeric($row['requests'] ?? null) ? (int)$row['requests'] : 0,
                'tokens'    => is_numeric($row['tokens'] ?? null) ? (int)$row['tokens'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Format raw per-key totals into display strings for the list-view columns.
     *
     * @param array<int|string, array{cost: float, requests: int, tokens: int}> $totals
     *
     * @return array{cost: array<int|string, string>, requests: array<int|string, string>, tokens: array<int|string, string>}
     */
    public static function formatUsageColumns(array $totals): array
    {
        $cost = [];
        $requests = [];
        $tokens = [];
        foreach ($totals as $key => $t) {
            $cost[$key]     = '~$' . number_format($t['cost'], 2);
            $requests[$key] = number_format((float)$t['requests']);
            $tokens[$key]   = number_format((float)$t['tokens']);
        }

        return ['cost' => $cost, 'requests' => $requests, 'tokens' => $tokens];
    }

    /**
     * Apply the shared SUM selects and the request_date window WHERE to a query.
     */
    private function selectUsageSums(QueryBuilder $qb, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $qb->addSelectLiteral(self::SUM_COST)
            ->addSelectLiteral(self::SUM_REQUESTS)
            ->addSelectLiteral(self::SUM_TOKENS)
            ->where(
                $qb->expr()->gte('request_date', $qb->createNamedParameter($from->getTimestamp())),
                $qb->expr()->lte('request_date', $qb->createNamedParameter($to->getTimestamp())),
            );
    }

    private function assertGroupableColumn(string $column): void
    {
        if (!in_array($column, self::GROUPABLE_COLUMNS, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported group column "%s"', $column),
                1751299200,
            );
        }
    }

    /**
     * @return list<array{label: string, cost: float, requests: int, tokens: int}>
     */
    private function breakdown(string $column, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $this->assertGroupableColumn($column);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select($column)->from(self::TABLE);
        $this->selectUsageSums($qb, $from, $to);
        $rows = $qb
            ->groupBy($column)
            ->orderBy('cost', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $label = is_string($row[$column] ?? null) ? $row[$column] : '';
            if ($label === '') {
                $label = 'unknown';
            }
            $out[] = [
                'label'    => $label,
                'cost'     => is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : 0.0,
                'requests' => is_numeric($row['requests'] ?? null) ? (int)$row['requests'] : 0,
                'tokens'   => is_numeric($row['tokens'] ?? null) ? (int)$row['tokens'] : 0,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $usageRows
     *
     * @return array<int, string> beUserUid => username
     */
    private function resolveUsernames(array $usageRows): array
    {
        $uids = [];
        foreach ($usageRows as $row) {
            $uid = is_numeric($row['be_user'] ?? null) ? (int)$row['be_user'] : 0;
            if ($uid > 0) {
                $uids[$uid] = $uid;
            }
        }
        if ($uids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('uid', 'username')
            ->from('be_users')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter(array_values($uids), Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            if (is_numeric($row['uid'] ?? null) && is_string($row['username'] ?? null)) {
                $map[(int)$row['uid']] = $row['username'];
            }
        }

        return $map;
    }

    /**
     * @param UserBudget|null $budget Pre-fetched budget for $beUserUid (batch-loaded by the caller).
     *
     * @return array{usedCost: float, limitCost: float, percent: float}|null
     */
    private function budgetConsumption(int $beUserUid, ?UserBudget $budget, int $monthStart, int $now): ?array
    {
        if ($beUserUid <= 0) {
            return null;
        }
        if ($budget === null || !$budget->isActive() || $budget->getMaxCostPerMonth() <= 0.0) {
            return null;
        }

        $windows = $this->budgetUsageWindows->aggregate($beUserUid, null, $monthStart, $now);
        $used = $windows['monthly']['cost'];
        $limit = $budget->getMaxCostPerMonth();

        return [
            'usedCost'  => $used,
            'limitCost' => $limit,
            'percent'   => $limit > 0.0 ? min(100.0, ($used / $limit) * 100.0) : 0.0,
        ];
    }
}
