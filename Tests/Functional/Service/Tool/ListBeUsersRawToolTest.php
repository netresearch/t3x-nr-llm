<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ListBeUsersRawTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ListBeUsersRawTool — the full-column variant.
 *
 * It selects `*` but still strips the credential columns (`password`, `mfa`)
 * before emitting, and ships disabled-by-default because the wide column set
 * reveals the backend account layout. The credential-exclusion assertion is
 * the load-bearing one: even with `SELECT *`, no hash or MFA seed may egress.
 */
#[CoversClass(ListBeUsersRawTool::class)]
final class ListBeUsersRawToolTest extends AbstractFunctionalTestCase
{
    private const SECRET_HASH = '$2y$12$THISisAcrackableHASHmarker0000000000000000000000000';

    private const SECRET_MFA = '{"totp":"JBSWY3DPEHPK3PXP-secret-seed-marker"}';

    private ListBeUsersRawTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new ListBeUsersRawTool($connectionPool);

        $connection = $connectionPool->getConnectionForTable('be_users');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('be_users', [
            'uid'      => 20,
            'pid'      => 0,
            'username' => 'dave',
            'password' => self::SECRET_HASH,
            'mfa'      => self::SECRET_MFA,
            'realName' => 'Dave Raw',
            'email'    => 'dave@example.com',
            'admin'    => 1,
            'deleted'  => 0,
        ]);
    }

    #[Test]
    public function getSpecDeclaresRawFunction(): void
    {
        self::assertSame('list_be_users_raw', $this->tool->getSpec()->name);
    }

    #[Test]
    public function executeEmitsWideColumnSet(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringContainsString('Backend users (1):', $output);
        self::assertStringContainsString('[20]', $output);
        self::assertStringContainsString('username: dave', $output);
        self::assertStringContainsString('realName: Dave Raw', $output);
        // A column the redacted variant never selects, proving "raw" really is wide.
        self::assertStringContainsString('email: dave@example.com', $output);
    }

    #[Test]
    public function executeStripsCredentialColumnsEvenWithSelectStar(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringNotContainsString(self::SECRET_HASH, $output);
        self::assertStringNotContainsString(self::SECRET_MFA, $output);
        self::assertStringNotContainsString('password:', $output);
        self::assertStringNotContainsString('mfa:', $output);
    }

    #[Test]
    public function isAdminOnlyAndDisabledByDefault(): void
    {
        self::assertTrue($this->tool->requiresAdmin());
        self::assertFalse($this->tool->isEnabledByDefault());
    }
}
