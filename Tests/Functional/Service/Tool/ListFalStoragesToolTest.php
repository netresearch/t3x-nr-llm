<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ListFalStoragesTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Functional tests for ListFalStoragesTool (ADR-047): the effective storages
 * render with name/driver/flags, the server-side base path never egresses,
 * and the tool fails closed without a backend user.
 */
#[CoversClass(ListFalStoragesTool::class)]
final class ListFalStoragesToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $storageRepository = $this->get(StorageRepository::class);
        self::assertInstanceOf(StorageRepository::class, $storageRepository);
        self::assertSame(1, $storageRepository->createLocalStorage('Main storage', 'fileadmin/', 'relative'));

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('list_fal_storages');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function listsTheAccessibleStorageWithoutServerPath(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute([]);

        self::assertStringContainsString('Accessible file storages (1):', $output);
        self::assertStringContainsString('[1] Main storage (driver Local', $output);
        // The server-side base path must never egress.
        self::assertStringNotContainsString('fileadmin', $output);
        self::assertStringNotContainsString($this->instancePath, $output);
    }

    #[Test]
    public function failsClosedWithoutBackendUser(): void
    {
        self::assertSame('No accessible file storages.', $this->tool->execute([]));
    }
}
