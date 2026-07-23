<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The crux of ADR-083 tool authorization: an actor's uid becomes a LIVE backend
 * user whose privilege comes from the fresh database record, identically on the
 * synchronous and worker paths — never mintable from the serialised actor.
 */
#[CoversClass(ActingBackendUserResolver::class)]
final class ActingBackendUserResolverTest extends AbstractFunctionalTestCase
{
    private ActingBackendUserResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // BeUsers.csv: uid 1 = admin, uid 2 = non-admin editor (both enabled).
        $this->importFixture('BeUsers.csv');
        $this->resolver = new ActingBackendUserResolver();
    }

    #[Test]
    public function resolvesAnAdminUidToALiveAdminUser(): void
    {
        $user = $this->resolver->resolve(AiActorContext::backendUser(1));

        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertTrue($user->isAdmin());
    }

    #[Test]
    public function resolvesANonAdminUidToAScopedUser(): void
    {
        // The admin flag comes from the DB record, NOT the actor: even if a
        // tampered actor claimed isAdmin, the resolved user is the real editor.
        $user = $this->resolver->resolve(AiActorContext::backendUser(2, isAdmin: true));

        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertFalse($user->isAdmin());
    }

    #[Test]
    public function failsClosedForAnonymousServiceAccountAndUnknownUid(): void
    {
        self::assertNull($this->resolver->resolve(AiActorContext::anonymous()));
        self::assertNull($this->resolver->resolve(AiActorContext::serviceAccount('nightly-import')));
        self::assertNull($this->resolver->resolve(AiActorContext::backendUser(0)));
        self::assertNull($this->resolver->resolve(AiActorContext::backendUser(99999)));
    }

    #[Test]
    public function failsClosedForADisabledUser(): void
    {
        // Enable-fields are honoured: a disabled be_user must not resolve, so a
        // run queued by a since-disabled user cannot keep acting.
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('be_users');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('be_users', [
            'uid'      => 3,
            'pid'      => 0,
            'username' => 'disabled-editor',
            'admin'    => 0,
            'disable'  => 1,
            'deleted'  => 0,
        ]);

        self::assertNull($this->resolver->resolve(AiActorContext::backendUser(3)));
    }
}
