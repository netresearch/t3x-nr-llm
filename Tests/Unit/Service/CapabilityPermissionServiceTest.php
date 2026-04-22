<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\CapabilityPermissionService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(CapabilityPermissionService::class)]
class CapabilityPermissionServiceTest extends AbstractUnitTestCase
{
    private CapabilityPermissionService $subject;

    private mixed $originalGlobalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CapabilityPermissionService();
        $this->originalGlobalUser = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        if ($this->originalGlobalUser !== null) {
            $GLOBALS['BE_USER'] = $this->originalGlobalUser;
        } else {
            unset($GLOBALS['BE_USER']);
        }
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // permissionKey / permissionString helpers
    // ──────────────────────────────────────────────

    #[Test]
    public function permissionKeyUsesCapabilityValue(): void
    {
        self::assertSame('capability_vision', CapabilityPermissionService::permissionKey(ModelCapability::VISION));
        self::assertSame('capability_chat', CapabilityPermissionService::permissionKey(ModelCapability::CHAT));
        self::assertSame('capability_json_mode', CapabilityPermissionService::permissionKey(ModelCapability::JSON_MODE));
    }

    #[Test]
    public function permissionStringPrefixesNamespace(): void
    {
        self::assertSame('nrllm:capability_vision', CapabilityPermissionService::permissionString(ModelCapability::VISION));
        self::assertSame('nrllm', CapabilityPermissionService::PERM_NAMESPACE);
    }

    /**
     * @return list<array{ModelCapability, string}>
     */
    public static function allCapabilitiesProvider(): array
    {
        return array_map(
            static fn(ModelCapability $c): array => [$c, 'nrllm:capability_' . $c->value],
            ModelCapability::cases(),
        );
    }

    #[Test]
    #[DataProvider('allCapabilitiesProvider')]
    public function everyEnumCaseProducesAStablePermissionString(ModelCapability $capability, string $expected): void
    {
        self::assertSame($expected, CapabilityPermissionService::permissionString($capability));
    }

    // ──────────────────────────────────────────────
    // isAllowed
    // ──────────────────────────────────────────────

    #[Test]
    public function allowsWhenNoBackendUserPresent(): void
    {
        // No $GLOBALS['BE_USER'], no explicit arg -> CLI/frontend path
        self::assertTrue($this->subject->isAllowed(ModelCapability::VISION));
    }

    #[Test]
    public function allowsWhenGlobalIsNotABackendUser(): void
    {
        $GLOBALS['BE_USER'] = new stdClass();

        self::assertTrue($this->subject->isAllowed(ModelCapability::CHAT));
    }

    #[Test]
    public function allowsAdminBypassingTheCheckMethod(): void
    {
        $user = $this->makeBackendUser(isAdmin: true);
        $user->expects(self::never())->method('check');

        self::assertTrue($this->subject->isAllowed(ModelCapability::VISION, $user));
    }

    #[Test]
    public function delegatesToBackendUserCheckForNonAdmin(): void
    {
        $user = $this->makeBackendUser(isAdmin: false);
        $user->expects(self::once())
            ->method('check')
            ->with('custom_options', 'nrllm:capability_vision')
            ->willReturn(true);

        self::assertTrue($this->subject->isAllowed(ModelCapability::VISION, $user));
    }

    #[Test]
    public function returnsFalseWhenBackendUserDeniesCheck(): void
    {
        $user = $this->makeBackendUser(isAdmin: false);
        $user->method('check')->willReturn(false);

        self::assertFalse($this->subject->isAllowed(ModelCapability::TOOLS, $user));
    }

    #[Test]
    public function fallsBackToGlobalsBeUserWhenNoArgumentPassed(): void
    {
        $user = $this->makeBackendUser(isAdmin: false);
        $user->method('check')
            ->with('custom_options', 'nrllm:capability_embeddings')
            ->willReturn(true);
        $GLOBALS['BE_USER'] = $user;

        self::assertTrue($this->subject->isAllowed(ModelCapability::EMBEDDINGS));
    }

    #[Test]
    public function explicitArgumentOverridesGlobalUser(): void
    {
        $globalUser = $this->makeBackendUser(isAdmin: false);
        $globalUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $globalUser;

        $explicitUser = $this->makeBackendUser(isAdmin: true);
        self::assertTrue($this->subject->isAllowed(ModelCapability::CHAT, $explicitUser));
    }

    private function makeBackendUser(bool $isAdmin): BackendUserAuthentication&MockObject
    {
        $mock = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['check', 'isAdmin'])
            ->getMock();
        $mock->method('isAdmin')->willReturn($isAdmin);
        // Fallback record in case isAdmin() is not present on older stubs
        $mock->user = ['admin' => $isAdmin ? 1 : 0];
        return $mock;
    }
}
