<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\FindMissingFilesTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for FindMissingFilesTool (ADR-047): only missing=1 rows
 * of the effective storages surface, with the total count always reported.
 */
#[CoversClass(FindMissingFilesTool::class)]
final class FindMissingFilesToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $files = $connectionPool->getConnectionForTable('sys_file');
        self::assertInstanceOf(Connection::class, $files);

        $files->insert('sys_file', [
            'uid' => 30, 'storage' => 1, 'identifier' => '/lost.pdf',
            'name' => 'lost.pdf', 'mime_type' => 'application/pdf', 'size' => 1234, 'missing' => 1,
        ]);
        $files->insert('sys_file', [
            'uid' => 31, 'storage' => 1, 'identifier' => '/present.pdf',
            'name' => 'present.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'missing' => 0,
        ]);
        // Missing, but in a storage outside the gate.
        $files->insert('sys_file', [
            'uid' => 32, 'storage' => 2, 'identifier' => '/foreign-lost.pdf',
            'name' => 'foreign-lost.pdf', 'mime_type' => 'application/pdf', 'size' => 20, 'missing' => 1,
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('find_missing_files');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function listsOnlyMissingFilesOfTheEffectiveStorages(): void
    {
        $user = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($user);

        $output = $this->tool->execute([], $context)->content;

        self::assertStringContainsString('1 missing file (showing 1):', $output);
        self::assertStringContainsString('- [30] 1:/lost.pdf (last known size 1234 bytes)', $output);
        self::assertStringNotContainsString('present.pdf', $output);
        self::assertStringNotContainsString('foreign-lost.pdf', $output);
    }

    #[Test]
    public function storageArgumentOutsideTheGateIsDenied(): void
    {
        $user = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($user);

        self::assertSame('No accessible file storages.', $this->tool->execute(['storage' => 2], $context)->content);
    }

    #[Test]
    public function failsClosedWithoutBackendUser(): void
    {
        self::assertSame('No accessible file storages.', $this->tool->execute([], ToolExecutionContext::none())->content);
    }
}
