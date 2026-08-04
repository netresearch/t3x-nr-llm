<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Reads and reconciles `tx_nrllm_mcp_tool` (ADR-116).
 *
 * A machine-managed catalogue: every row is written by an import and by nothing
 * else. The table carries no `deleted`, no `hidden` and no TCA, so all
 * restrictions are removed on every query — there is no enable field to honour
 * and leaving the default restrictions on would silently return nothing.
 *
 * Reconciliation marks rather than deletes. A tool that vanished from a server
 * keeps its row with `orphaned` set, because an operator who enabled it needs
 * to see that it went away; deleting it would present the same situation as a
 * tool that had never existed.
 */
final readonly class McpToolRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_mcp_tool';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * Every non-orphaned tool of one server.
     *
     * @return list<McpToolRecord>
     */
    public function findLiveByServer(int $serverUid): array
    {
        $queryBuilder = $this->queryBuilder();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('server', $queryBuilder->createNamedParameter($serverUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('orphaned', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('tool_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Every tool of one server, orphans included — the backend list shows both.
     *
     * @return list<McpToolRecord>
     */
    public function findAllByServer(int $serverUid): array
    {
        $queryBuilder = $this->queryBuilder();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('server', $queryBuilder->createNamedParameter($serverUid, ParameterType::INTEGER)))
            ->orderBy('orphaned', 'ASC')
            ->addOrderBy('tool_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Replace one server's catalogue with the tools an import just accepted.
     *
     * Written as an upsert plus an orphan sweep rather than delete-then-insert:
     * a row's uid is what the operator's per-tool state would hang off, and
     * recreating rows on every import would discard it. A tool that comes back
     * after disappearing is un-orphaned rather than duplicated.
     *
     * @param list<array{toolName: string, remoteName: string, description: string, inputSchema: string, remoteAnnotations: string}> $tools
     *
     * @return int the number of rows marked orphaned by this reconciliation
     */
    public function reconcile(int $serverUid, int $pid, array $tools, int $timestamp): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existing   = [];

        foreach ($this->findAllByServer($serverUid) as $record) {
            $existing[$record->toolName] = $record;
        }

        $seen = [];
        foreach ($tools as $tool) {
            $seen[$tool['toolName']] = true;

            $values = [
                'server'             => $serverUid,
                'pid'                => $pid,
                'tool_name'          => $tool['toolName'],
                'remote_name'        => $tool['remoteName'],
                'description'        => $tool['description'],
                'input_schema'       => $tool['inputSchema'],
                'remote_annotations' => $tool['remoteAnnotations'],
                'orphaned'           => 0,
                'tstamp'             => $timestamp,
            ];

            if (isset($existing[$tool['toolName']])) {
                $connection->update(self::TABLE, $values, ['uid' => $existing[$tool['toolName']]->uid]);

                continue;
            }

            $connection->insert(self::TABLE, $values + ['crdate' => $timestamp]);
        }

        $orphaned = 0;
        foreach ($existing as $toolName => $record) {
            if (isset($seen[$toolName])) {
                continue;
            }
            ++$orphaned;

            // Already marked from an earlier import: counted, not rewritten,
            // so tstamp keeps saying when the tool actually disappeared.
            if ($record->orphaned) {
                continue;
            }

            $connection->update(self::TABLE, ['orphaned' => 1, 'tstamp' => $timestamp], ['uid' => $record->uid]);
        }

        return $orphaned;
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): McpToolRecord
    {
        return new McpToolRecord(
            uid: self::toInt($row['uid'] ?? 0),
            pid: self::toInt($row['pid'] ?? 0),
            server: self::toInt($row['server'] ?? 0),
            toolName: self::toStr($row['tool_name'] ?? ''),
            remoteName: self::toStr($row['remote_name'] ?? ''),
            description: self::toStr($row['description'] ?? ''),
            inputSchema: self::toStr($row['input_schema'] ?? ''),
            remoteAnnotations: self::toStr($row['remote_annotations'] ?? ''),
            orphaned: self::toInt($row['orphaned'] ?? 0) === 1,
            tstamp: self::toInt($row['tstamp'] ?? 0),
            crdate: self::toInt($row['crdate'] ?? 0),
        );
    }
}
