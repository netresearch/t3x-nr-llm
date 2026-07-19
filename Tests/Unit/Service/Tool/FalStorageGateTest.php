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
use TYPO3\CMS\Core\Resource\StorageRepository;

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

    #[Test]
    public function adminReachesAnyFileInAllowedStorageWithoutMountCheck(): void
    {
        $admin = $this->userWith(isAdmin: true, storageUids: []);
        // No StorageRepository needed — admins bypass the mount resolution.
        $gate = new FalStorageGate([1]);

        self::assertTrue($gate->isFileAccessible($admin, 1, '/any/where/file.pdf'));
        // Storage outside the allow-list is still denied.
        self::assertFalse($gate->isFileAccessible($admin, 2, '/x.pdf'));
    }

    // The actual file-mount enforcement (getFile resolves, then
    // checkFileActionPermission asserts against the acting user's mounts) is
    // exercised end-to-end in FalToolsFileMountTest against a real storage +
    // file mount — stubbing the storage here would only assert fabricated
    // behaviour. The unit tests below cover the pre-resolution guard logic.

    #[Test]
    public function fileInDisallowedStorageIsDeniedBeforeResolution(): void
    {
        $editor = $this->userWith(isAdmin: false, storageUids: [7]); // no mount into storage 1
        $gate   = new FalStorageGate([1], $this->storageRepositoryReturning(null));

        self::assertFalse($gate->isFileAccessible($editor, 1, '/x.pdf'));
    }

    #[Test]
    public function nonAdminIsDeniedWhenStorageRepositoryIsAbsent(): void
    {
        $editor = $this->userWith(isAdmin: false, storageUids: [1]);
        $gate   = new FalStorageGate([1]); // no repository → fail-closed

        self::assertFalse($gate->isFileAccessible($editor, 1, '/x.pdf'));
    }

    #[Test]
    public function emptyIdentifierIsDenied(): void
    {
        $editor = $this->userWith(isAdmin: false, storageUids: [1]);
        $gate   = new FalStorageGate([1], $this->storageRepositoryReturning(self::createStub(ResourceStorage::class)));

        self::assertFalse($gate->isFileAccessible($editor, 1, ''));
    }

    private function storageRepositoryReturning(?ResourceStorage $storage): StorageRepository
    {
        $repository = self::createStub(StorageRepository::class);
        $repository->method('findByUid')->willReturn($storage);

        return $repository;
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
