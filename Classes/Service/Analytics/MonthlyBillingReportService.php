<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Builds the monthly billing report (ADR-231) from the queries the Analytics
 * page already runs, cut to one calendar month.
 *
 * "Results by kind" groups by service type, calling extension and task. A
 * later per-site split is one more whitelisted column in that grouping and
 * one more CSV column; nothing here assumes the three are all there is.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class MonthlyBillingReportService
{
    /** The grouping of "results by kind", in the order the columns appear. */
    public const KIND_COLUMNS = ['service_type', 'source_extension', 'task_uid'];

    public function __construct(
        private UsageAnalyticsServiceInterface $analytics,
        private EditorEntitlementDirectory $entitlements,
        private ExtensionConfiguration $extensionConfiguration,
        private ConnectionPool $connectionPool,
    ) {}

    public function build(BillingMonth $month, DateTimeImmutable $now): MonthlyBillingReport
    {
        $from = $month->start;
        $to   = $month->lastSecond;

        $totals = $this->analytics->getKpiTotals($from, $to);
        $markup = BillingMarkup::fromExtensionConfiguration($this->extensionConfiguration);

        [$activeEditors, $withoutUser] = $this->activeEditors($this->analytics->getPerUserUsage($from, $to));

        return new MonthlyBillingReport(
            month: $month,
            monthIsRunning: $month->isRunning($now),
            requests: $totals['requests'],
            tokens: $totals['tokens'],
            amounts: BillingAmounts::of($totals['cost'], $markup),
            markupAdjusted: $markup->adjusted,
            activeEditors: $activeEditors,
            withoutUser: $withoutUser,
            entitledEditors: $this->entitlements->entitledEditors(),
            administratorCount: $this->entitlements->activeAdministratorCount(),
            entitlementAsOf: $now,
            byKind: $this->byKind($this->analytics->getTotalsGroupedByColumns(self::KIND_COLUMNS, $from, $to)),
        );
    }

    /**
     * Split the per-user rows into backend users and the `be_user = 0` row
     * (CLI, scheduler), which is no editor. Every user with a request counts
     * as active, whatever their account state is today — the state is shown.
     *
     * @param list<array{beUserUid: int, label: string, cost: float, requests: int, tokens: int, budget: mixed}> $perUser
     *
     * @return array{0: list<array{uid: int, username: string, realName: string, admin: bool, disabled: bool, deleted: bool, cost: float, requests: int, tokens: int}>, 1: array{cost: float, requests: int, tokens: int}|null}
     */
    private function activeEditors(array $perUser): array
    {
        $states = $this->entitlements->accountStates(
            array_map(static fn (array $row): int => $row['beUserUid'], $perUser),
        );

        $editors     = [];
        $withoutUser = null;
        foreach ($perUser as $row) {
            $uid = $row['beUserUid'];
            if ($uid <= 0) {
                $withoutUser = ['cost' => $row['cost'], 'requests' => $row['requests'], 'tokens' => $row['tokens']];
                continue;
            }

            $state     = $states[$uid] ?? null;
            $editors[] = [
                'uid'      => $uid,
                'username' => $state !== null && $state['username'] !== '' ? $state['username'] : $row['label'],
                'realName' => $state['realName'] ?? '',
                'admin'    => $state['admin'] ?? false,
                'disabled' => $state['disabled'] ?? false,
                // A uid with no be_users row at all was removed from the table.
                'deleted'  => $state === null || $state['deleted'],
                'cost'     => $row['cost'],
                'requests' => $row['requests'],
                'tokens'   => $row['tokens'],
            ];
        }

        return [$editors, $withoutUser];
    }

    /**
     * @param list<array<string, int|float|string>> $rows
     *
     * @return list<array{serviceType: string, sourceExtension: string, taskUid: int, taskName: string, cost: float, requests: int, tokens: int}>
     */
    private function byKind(array $rows): array
    {
        $taskUids = [];
        foreach ($rows as $row) {
            $taskUid = (int) ($row['task_uid'] ?? 0);
            if ($taskUid > 0) {
                $taskUids[$taskUid] = $taskUid;
            }
        }

        $taskNames = $this->taskNames(array_values($taskUids));

        $out = [];
        foreach ($rows as $row) {
            $taskUid = (int) ($row['task_uid'] ?? 0);
            $out[]   = [
                'serviceType'     => (string) ($row['service_type'] ?? ''),
                'sourceExtension' => (string) ($row['source_extension'] ?? ''),
                'taskUid'         => $taskUid,
                'taskName'        => $taskNames[$taskUid] ?? '',
                'cost'            => (float) ($row['cost'] ?? 0.0),
                'requests'        => (int) ($row['requests'] ?? 0),
                'tokens'          => (int) ($row['tokens'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Task names without restrictions: a task deleted after it ran still
     * bills under its name.
     *
     * @param list<int> $uids
     *
     * @return array<int, string>
     */
    private function taskNames(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_task');
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('uid', 'name')
            ->from('tx_nrllm_task')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            if (is_numeric($row['uid'] ?? null) && is_string($row['name'] ?? null)) {
                $names[(int) $row['uid']] = $row['name'];
            }
        }

        return $names;
    }
}
