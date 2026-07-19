<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Unit tests for the shared read-access policy (ADR-042).
 *
 * Load-bearing: the sensitive-table denylist holds even for admins, the
 * sensitive-field pattern catches credential-ish segments without false
 * positives on editorial columns, and every gate fails closed without a
 * backend user.
 */
#[CoversClass(TableReadAccessService::class)]
final class TableReadAccessServiceTest extends TestCase
{
    private TableReadAccessService $service;

    private mixed $tcaBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service   = new TableReadAccessService();
        $this->tcaBackup = $GLOBALS['TCA'] ?? null;

        $GLOBALS['TCA'] = [
            'pages'      => ['ctrl' => ['label' => 'title'], 'columns' => ['title' => []]],
            'tt_content' => ['ctrl' => ['label' => 'header'], 'columns' => ['header' => []]],
            'be_users'   => ['ctrl' => ['label' => 'username'], 'columns' => ['username' => []]],
            'tx_nrllm_provider' => ['ctrl' => ['label' => 'name'], 'columns' => ['name' => []]],
            'tx_secret_admin'   => ['ctrl' => ['label' => 'name', 'adminOnly' => true], 'columns' => ['name' => []]],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        parent::tearDown();
    }

    #[Test]
    public function failsClosedWithoutBackendUser(): void
    {
        self::assertFalse($this->service->canReadTable(null, 'pages'));
    }

    #[Test]
    public function sensitiveTablesAreDeniedEvenForAdmins(): void
    {
        $admin = $this->createMock(BackendUserAuthentication::class);
        $admin->method('isAdmin')->willReturn(true);

        self::assertFalse($this->service->canReadTable($admin, 'be_users'));
        self::assertFalse($this->service->canReadTable($admin, 'tx_nrllm_provider'));
        self::assertTrue($this->service->canReadTable($admin, 'pages'));
    }

    #[Test]
    public function unknownTableIsDenied(): void
    {
        $admin = $this->createMock(BackendUserAuthentication::class);
        $admin->method('isAdmin')->willReturn(true);

        self::assertFalse($this->service->canReadTable($admin, 'tx_does_not_exist'));
    }

    #[Test]
    public function nonAdminNeedsTablesSelectRight(): void
    {
        $editor = $this->createMock(BackendUserAuthentication::class);
        $editor->method('isAdmin')->willReturn(false);
        $editor->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $value === 'tt_content',
        );

        self::assertTrue($this->service->canReadTable($editor, 'tt_content'));
        self::assertFalse($this->service->canReadTable($editor, 'pages'));
    }

    #[Test]
    public function nonAdminNeverReadsAdminOnlyTables(): void
    {
        $editor = $this->createMock(BackendUserAuthentication::class);
        $editor->method('isAdmin')->willReturn(false);
        $editor->method('check')->willReturn(true);

        self::assertFalse($this->service->canReadTable($editor, 'tx_secret_admin'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fieldProvider(): array
    {
        return [
            'password'          => ['password', true],
            'api_key'           => ['api_key', true],
            'identifier_hash'   => ['identifier_hash', true],
            'ses_token'         => ['ses_token', true],
            'mfa'               => ['mfa', true],
            'salt'              => ['salt', true],
            // Concatenated / camelCase / digit-suffixed forms the segment
            // pattern misses, caught by the substring pattern:
            'apikey'            => ['apikey', true],
            'accessToken'       => ['accessToken', true],
            'password2'         => ['password2', true],
            'clientSecret'      => ['clientSecret', true],
            'refreshtoken'      => ['refreshtoken', true],
            'apitoken'          => ['apitoken', true],
            // Digit-suffixed segment secrets (confirm-style columns):
            'secret2'           => ['secret2', true],
            'token2'            => ['token2', true],
            'key2'              => ['key2', true],
            // No false positives on editorial columns:
            'author'            => ['author', false],
            'author_email'      => ['author_email', false],
            'title'             => ['title', false],
            'keywords'          => ['keywords', false],
            'monkey'            => ['monkey', false],
            // Bare secret/token as substrings must NOT flag these:
            'secretary'         => ['secretary', false],
            'tokenizer'         => ['tokenizer', false],
        ];
    }

    #[Test]
    #[DataProvider('fieldProvider')]
    public function sensitiveFieldPatternMatchesCredentialSegments(string $field, bool $expected): void
    {
        self::assertSame($expected, $this->service->isSensitiveField($field));
    }

    #[Test]
    public function filterSensitiveFieldsDropsCredentialColumns(): void
    {
        $fields = $this->service->filterSensitiveFields(['uid', 'title', 'password', 'api_key', 'author']);

        self::assertSame(['uid', 'title', 'author'], $fields);
    }
}
