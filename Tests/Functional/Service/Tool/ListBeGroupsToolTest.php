<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ListBeGroupsTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ListBeGroupsTool.
 *
 * Backend roles ARE backend groups in TYPO3; the tool returns only uid + title
 * (no permission masks), title-ordered, soft-deleted groups excluded.
 */
#[CoversClass(ListBeGroupsTool::class)]
final class ListBeGroupsToolTest extends AbstractFunctionalTestCase
{
    private ListBeGroupsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new ListBeGroupsTool($connectionPool);

        $connection = $connectionPool->getConnectionForTable('be_groups');
        self::assertInstanceOf(Connection::class, $connection);
        // Insert out of alphabetical order to prove title ASC ordering.
        $this->insertGroup($connection, 1, 'Editors', ['non_exclude_fields' => 'pages:title']);
        $this->insertGroup($connection, 2, 'Administrators');
        $this->insertGroup($connection, 3, 'Deleted Group', ['deleted' => 1]);
    }

    #[Test]
    public function getSpecDeclaresListBeGroupsFunction(): void
    {
        self::assertSame('list_be_groups', $this->tool->getSpec()->name);
    }

    #[Test]
    public function executeListsLiveGroupsTitleOrdered(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringContainsString('Backend groups (2):', $output);
        // Title ASC: Administrators before Editors.
        $adminPos  = strpos($output, '[2] Administrators');
        $editorPos = strpos($output, '[1] Editors');
        self::assertIsInt($adminPos);
        self::assertIsInt($editorPos);
        self::assertLessThan($editorPos, $adminPos);
    }

    #[Test]
    public function executeExcludesSoftDeletedGroupsAndPermissionMasks(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringNotContainsString('Deleted Group', $output);
        // Only uid + title are emitted — never authorization columns.
        self::assertStringNotContainsString('non_exclude_fields', $output);
        self::assertStringNotContainsString('pages:title', $output);
    }

    #[Test]
    public function isAdminOnlyAndEnabledByDefault(): void
    {
        self::assertTrue($this->tool->requiresAdmin());
        self::assertTrue($this->tool->isEnabledByDefault());
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private function insertGroup(Connection $connection, int $uid, string $title, array $overrides = []): void
    {
        $connection->insert('be_groups', [
            'uid'                => $uid,
            'pid'                => 0,
            'title'              => $title,
            'non_exclude_fields' => $overrides['non_exclude_fields'] ?? '',
            'deleted'            => $overrides['deleted'] ?? 0,
        ]);
    }
}
