<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ReadFalAssetMetaTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ReadFalAssetMetaTool.
 *
 * Verifies the storage-scoped sys_file metadata read: an asset in an
 * allowed storage returns name/MIME/size (+ title/alt), while an asset in
 * a non-allowed storage and a missing uid both return the same neutral
 * "not found or not permitted" string — the model-chosen uid must never be
 * able to enumerate arbitrary storages, and a missing row must not throw.
 */
#[CoversClass(ReadFalAssetMetaTool::class)]
final class ReadFalAssetMetaToolTest extends AbstractFunctionalTestCase
{
    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private ReadFalAssetMetaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new ReadFalAssetMetaTool($connectionPool);
    }

    #[Test]
    public function getSpecDeclaresReadFalAssetMetaFunction(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('read_fal_asset_meta', $spec->name);
        self::assertSame(['uid'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('uid', $properties);
    }

    #[Test]
    public function returnsMetadataForAssetInAllowedStorage(): void
    {
        $this->importFixture('sys_file_tools.csv');
        $this->importFixture('sys_file_metadata_tools.csv');

        $output = $this->tool->execute(['uid' => 1])->content;

        self::assertStringContainsString('logo.png', $output);
        self::assertStringContainsString('image/png', $output);
        self::assertStringContainsString('2048', $output);
        self::assertStringContainsString('Company Logo', $output);
        self::assertStringContainsString('Logo alt text', $output);
    }

    #[Test]
    public function rejectsAssetOutsideAllowedStorage(): void
    {
        $this->importFixture('sys_file_tools.csv');

        self::assertSame(self::NOT_PERMITTED, $this->tool->execute(['uid' => 2])->content);
    }

    #[Test]
    public function returnsNotFoundForMissingUidWithoutThrowing(): void
    {
        $this->importFixture('sys_file_tools.csv');

        self::assertSame(self::NOT_PERMITTED, $this->tool->execute(['uid' => 999999])->content);
    }
}
