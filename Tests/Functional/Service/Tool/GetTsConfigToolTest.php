<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetTsConfigTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for GetTsConfigTool (ADR-042).
 *
 * Load-bearing: the rootline-merged Page TSconfig from the seeded page's
 * ``TSconfig`` column resolves, the dotted path drills into the right
 * subtree, credential-ish values are redacted, and a missing page returns
 * the neutral string.
 */
#[CoversClass(GetTsConfigTool::class)]
final class GetTsConfigToolTest extends AbstractFunctionalTestCase
{
    private GetTsConfigTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new GetTsConfigTool($connectionPool);

        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'sorting' => 1,
            'TSconfig' => "demo.flag = 1\ndemo.nested.label = Editorial\nvault.apiKey = top-secret-value",
        ]);
    }

    #[Test]
    public function topLevelKeysListWithoutPath(): void
    {
        $output = $this->tool->execute(['pageUid' => 1]);

        self::assertStringContainsString('Page TSconfig for page 1', $output);
        self::assertStringContainsString('demo (+', $output);
    }

    #[Test]
    public function dottedPathDrillsIntoTheSubtree(): void
    {
        $output = $this->tool->execute(['pageUid' => 1, 'path' => 'demo']);

        self::assertStringContainsString('flag = 1', $output);
        self::assertStringContainsString('nested {', $output);
        self::assertStringContainsString('label = Editorial', $output);
    }

    #[Test]
    public function credentialValuesAreRedacted(): void
    {
        $output = $this->tool->execute(['pageUid' => 1, 'path' => 'vault']);

        self::assertStringContainsString('apiKey = [redacted]', $output);
        self::assertStringNotContainsString('top-secret-value', $output);
    }

    #[Test]
    public function missingPageReturnsNeutralString(): void
    {
        self::assertSame('Page not found.', $this->tool->execute(['pageUid' => 999]));
    }
}
