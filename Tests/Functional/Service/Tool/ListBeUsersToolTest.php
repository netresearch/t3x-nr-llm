<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ListBeUsersTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for ListBeUsersTool.
 *
 * Load-bearing security assertion: the `password` hash and `mfa` secret of a
 * backend user MUST NEVER reach the tool output (and therefore the LLM
 * provider), and soft-deleted users must be excluded.
 */
#[CoversClass(ListBeUsersTool::class)]
final class ListBeUsersToolTest extends AbstractFunctionalTestCase
{
    private const SECRET_HASH = '$2y$12$THISisAcrackableHASHmarker0000000000000000000000000';

    private const SECRET_MFA = '{"totp":"JBSWY3DPEHPK3PXP-secret-seed-marker"}';

    private ListBeUsersTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new ListBeUsersTool($connectionPool);

        $connection = $connectionPool->getConnectionForTable('be_users');
        self::assertInstanceOf(Connection::class, $connection);
        $this->insertUser($connection, 10, 'alice', [
            'realName' => 'Alice Admin',
            'email'    => 'alice@example.com',
            'admin'    => 1,
            'lastlogin' => 1700000000,
        ]);
        $this->insertUser($connection, 11, 'bob', [
            'realName' => 'Bob Editor',
            'email'    => 'bob@example.com',
            'admin'    => 0,
            'disable'  => 1,
        ]);
        // Soft-deleted — must be excluded from the listing.
        $this->insertUser($connection, 12, 'carol', ['deleted' => 1]);
    }

    #[Test]
    public function getSpecDeclaresListBeUsersFunctionWithoutParameters(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('list_be_users', $spec->name);
        // A parameterless tool must expose its (empty) `properties` as a JSON
        // object `{}`, not an array `[]` — strict providers (Ollama) reject
        // `[]`. ToolSpec normalises the empty case to a stdClass at construction.
        self::assertEquals(new \stdClass(), $spec->parameters['properties'] ?? null);
        self::assertStringContainsString(
            '"properties":{}',
            json_encode($spec->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function executeListsLiveUsersWithProfileColumns(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringContainsString('Backend users (2):', $output);
        self::assertStringContainsString('[10] alice | Alice Admin <alice@example.com> | admin=1 disabled=0', $output);
        self::assertStringContainsString('[11] bob | Bob Editor <bob@example.com> | admin=0 disabled=1', $output);
        self::assertStringContainsString('lastlogin=2023-11-14 22:13', $output);
        self::assertStringContainsString('lastlogin=never', $output);
    }

    #[Test]
    public function executeExcludesSoftDeletedUsers(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringNotContainsString('carol', $output);
    }

    #[Test]
    public function executeNeverLeaksPasswordHashOrMfaSecret(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringNotContainsString(self::SECRET_HASH, $output);
        self::assertStringNotContainsString(self::SECRET_MFA, $output);
        self::assertStringNotContainsString('$2y$', $output);
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
    private function insertUser(Connection $connection, int $uid, string $username, array $overrides = []): void
    {
        $connection->insert('be_users', [
            'uid'       => $uid,
            'pid'       => 0,
            'username'  => $username,
            'password'  => self::SECRET_HASH,
            'mfa'       => self::SECRET_MFA,
            'realName'  => $overrides['realName'] ?? '',
            'email'     => $overrides['email'] ?? '',
            'admin'     => $overrides['admin'] ?? 0,
            'disable'   => $overrides['disable'] ?? 0,
            'lastlogin' => $overrides['lastlogin'] ?? 0,
            'deleted'   => $overrides['deleted'] ?? 0,
        ]);
    }
}
