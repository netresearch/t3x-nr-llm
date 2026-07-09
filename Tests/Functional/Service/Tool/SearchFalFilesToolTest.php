<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\SearchFalFilesTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for SearchFalFilesTool (ADR-047): name and metadata-title
 * matches, literal LIKE-wildcard handling, storage scoping, missing files
 * excluded.
 */
#[CoversClass(SearchFalFilesTool::class)]
final class SearchFalFilesToolTest extends AbstractFunctionalTestCase
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

        // Storage 1 (allowed): a name match, a metadata match, a missing file.
        $files->insert('sys_file', [
            'uid' => 10, 'storage' => 1, 'identifier' => '/brochure-2026.pdf',
            'name' => 'brochure-2026.pdf', 'mime_type' => 'application/pdf', 'size' => 1000, 'missing' => 0,
        ]);
        $files->insert('sys_file', [
            'uid' => 11, 'storage' => 1, 'identifier' => '/img/DSC01.jpg',
            'name' => 'DSC01.jpg', 'mime_type' => 'image/jpeg', 'size' => 2000, 'missing' => 0,
        ]);
        $files->insert('sys_file', [
            'uid' => 12, 'storage' => 1, 'identifier' => '/gone-brochure.pdf',
            'name' => 'gone-brochure.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'missing' => 1,
        ]);
        // Storage 2 (NOT allowed): must never surface.
        $files->insert('sys_file', [
            'uid' => 13, 'storage' => 2, 'identifier' => '/secret-brochure.pdf',
            'name' => 'secret-brochure.pdf', 'mime_type' => 'application/pdf', 'size' => 3000, 'missing' => 0,
        ]);

        $files->insert('sys_file_metadata', [
            'uid' => 100, 'file' => 11, 'sys_language_uid' => 0, 'title' => 'Team brochure photo',
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('search_fal_files');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function matchesFileNameAndMetadataTitleWithinAllowedStorages(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['query' => 'brochure']);

        // Name match (uid 10) and metadata-title match (uid 11).
        self::assertStringContainsString('[10] 1:/brochure-2026.pdf', $output);
        self::assertStringContainsString('[11] 1:/img/DSC01.jpg', $output);
        // Missing files and foreign storages never surface.
        self::assertStringNotContainsString('gone-brochure', $output);
        self::assertStringNotContainsString('secret-brochure', $output);
    }

    #[Test]
    public function likeWildcardsAreMatchedLiterally(): void
    {
        $this->setUpBackendUser(1);

        self::assertStringContainsString(
            'No files match "%"',
            $this->tool->execute(['query' => '%']),
        );
    }

    #[Test]
    public function storageArgumentOutsideTheGateIsDenied(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(
            'No accessible file storages.',
            $this->tool->execute(['query' => 'brochure', 'storage' => 2]),
        );
    }

    #[Test]
    public function failsClosedWithoutBackendUser(): void
    {
        self::assertSame(
            'No accessible file storages.',
            $this->tool->execute(['query' => 'brochure']),
        );
    }
    #[Test]
    public function searchIsCaseInsensitive(): void
    {
        $this->setUpBackendUser(1);

        // Fixture name is lowercase 'brochure-2026.pdf'; DB collations with
        // case-sensitive LIKE (e.g. PostgreSQL) must still match.
        $output = $this->tool->execute(['query' => 'BROCHURE']);

        self::assertStringContainsString('brochure-2026.pdf', $output);
        // Uppercase name matched by lowercase query, too.
        $output = $this->tool->execute(['query' => 'dsc01']);
        self::assertStringContainsString('DSC01.jpg', $output);
    }
}
