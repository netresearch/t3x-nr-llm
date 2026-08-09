<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Schema;

use Doctrine\DBAL\Schema\Column;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * A server configured before `requires_approval` existed requires approval after
 * the schema update (ADR-134).
 *
 * Nothing corrects the column after the fact — there is no upgrade wizard — so
 * the schema default is the whole mechanism, and this test is what holds it.
 * The path exercised is the one `extension:setup` and the install tool take:
 * {@see SqlReader} collects the CREATE TABLE statements, {@see SchemaMigrator}
 * diffs them against the live schema and runs the add/change statements
 * (`TYPO3\CMS\Core\Package\PackageSetup::updateDatabaseSchema()`).
 */
#[CoversClass(McpServerRecord::class)]
final class McpServerApprovalDefaultTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_mcp_server';

    #[Test]
    public function aServerConfiguredBeforeTheColumnExistedRequiresApprovalAfterTheSchemaUpdate(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable(self::TABLE);

        // Rewind the schema to the version before this feature. Portable DDL
        // rather than a Doctrine TableDiff, whose constructor is @internal and
        // may only be produced by a Comparator.
        $connection->executeStatement(
            'ALTER TABLE ' . self::TABLE . ' DROP COLUMN requires_approval',
        );
        self::assertNotContains('requires_approval', $this->columnsOf($connectionPool));

        // A server from that version: configured, enabled, importing tools.
        $connection->insert(self::TABLE, [
            'pid'              => 0,
            'identifier'       => 'legacy',
            'name'             => 'Legacy server',
            'description'      => '',
            'url'              => 'https://mcp.example.com/rpc',
            'auth_credential'  => '',
            'auth_placement'   => 'bearer',
            'auth_header_name' => '',
            'data_class'       => 'publicContent',
            'enabled'          => 1,
            'import_status'    => 'ok',
            'import_error'     => '',
            'last_imported'    => 1_700_000_000,
            'tool_count'       => 3,
            'tstamp'           => 0,
            'crdate'           => 0,
            'deleted'          => 0,
            'hidden'           => 0,
        ]);

        $this->updateDatabaseSchema();

        self::assertContains('requires_approval', $this->columnsOf($connectionPool));

        // The stored byte, and the reading the agent loop actually acts on.
        self::assertSame(1, $this->storedFlag($connectionPool));

        $repository = $this->get(McpServerRepository::class);
        self::assertInstanceOf(McpServerRepository::class, $repository);
        $records = $repository->findAll();
        self::assertCount(1, $records);
        self::assertTrue($records[0]->approvalRequired());
    }

    /**
     * The add/change half of `PackageSetup::updateDatabaseSchema()`.
     */
    private function updateDatabaseSchema(): void
    {
        $sqlReader = $this->get(SqlReader::class);
        self::assertInstanceOf(SqlReader::class, $sqlReader);
        $schemaMigrator = $this->get(SchemaMigrator::class);
        self::assertInstanceOf(SchemaMigrator::class, $schemaMigrator);

        /** @var array<int, string> $statements */
        $statements = array_values(array_filter(
            $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString()),
            is_string(...),
        ));

        $suggestions = array_merge_recursive(...array_values($schemaMigrator->getUpdateSuggestions($statements)));

        // Only the keys are read (`array_intersect_key`); the hash doubles as
        // the value so the declared `array<string>` holds.
        $selected = [];
        foreach (['add', 'change', 'create_table', 'change_table'] as $action) {
            if (!isset($suggestions[$action])) {
                continue;
            }

            if (!is_array($suggestions[$action])) {
                continue;
            }

            foreach (array_keys($suggestions[$action]) as $hash) {
                $selected[$hash] = (string)$hash;
            }
        }

        $schemaMigrator->migrate($statements, $selected);
    }

    /**
     * @return array<int, string>
     */
    private function columnsOf(ConnectionPool $connectionPool): array
    {
        $columns = $connectionPool
            ->getConnectionForTable(self::TABLE)
            ->createSchemaManager()
            ->listTableColumns(self::TABLE);

        return array_map(static fn(Column $column): string => $column->getName(), array_values($columns));
    }

    private function storedFlag(ConnectionPool $connectionPool): int
    {
        $queryBuilder = $connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder
            ->select('requires_approval')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter('legacy')))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int)$value : -1;
    }
}
