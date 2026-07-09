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
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Directory listing of one FAL folder (ADR-047).
 *
 * Subfolders (with file count) first, then files with human-readable size and
 * MIME type — enough for the model to navigate a storage without ever seeing
 * a server path: all identifiers are storage-relative, resolution goes
 * exclusively through the storage API ({@see \TYPO3\CMS\Core\Resource\ResourceStorage::getFolder()}),
 * and any resolution failure collapses into one neutral denial, so folder
 * names outside the gate cannot be probed.
 */
final readonly class BrowseFalFolderTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Folder not found or not permitted.';

    /** Upper bound on listed entries (folders + files). */
    private const MAX_ENTRIES = 100;

    public function __construct(
        private StorageRepository $storageRepository,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'browse_fal_folder',
            'List one folder of a FAL file storage: subfolders (with file count) and files with size and '
            . 'MIME type. Identifiers are storage-relative. Use list_fal_storages to discover storages.',
            [
                'type'       => 'object',
                'properties' => [
                    'folder' => [
                        'type'        => 'string',
                        'description' => 'Storage-relative folder identifier (default "/", the storage root).',
                    ],
                    'storage' => [
                        'type'        => 'integer',
                        'description' => 'sys_file_storage uid (default: the first accessible storage).',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $user      = $this->actingBackendUser();
        $effective = $this->storageGate->effectiveStorages($user);
        if ($effective === []) {
            return self::NOT_PERMITTED;
        }

        $storageUid = self::toInt($arguments['storage'] ?? ($effective[0] ?? 0));
        if (!in_array($storageUid, $effective, true)) {
            return self::NOT_PERMITTED;
        }

        $folderId = trim(self::toStr($arguments['folder'] ?? '/'));
        if ($folderId === '') {
            $folderId = '/';
        }

        try {
            $storage = $this->storageRepository->findByUid($storageUid);
            if ($storage === null || !$storage->isOnline()) {
                return self::NOT_PERMITTED;
            }
            $folder = $storage->getFolder($folderId);

            $lines   = [];
            $count   = 0;
            $skipped = 0;

            foreach ($storage->getFoldersInFolder($folder) as $subfolder) {
                if ($count >= self::MAX_ENTRIES) {
                    ++$skipped;
                    continue;
                }
                $lines[] = sprintf(
                    '- %s/ (%d files)',
                    $subfolder->getName(),
                    $storage->countFilesInFolder($subfolder),
                );
                ++$count;
            }

            foreach ($storage->getFilesInFolder($folder) as $file) {
                if ($count >= self::MAX_ENTRIES) {
                    ++$skipped;
                    continue;
                }
                $lines[] = sprintf(
                    '- %s (%s, %s)',
                    $file->getName(),
                    $file->getMimeType(),
                    $this->humanSize($file->getSize()),
                );
                ++$count;
            }
        } catch (Throwable) {
            // Neutral by design: a missing folder, a folder outside the
            // storage and a driver error are indistinguishable to the model.
            return self::NOT_PERMITTED;
        }

        if ($lines === []) {
            return sprintf('Folder %s of storage %d is empty.', $folder->getIdentifier(), $storageUid);
        }

        $header = sprintf(
            'Folder %s of storage %d (%d entries%s):',
            $folder->getIdentifier(),
            $storageUid,
            $count,
            $skipped > 0 ? sprintf(', %d more not shown', $skipped) : '',
        );

        return $header . "\n" . implode("\n", $lines);
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

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MiB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
