<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Usage;

use DateTimeInterface;
use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Domain\ValueObject\ExtensionUsageTotals;
use Netresearch\NrLlm\Domain\ValueObject\OperationUsage;
use Netresearch\NrLlm\Domain\ValueObject\TelemetryCoverage;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Reads tx_nrllm_service_usage and tx_nrllm_telemetry for one source extension.
 *
 * Neither table has TCA enable fields, so no restrictions apply (the same
 * reading UsageAnalyticsService and TelemetryRepository use).
 *
 * @internal use {@see ExtensionUsageReaderInterface}
 */
final readonly class ExtensionUsageReader implements ExtensionUsageReaderInterface
{
    private const USAGE_TABLE = 'tx_nrllm_service_usage';

    private const TELEMETRY_TABLE = 'tx_nrllm_telemetry';

    /** The writers keep this many characters of the caller's name. */
    private const SOURCE_LENGTH = 64;

    public function __construct(
        private ConnectionPool $connectionPool,
        private PrivacyPolicyInterface $privacyPolicy,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function totals(
        string $sourceExtension,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $beUserUid = null,
    ): ExtensionUsageTotals {
        // The aggregate holds one row per day, stamped with that day's local
        // midnight (UsageTrackerService: strtotime('today')). A period that
        // starts during a day includes that day's row, the way the telemetry
        // counts include that day's later calls.
        $firstDay = strtotime('today', $from->getTimestamp());

        $qb = $this->connectionPool->getQueryBuilderForTable(self::USAGE_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->addSelectLiteral('COALESCE(SUM(estimated_cost), 0) AS cost')
            ->addSelectLiteral('COALESCE(SUM(request_count), 0) AS requests')
            ->addSelectLiteral('COALESCE(SUM(tokens_used), 0) AS tokens')
            ->from(self::USAGE_TABLE)
            ->where(
                $qb->expr()->eq('source_extension', $qb->createNamedParameter(substr($sourceExtension, 0, self::SOURCE_LENGTH))),
                $qb->expr()->gte('request_date', $qb->createNamedParameter($firstDay !== false ? $firstDay : $from->getTimestamp(), Connection::PARAM_INT)),
                $qb->expr()->lte('request_date', $qb->createNamedParameter($to->getTimestamp(), Connection::PARAM_INT)),
            );
        $this->narrowToUser($qb, $beUserUid);

        $row = $qb->executeQuery()->fetchAssociative();
        $row = is_array($row) ? $row : [];

        return new ExtensionUsageTotals(
            is_numeric($row['cost'] ?? null) ? (float) $row['cost'] : 0.0,
            is_numeric($row['requests'] ?? null) ? (int) $row['requests'] : 0,
            is_numeric($row['tokens'] ?? null) ? (int) $row['tokens'] : 0,
        );
    }

    public function operations(
        string $sourceExtension,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $beUserUid = null,
    ): array {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TELEMETRY_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('source_operation')
            ->addSelectLiteral('COUNT(*) AS calls')
            ->addSelectLiteral('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed')
            ->addSelectLiteral('MAX(crdate) AS last_call')
            ->from(self::TELEMETRY_TABLE)
            ->where(
                $qb->expr()->eq('source_extension', $qb->createNamedParameter(substr($sourceExtension, 0, self::SOURCE_LENGTH))),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($from->getTimestamp(), Connection::PARAM_INT)),
                $qb->expr()->lte('crdate', $qb->createNamedParameter($to->getTimestamp(), Connection::PARAM_INT)),
            );
        $this->narrowToUser($qb, $beUserUid);
        $qb->groupBy('source_operation')
            ->orderBy('calls', 'DESC')
            ->addOrderBy('source_operation', 'ASC');

        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $out[] = new OperationUsage(
                is_string($row['source_operation'] ?? null) ? $row['source_operation'] : '',
                is_numeric($row['calls'] ?? null) ? (int) $row['calls'] : 0,
                is_numeric($row['failed'] ?? null) ? (int) $row['failed'] : 0,
                is_numeric($row['last_call'] ?? null) ? (int) $row['last_call'] : 0,
            );
        }

        return $out;
    }

    public function coverage(): TelemetryCoverage
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TELEMETRY_TABLE);
        $qb->getRestrictions()->removeAll();
        $oldest = $qb->addSelectLiteral('MIN(crdate) AS oldest')
            ->from(self::TELEMETRY_TABLE)
            ->executeQuery()
            ->fetchOne();

        return new TelemetryCoverage(
            $this->telemetryEnabled(),
            $this->privacyPolicy->retentionDaysFor(PrivacyDataCategory::TELEMETRY),
            is_numeric($oldest) ? (int) $oldest : null,
        );
    }

    private function narrowToUser(QueryBuilder $qb, ?int $beUserUid): void
    {
        if ($beUserUid === null) {
            return;
        }

        $qb->andWhere($qb->expr()->eq('be_user', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)));
    }

    /**
     * Telemetry defaults ON and is off only for an explicit falsey
     * `telemetry.enabled`, the reading TelemetryMiddleware applies.
     */
    private function telemetryEnabled(): bool
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return true;
        }

        $telemetry = is_array($config) && is_array($config['telemetry'] ?? null) ? $config['telemetry'] : [];
        if (!array_key_exists('enabled', $telemetry)) {
            return true;
        }

        return (bool) $telemetry['enabled'];
    }
}
