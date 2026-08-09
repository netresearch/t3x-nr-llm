<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Doctrine\DBAL\Exception as DbalException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared storage gate of the FAL tools (ADR-047).
 *
 * The effective storage set is the intersection of the configured allow-list
 * and — for non-admins — the storages the backend user can actually reach
 * through their file mounts ({@see BackendUserAuthentication::getFileStorages()}).
 * Fail-closed: no backend user means no storages, and an empty intersection
 * means every FAL tool answers with its neutral denial.
 *
 * {@see self::effectiveStorages()} gates at STORAGE granularity only. A
 * non-admin whose file mount points at a subfolder still has the whole storage
 * in that set, so tools that surface individual files must additionally gate
 * each file with {@see self::isFileAccessible()}, which enforces the acting
 * user's file-mount boundaries via the storage API.
 */
final class FalStorageGate
{
    /**
     * Storages built for one acting user, keyed `<userUid>:<storageUid>`. Two
     * of the three consumers call {@see self::isFileAccessible()} once per
     * result row, and each miss builds a storage driver.
     *
     * Not `readonly` on the class for this alone: the cache is the reason.
     *
     * @var array<string, ResourceStorage|null>
     */
    private array $userStorages = [];

    /**
     * @param list<int> $allowedStorages sys_file_storage uids the FAL tools may touch
     */
    public function __construct(
        private readonly array $allowedStorages = [1],
        // Optional so the storage-set-only unit construction keeps working;
        // autowired in production and required for isFileAccessible().
        private readonly ?StorageRepository $storageRepository = null,
        private readonly ?ConnectionPool $connectionPool = null,
    ) {}

    /**
     * The storage uids the acting user may touch, in allow-list order.
     *
     * @return list<int>
     */
    public function effectiveStorages(?BackendUserAuthentication $user): array
    {
        if (!$user instanceof BackendUserAuthentication) {
            return [];
        }

        if ($user->isAdmin()) {
            return $this->allowedStorages;
        }

        $reachable = [];
        foreach ($user->getFileStorages() as $storage) {
            $reachable[$storage->getUid()] = true;
        }

        return array_values(array_filter(
            $this->allowedStorages,
            static fn(int $uid): bool => isset($reachable[$uid]),
        ));
    }

    public function isAllowed(?BackendUserAuthentication $user, int $storageUid): bool
    {
        return in_array($storageUid, $this->effectiveStorages($user), true);
    }

    /**
     * Whether the acting user may actually reach one file, enforcing file-mount
     * boundaries — not just the storage allow-list. {@see self::isAllowed()}
     * only checks the storage; a non-admin with a subfolder file mount passes
     * that for every file in the storage.
     *
     * The check runs against a storage built FOR `$user`, not against the
     * request-shared instance. `StorageRepository::findByUid()` returns the
     * cached object whose mounts and permissions the core
     * `StoragePermissionsAspect` attached once, for whoever was in
     * `$GLOBALS['BE_USER']` when it was first created — and `ResourceStorage`
     * holds no reference to that global afterwards. On the approval path the
     * ambient user is the APPROVER while the acting user is the run owner
     * (ADR-083), so the shared instance answers for the wrong person in both
     * directions: it permits a write the owner could not make when the
     * approver's mounts are broader, and refuses a legitimate one when they
     * are narrower.
     *
     * NB `getFile()` alone does NOT assert — it only resolves the record — so
     * the explicit permission check is load-bearing. Admins bypass mount
     * checks, which is why they return before any of this.
     */
    public function isFileAccessible(?BackendUserAuthentication $user, int $storageUid, string $identifier): bool
    {
        if (!$this->isAllowed($user, $storageUid) || !$user instanceof BackendUserAuthentication || $identifier === '') {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        try {
            $storage = $this->storageFor($user, $storageUid);
            if (!$storage instanceof ResourceStorage) {
                return false;
            }

            $file = $storage->getFile($identifier);
            if ($file === null) {
                return false;
            }

            return $storage->checkFileActionPermission('read', $file);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A storage carrying `$user`'s own mounts and file permissions, private to
     * this gate.
     *
     * `createFromRecord()` is the seam that makes this possible: it builds a
     * storage WITHOUT dispatching `AfterResourceStorageInitializationEvent`,
     * so no listener attaches the ambient user's state and nothing lands in
     * `StorageRepository`'s instance cache. What the aspect would have done is
     * done here instead, reading `$user` rather than a global.
     */
    private function storageFor(BackendUserAuthentication $user, int $storageUid): ?ResourceStorage
    {
        $key = $user->getUserId() . ':' . $storageUid;
        if (array_key_exists($key, $this->userStorages)) {
            return $this->userStorages[$key];
        }

        return $this->userStorages[$key] = $this->buildStorageFor($user, $storageUid);
    }

    private function buildStorageFor(BackendUserAuthentication $user, int $storageUid): ?ResourceStorage
    {
        if (!$this->storageRepository instanceof StorageRepository || !$this->connectionPool instanceof ConnectionPool) {
            return null;
        }

        $record = $this->storageRecord($storageUid);
        if ($record === null) {
            return null;
        }

        $storage = $this->storageRepository->createFromRecord($record);
        $storage->setEvaluatePermissions(true);
        $storage->setUserPermissions($this->filePermissionsFor($user, $storageUid));

        foreach ($this->fileMountRecordsFor($user) as $mount) {
            $identifier = is_string($mount['identifier'] ?? null) ? $mount['identifier'] : '';
            if (!str_contains($identifier, ':')) {
                // An identifier without a storage prefix names no storage —
                // the core skips these too rather than guessing a base.
                continue;
            }

            [$base, $path] = GeneralUtility::trimExplode(':', $identifier, false, 2);
            if ((int)$base !== $storageUid) {
                continue;
            }

            try {
                $storage->addFileMount($path, $mount);
            } catch (FolderDoesNotExistException) {
                // A mount pointing at a folder that is gone grants nothing.
                // Skipping it leaves the gate narrower, never wider.
            }
        }

        return $storage;
    }

    /**
     * The file-mount records of THIS user, read directly.
     *
     * `BackendUserAuthentication::getFileMountRecords()` cannot be used here,
     * and the reason is worth stating because it is not obvious: it memoises
     * into the runtime cache under the single key
     * `backendUserAuthenticationFileMountRecords`, with no user in it. The
     * first backend user to ask in a request populates it, and every other
     * user object then receives that user's mounts. Two users in one request
     * is exactly the situation this gate exists for, so the public accessor
     * answers for the wrong person just as the shared storage did.
     *
     * `groupData` is core-internal, and using it is deliberate: it is the only
     * per-user source. If it ever disappears the list is empty, the storage
     * gets no mounts, and the gate denies — loudly, and closed.
     *
     * @return list<array<string, mixed>>
     */
    private function fileMountRecordsFor(BackendUserAuthentication $user): array
    {
        $declared  = $user->groupData['filemounts'] ?? '';
        $mountUids = is_scalar($declared) ? GeneralUtility::intExplode(',', (string)$declared, true) : [];
        if ($mountUids === [] || !$this->connectionPool instanceof ConnectionPool) {
            return [];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_filemounts');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

            $records = $queryBuilder
                ->select('*')
                ->from('sys_filemounts')
                ->where($queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($mountUids, Connection::PARAM_INT_ARRAY),
                ))
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (DbalException) {
            return [];
        }

        return array_values(array_filter($records, is_array(...)));
    }

    /**
     * The user's file permissions for this storage: the global set, overlaid
     * with any `permissions.file.storage.<uid>` User TSconfig. Mirrors what
     * the core aspect computes, for the passed user.
     *
     * @return array<string, bool>
     */
    private function filePermissionsFor(BackendUserAuthentication $user, int $storageUid): array
    {
        $permissions = [];
        foreach ($user->getFilePermissions() as $permission => $value) {
            $permissions[(string)$permission] = (bool)$value;
        }

        // Walked one level at a time: getTSConfig() is typed array<string,
        // mixed>, so every nested offset is mixed to the analyser.
        $section = $user->getTSConfig()['permissions.'] ?? null;
        $files   = is_array($section) ? ($section['file.'] ?? null) : null;
        $storages = is_array($files) ? ($files['storage.'] ?? null) : null;
        $perStorage = is_array($storages) ? ($storages[$storageUid . '.'] ?? null) : null;
        if (is_array($perStorage)) {
            foreach ($perStorage as $permission => $value) {
                $permissions[(string)$permission] = (bool)$value;
            }
        }

        return $permissions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storageRecord(int $storageUid): ?array
    {
        if (!$this->connectionPool instanceof ConnectionPool) {
            return null;
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
            $queryBuilder->getRestrictions()->removeAll();
            $record = $queryBuilder
                ->select('*')
                ->from('sys_file_storage')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($storageUid)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (DbalException) {
            return null;
        }

        return is_array($record) ? $record : null;
    }
}
