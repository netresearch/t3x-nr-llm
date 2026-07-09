<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetFalReferencesTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for GetFalReferencesTool (ADR-047): usages render as
 * table:uid (field), deleted references stay invisible, hidden ones are
 * marked, and files outside the storage gate are denied neutrally.
 */
#[CoversClass(GetFalReferencesTool::class)]
final class GetFalReferencesToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->insert('sys_file', [
            'uid' => 20, 'storage' => 1, 'identifier' => '/logo.svg',
            'name' => 'logo.svg', 'mime_type' => 'image/svg+xml', 'size' => 500, 'missing' => 0,
        ]);
        $connection->insert('sys_file', [
            'uid' => 21, 'storage' => 2, 'identifier' => '/other.svg',
            'name' => 'other.svg', 'mime_type' => 'image/svg+xml', 'size' => 600, 'missing' => 0,
        ]);

        $connection->insert('sys_file_reference', [
            'uid' => 200, 'uid_local' => 20, 'uid_foreign' => 5, 'tablenames' => 'tt_content',
            'fieldname' => 'image', 'deleted' => 0, 'hidden' => 0,
        ]);
        $connection->insert('sys_file_reference', [
            'uid' => 201, 'uid_local' => 20, 'uid_foreign' => 1, 'tablenames' => 'pages',
            'fieldname' => 'media', 'deleted' => 0, 'hidden' => 1,
        ]);
        $connection->insert('sys_file_reference', [
            'uid' => 202, 'uid_local' => 20, 'uid_foreign' => 9, 'tablenames' => 'tt_content',
            'fieldname' => 'assets', 'deleted' => 1, 'hidden' => 0,
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('get_fal_references');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function listsReferencesWithHiddenMarkAndWithoutDeletedOnes(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['uid' => 20]);

        self::assertStringContainsString('References to logo.svg (2):', $output);
        self::assertStringContainsString('- tt_content:5 (field image)', $output);
        self::assertStringContainsString('- pages:1 (field media) [hidden]', $output);
        self::assertStringNotContainsString('tt_content:9', $output);
    }

    #[Test]
    public function unusedFileSaysSoWithTheSoftReferenceCaveat(): void
    {
        $this->setUpBackendUser(1);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('sys_file', [
            'uid' => 22, 'storage' => 1, 'identifier' => '/unused.png',
            'name' => 'unused.png', 'mime_type' => 'image/png', 'size' => 10, 'missing' => 0,
        ]);

        $output = $this->tool->execute(['uid' => 22]);

        self::assertStringContainsString('No references to unused.png', $output);
        self::assertStringContainsString('soft references', $output);
    }

    #[Test]
    public function fileInForeignStorageIsDeniedNeutrally(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame('Asset not found or not permitted.', $this->tool->execute(['uid' => 21]));
    }

    #[Test]
    public function failsClosedWithoutBackendUser(): void
    {
        self::assertSame('Asset not found or not permitted.', $this->tool->execute(['uid' => 20]));
    }
}
