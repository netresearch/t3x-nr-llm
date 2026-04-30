<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Budget;

use Netresearch\NrLlm\Service\Budget\BackendUserContextResolver;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(BackendUserContextResolver::class)]
final class BackendUserContextResolverTest extends AbstractUnitTestCase
{
    private mixed $previousBeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }
        parent::tearDown();
    }

    #[Test]
    public function returnsNullWhenBeUserGlobalIsAbsent(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertNull((new BackendUserContextResolver())->resolveBeUserUid());
    }

    #[Test]
    public function returnsNullWhenBeUserIsNotABackendUserAuthentication(): void
    {
        // Defensive — if a third-party hooks something else into the
        // global, the resolver must not crash. CLI / scheduler contexts
        // sometimes leave non-typed sentinels here.
        $GLOBALS['BE_USER'] = new stdClass();

        self::assertNull((new BackendUserContextResolver())->resolveBeUserUid());
    }

    #[Test]
    public function returnsTheBeUserUidWhenSet(): void
    {
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 7, 'username' => 'editor'];
        $GLOBALS['BE_USER'] = $beUser;

        self::assertSame(7, (new BackendUserContextResolver())->resolveBeUserUid());
    }

    #[Test]
    public function returnsNullWhenUidIsZero(): void
    {
        // uid === 0 is the documented "anonymous / not logged in"
        // marker in TYPO3. The resolver maps it to null so the
        // BudgetMiddleware does not run a per-user budget check
        // against an anonymous principal.
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 0];
        $GLOBALS['BE_USER'] = $beUser;

        self::assertNull((new BackendUserContextResolver())->resolveBeUserUid());
    }

    #[Test]
    public function returnsNullWhenUidIsNotAnInt(): void
    {
        // Belt-and-braces — TYPO3 typing says int|null, but a CSV / DB
        // round-trip from a misbehaving extension could hand us a
        // string. Guard against it rather than coercing.
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->user = ['uid' => '42'];
        $GLOBALS['BE_USER'] = $beUser;

        self::assertNull((new BackendUserContextResolver())->resolveBeUserUid());
    }

    #[Test]
    public function returnsNullWhenUserArrayIsMissingUid(): void
    {
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->user = ['username' => 'editor'];
        $GLOBALS['BE_USER'] = $beUser;

        self::assertNull((new BackendUserContextResolver())->resolveBeUserUid());
    }
}
