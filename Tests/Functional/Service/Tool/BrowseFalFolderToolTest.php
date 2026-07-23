<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\BrowseFalFolderTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for BrowseFalFolderTool (ADR-047): a real Local storage
 * folder lists subfolders and files, and everything unresolvable collapses
 * into the neutral denial.
 */
#[CoversClass(BrowseFalFolderTool::class)]
final class BrowseFalFolderToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/hello.txt', 'Hello FAL');
        file_put_contents($this->instancePath . '/fileadmin/docs/manual.txt', 'The manual');

        $storageRepository = $this->get(StorageRepository::class);
        self::assertInstanceOf(StorageRepository::class, $storageRepository);
        self::assertSame(1, $storageRepository->createLocalStorage('Main storage', 'fileadmin/', 'relative'));

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('browse_fal_folder');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function listsSubfoldersAndFilesOfTheRoot(): void
    {
        $user    = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($user);

        $output = $this->tool->execute([], $context)->content;

        self::assertStringContainsString('Folder / of storage 1', $output);
        self::assertStringContainsString('- docs/ (1 files)', $output);
        self::assertStringContainsString('- hello.txt (text/plain, 9 B)', $output);
    }

    #[Test]
    public function listsASubfolderByIdentifier(): void
    {
        $user    = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($user);

        $output = $this->tool->execute(['folder' => '/docs/'], $context)->content;

        self::assertStringContainsString('- manual.txt (text/plain, 10 B)', $output);
        self::assertStringNotContainsString('hello.txt', $output);
    }

    #[Test]
    public function unknownFolderIsDeniedNeutrally(): void
    {
        $user    = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($user);

        self::assertSame(
            'Folder not found or not permitted.',
            $this->tool->execute(['folder' => '/does-not-exist/'], $context)->content,
        );
    }

    #[Test]
    public function unknownStorageIsDeniedNeutrally(): void
    {
        $user    = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($user);

        self::assertSame(
            'Folder not found or not permitted.',
            $this->tool->execute(['storage' => 99], $context)->content,
        );
    }

    #[Test]
    public function failsClosedWithoutBackendUser(): void
    {
        self::assertSame(
            'Folder not found or not permitted.',
            $this->tool->execute([], ToolExecutionContext::none())->content,
        );
    }
}
