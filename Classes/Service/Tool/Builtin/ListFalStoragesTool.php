<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Throwable;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * The entry point of the FAL tool family (ADR-047): which file storages may
 * this run touch at all?
 *
 * Lists the effective storages ({@see FalStorageGate}: configured allow-list,
 * intersected with the acting non-admin's file mounts) with uid, name, driver
 * and capability flags. The server-side base path is deliberately NOT part of
 * the output — tool results egress to the external provider, and filesystem
 * layout is nobody's business there.
 */
final readonly class ListFalStoragesTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;

    public function __construct(
        private StorageRepository $storageRepository,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_fal_storages',
            'List the file storages (FAL) accessible to this run: uid, name, driver and status flags. '
            . 'Use the uid with browse_fal_folder, search_fal_files or find_missing_files.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $user  = $this->actingBackendUser();
        $lines = [];
        foreach ($this->storageGate->effectiveStorages($user) as $uid) {
            try {
                $storage = $this->storageRepository->findByUid($uid);
            } catch (Throwable) {
                $storage = null;
            }
            if ($storage === null) {
                continue;
            }

            $flags = [];
            $flags[] = $storage->isOnline() ? 'online' : 'offline';
            if ($storage->isBrowsable()) {
                $flags[] = 'browsable';
            }
            if ($storage->isPublic()) {
                $flags[] = 'public';
            }

            $lines[] = sprintf(
                '- [%d] %s (driver %s; %s)',
                $storage->getUid(),
                $storage->getName(),
                $storage->getDriverType(),
                implode(', ', $flags),
            );
        }

        if ($lines === []) {
            return 'No accessible file storages.';
        }

        return sprintf("Accessible file storages (%d):\n", count($lines)) . implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; the storage gate intersects with file mounts.
        return false;
    }

    public function getGroup(): string
    {
        return 'files';
    }
}
