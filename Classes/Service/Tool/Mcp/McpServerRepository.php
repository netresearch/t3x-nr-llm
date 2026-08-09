<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reads and updates `tx_nrllm_mcp_server` (ADR-116).
 *
 * The table is operator-managed and carries `deleted`/`hidden`, unlike the
 * catalogue table beside it. The deleted restriction is kept: a discarded
 * server must disappear from the tool surface. The hidden restriction is
 * dropped deliberately — `enabled` is this table's own switch and the one an
 * operator reasons about, so honouring `hidden` as well would give the same
 * decision two controls that can disagree.
 */
final readonly class McpServerRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_mcp_server';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * Every configured server, enabled or not — the backend list needs both.
     *
     * @return list<McpServerRecord>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->queryBuilder();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('identifier', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * The servers whose tools may be offered at all.
     *
     * Two conditions, both necessary: the operator switched the server on, and
     * the operator declared what class of data it may see. An undeclared server
     * is not a server with a default — it is one nobody has classified, and
     * {@see McpServerRecord::dataClassEnum()} says why that must stay inert.
     *
     * @return list<McpServerRecord>
     */
    public function findUsable(): array
    {
        return array_values(array_filter(
            $this->findEnabled(),
            static fn(McpServerRecord $server): bool => $server->dataClassEnum() instanceof ToolDataClass,
        ));
    }

    /**
     * @return list<McpServerRecord>
     */
    public function findEnabled(): array
    {
        $queryBuilder = $this->queryBuilder();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)))
            ->orderBy('identifier', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByUid(int $uid): ?McpServerRecord
    {
        $queryBuilder = $this->queryBuilder();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Record how the last import went.
     *
     * The error text is stored even when empty, so a server that recovers stops
     * showing the fault that has been fixed.
     */
    public function recordImportOutcome(int $uid, string $status, string $error, int $toolCount, int $timestamp): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'import_status' => $status,
                'import_error'  => $error,
                'tool_count'    => $toolCount,
                'last_imported' => $timestamp,
                'tstamp'        => $timestamp,
            ],
            ['uid' => $uid],
        );
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): McpServerRecord
    {
        return new McpServerRecord(
            uid: self::toInt($row['uid'] ?? 0),
            pid: self::toInt($row['pid'] ?? 0),
            identifier: self::toStr($row['identifier'] ?? ''),
            name: self::toStr($row['name'] ?? ''),
            description: self::toStr($row['description'] ?? ''),
            url: self::toStr($row['url'] ?? ''),
            authCredential: self::toStr($row['auth_credential'] ?? ''),
            authPlacement: self::toStr($row['auth_placement'] ?? ''),
            authHeaderName: self::toStr($row['auth_header_name'] ?? ''),
            dataClass: self::toStr($row['data_class'] ?? ''),
            // Absent key -> '' -> approval required. The opposite default would
            // mean a row this code could not read runs unattended, which is the
            // one outcome the column exists to prevent (ADR-134).
            requiresApproval: self::toStr($row['requires_approval'] ?? ''),
            enabled: self::toInt($row['enabled'] ?? 0) === 1,
            importStatus: self::toStr($row['import_status'] ?? ''),
            importError: self::toStr($row['import_error'] ?? ''),
            lastImported: self::toInt($row['last_imported'] ?? 0),
            toolCount: self::toInt($row['tool_count'] ?? 0),
            tstamp: self::toInt($row['tstamp'] ?? 0),
            crdate: self::toInt($row['crdate'] ?? 0),
        );
    }
}
