<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Shared storage gate of the FAL tools (ADR-047).
 *
 * The effective storage set is the intersection of the configured allow-list
 * and — for non-admins — the storages the backend user can actually reach
 * through their file mounts ({@see BackendUserAuthentication::getFileStorages()}).
 * Fail-closed: no backend user means no storages, and an empty intersection
 * means every FAL tool answers with its neutral denial.
 */
final readonly class FalStorageGate
{
    /**
     * @param list<int> $allowedStorages sys_file_storage uids the FAL tools may touch
     */
    public function __construct(
        private array $allowedStorages = [1],
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
}
