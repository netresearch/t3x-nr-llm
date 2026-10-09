<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\PageTsConfigReader;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Page TSconfig for a named backend user, never the ambient one (#1017).
 *
 * Core reads the ambient user in three places; the tool tests cover the user
 * TSconfig overrides through each caller. This covers the third input, the
 * user the `[backend.user…]` conditions see, and that the context the
 * request runs in is left as it was.
 */
#[CoversClass(PageTsConfigReader::class)]
final class PageTsConfigReaderTest extends AbstractFunctionalTestCase
{
    private const PAGE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Home', 'doktype' => 1,
            'TSconfig' => "demo.base = 1\n[backend.user.isAdmin]\ndemo.admin = 1\n[END]",
        ]);
    }

    #[Test]
    public function theConditionsSeeTheNamedUserNotTheAmbientOne(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);
        $reader = new PageTsConfigReader();

        // Ambient: the non-admin editor. Named: the admin.
        self::assertSame(['base' => '1', 'admin' => '1'], $reader->forPage(self::PAGE, $admin)['demo.'] ?? null);

        // And the other way round.
        $this->setUpBackendUser(1);
        self::assertSame(['base' => '1'], $reader->forPage(self::PAGE, $editor)['demo.'] ?? null);

        // No named user is no user, not the ambient admin.
        self::assertSame(['base' => '1'], $reader->forPage(self::PAGE, null)['demo.'] ?? null);
    }

    #[Test]
    public function theRequestsContextIsLeftAsItWas(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->setUpBackendUser(2);
        $context = $this->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        $user      = $context->getAspect('backend.user');
        $workspace = $context->getAspect('workspace');

        (new PageTsConfigReader())->forPage(self::PAGE, $admin);

        self::assertSame($user, $context->getAspect('backend.user'));
        self::assertSame($workspace, $context->getAspect('workspace'));
        self::assertSame(2, $context->getPropertyFromAspect('backend.user', 'id'));
    }
}
