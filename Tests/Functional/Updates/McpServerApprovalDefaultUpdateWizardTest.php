<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Updates;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Updates\McpServerApprovalDefaultUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(McpServerApprovalDefaultUpdateWizard::class)]
final class McpServerApprovalDefaultUpdateWizardTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_mcp_server';

    #[Test]
    public function serversThatWereAlreadyImportingAreUnpinnedAndTheWizardIsIdempotent(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable(self::TABLE);

        // What the schema update leaves behind: a working integration carrying
        // the new default, plus a deleted sibling that must not come back with
        // different behaviour than the rest.
        $connection->insert(self::TABLE, $this->serverRow('running', lastImported: 1_700_000_000));
        $connection->insert(self::TABLE, $this->serverRow('discarded', lastImported: 1_700_000_000, deleted: 1));
        // Configured but never imported: it offers no tools, so there is no
        // running integration to preserve and it keeps the safe default.
        $connection->insert(self::TABLE, $this->serverRow('fresh', lastImported: 0));

        $wizard = new McpServerApprovalDefaultUpdateWizard($connectionPool);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(0, $this->flagOf($connection, 'running'));
        self::assertSame(0, $this->flagOf($connection, 'discarded'));
        self::assertSame(1, $this->flagOf($connection, 'fresh'));

        // Idempotent: nothing left to do, and a second run changes nothing.
        self::assertFalse($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(0, $this->flagOf($connection, 'running'));
        self::assertSame(0, $this->flagOf($connection, 'discarded'));
        self::assertSame(1, $this->flagOf($connection, 'fresh'));
    }

    /**
     * A fresh install that has just configured its first server must not be
     * offered a wizard that switches approval off again.
     */
    #[Test]
    public function aNeverImportedServerAloneDoesNotTriggerTheWizard(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $this->serverRow('fresh', lastImported: 0));

        self::assertFalse((new McpServerApprovalDefaultUpdateWizard($connectionPool))->updateNecessary());
    }

    /**
     * @return array<string, int|string>
     */
    private function serverRow(string $identifier, int $lastImported, int $deleted = 0): array
    {
        return [
            'pid'               => 0,
            'identifier'        => $identifier,
            'name'              => $identifier,
            'description'       => '',
            'url'               => 'https://mcp.example.com/rpc',
            'auth_credential'   => '',
            'auth_placement'    => 'bearer',
            'auth_header_name'  => '',
            'data_class'        => 'publicContent',
            // The value the schema update gives every pre-existing row.
            'requires_approval' => 1,
            'enabled'           => 1,
            'import_status'     => $lastImported > 0 ? 'ok' : 'never_imported',
            'import_error'      => '',
            'last_imported'     => $lastImported,
            'tool_count'        => $lastImported > 0 ? 3 : 0,
            'tstamp'            => 0,
            'crdate'            => 0,
            'deleted'           => $deleted,
            'hidden'            => 0,
        ];
    }

    private function flagOf(Connection $connection, string $identifier): int
    {
        $builder = $connection->createQueryBuilder();
        $builder->getRestrictions()->removeAll();
        $value = $builder
            ->select('requires_approval')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('identifier', $builder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int)$value : -1;
    }
}
