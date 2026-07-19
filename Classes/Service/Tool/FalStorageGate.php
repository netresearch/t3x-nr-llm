<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\StorageRepository;

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
final readonly class FalStorageGate
{
    /**
     * @param list<int> $allowedStorages sys_file_storage uids the FAL tools may touch
     */
    public function __construct(
        private array $allowedStorages = [1],
        // Optional so the storage-set-only unit construction keeps working;
        // autowired in production and required for isFileAccessible().
        private ?StorageRepository $storageRepository = null,
    ) {}

    /**
     * The storage uids the acting user may touch, in allow-list order.
     *
     * @return list<int>
     */
    public function effectiveStorages(?BackendUserAuthentication $user): array
    {
        if ($user === null) {
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
     * The storage object is resolved inside the running backend request, where
     * the core StoragePermissionsAspect has attached the acting user's file
     * mounts; with evaluatePermissions on, checkFileActionPermission('read')
     * then returns false for a file outside those mounts. NB getFile() alone
     * does NOT assert — it only resolves the record — so the explicit
     * permission check is load-bearing. Admins bypass mount checks.
     */
    public function isFileAccessible(?BackendUserAuthentication $user, int $storageUid, string $identifier): bool
    {
        if (!$this->isAllowed($user, $storageUid) || $user === null || $identifier === '') {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->storageRepository === null) {
            return false;
        }

        try {
            $storage = $this->storageRepository->findByUid($storageUid);
            if ($storage === null) {
                return false;
            }
            // findByUid returns a request-shared, cached storage; restore its
            // prior evaluatePermissions so this check does not leak into other
            // consumers of the same instance in the request.
            $previous = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(true);
            try {
                return $storage->checkFileActionPermission('read', $storage->getFile($identifier));
            } finally {
                $storage->setEvaluatePermissions($previous);
            }
        } catch (Throwable) {
            return false;
        }
    }
}
