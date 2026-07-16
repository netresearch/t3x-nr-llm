<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * The shared FAL storage gate (ADR-047) must stay fail-closed: no user
 * means no storages, admins get the configured allow-list verbatim, and
 * non-admins only the intersection with their file-mount storages —
 * in allow-list order.
 */
#[CoversClass(FalStorageGate::class)]
final class FalStorageGateTest extends AbstractUnitTestCase
{
    #[Test]
    public function missingUserYieldsNoStorages(): void
    {
        $gate = new FalStorageGate([1, 2]);

        self::assertSame([], $gate->effectiveStorages(null));
        self::assertFalse($gate->isAllowed(null, 1));
    }

    #[Test]
    public function adminGetsConfiguredAllowListVerbatim(): void
    {
        $admin = $this->userWith(isAdmin: true, storageUids: []);

        $gate = new FalStorageGate([3, 1]);

        self::assertSame([3, 1], $gate->effectiveStorages($admin));
        self::assertTrue($gate->isAllowed($admin, 3));
        self::assertFalse($gate->isAllowed($admin, 2));
    }

    #[Test]
    public function nonAdminGetsIntersectionInAllowListOrder(): void
    {
        // File mounts reach storages 2 and 3; the allow-list permits 3, 1, 2.
        $editor = $this->userWith(isAdmin: false, storageUids: [2, 3]);

        $gate = new FalStorageGate([3, 1, 2]);

        self::assertSame([3, 2], $gate->effectiveStorages($editor));
        self::assertTrue($gate->isAllowed($editor, 2));
        self::assertFalse($gate->isAllowed($editor, 1));
    }

    #[Test]
    public function nonAdminWithoutReachableStoragesIsDenied(): void
    {
        $editor = $this->userWith(isAdmin: false, storageUids: [7]);

        $gate = new FalStorageGate([1]);

        self::assertSame([], $gate->effectiveStorages($editor));
        self::assertFalse($gate->isAllowed($editor, 1));
        self::assertFalse($gate->isAllowed($editor, 7));
    }

    /**
     * @param list<int> $storageUids
     */
    private function userWith(bool $isAdmin, array $storageUids): BackendUserAuthentication
    {
        $storages = [];
        foreach ($storageUids as $uid) {
            $storage = self::createStub(ResourceStorage::class);
            $storage->method('getUid')->willReturn($uid);
            $storages[] = $storage;
        }

        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->method('getFileStorages')->willReturn($storages);

        return $user;
    }
}
