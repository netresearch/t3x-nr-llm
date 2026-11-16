<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * The inbox of a publication profile: the one folder a client's file may be
 * adopted into (spec 051 A2A-FILE6, ADR-305), named by the administrator as a
 * combined identifier such as `2:/a2a-inbox/`.
 *
 * A target is refused when it is public, which here means any of:
 *
 * - its storage record is marked public (`sys_file_storage.is_public`);
 * - its storage uses the `Local` driver with a relative base path: core takes
 *   that from the web root, so it lies there however the directory links;
 * - its storage uses the `Local` driver with an absolute base path that lies
 *   inside the web root (`Environment::getPublicPath()`) as written or as
 *   resolved, compared with the web root as written and as resolved. Core's
 *   Local driver derives a public URL from the written path; a link can
 *   bring a resolved path into the web root as well;
 * - the folder itself, resolved, lies inside the web root (a folder of the
 *   storage that links there);
 * - the folder is or lies below the storage's processing folder, where core
 *   skips the file mount check, or a `_recycler_` folder.
 *
 * A storage of another driver is taken as its record declares it: it has no
 * path in the web root to compare. Storage 0, core's fallback for the web
 * root, has no record and is refused as unknown, like any storage without
 * one.
 *
 * Asked where a profile is saved ({@see \Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerRecordSaveHook})
 * and again at every call of {@see A2aAdoptClientFileTool}, because the
 * storage record can change without the profile being saved.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFalInbox
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private StorageRepository $storageRepository,
    ) {}

    /**
     * The storage uid and the folder identifier (with a trailing slash) of a
     * combined identifier, or null when it is none.
     *
     * @return array{storage: int, folder: string}|null
     */
    public static function parse(string $combined): ?array
    {
        if (preg_match('#^([0-9]+):(/[^\\\\]*)$#', trim($combined), $matches) !== 1) {
            return null;
        }

        $folder = rtrim($matches[2], '/') . '/';
        if (in_array('..', explode('/', $folder), true)) {
            return null;
        }

        return ['storage' => (int) $matches[1], 'folder' => $folder];
    }

    /**
     * Why a file may not be adopted into this folder, or null when it may as
     * far as the configuration goes; the acting user's rights are asked
     * separately.
     */
    public function refusal(string $combined): ?string
    {
        $target = self::parse($combined);
        if ($target === null) {
            return sprintf('"%s" is not a combined folder identifier such as 2:/a2a-inbox/.', $combined);
        }

        $record = $this->storageRecord($target['storage']);
        if ($record === null) {
            return sprintf('storage %d does not exist.', $target['storage']);
        }

        if (is_numeric($record['is_public'] ?? null) && (int) $record['is_public'] !== 0) {
            return sprintf('storage %d is public.', $target['storage']);
        }

        if ($this->managedByCore($record, $target['folder'])) {
            return sprintf('%s is a processing or recycler folder of storage %d.', $target['folder'], $target['storage']);
        }

        if (($record['driver'] ?? null) === 'Local' && $this->underWebRoot($record, $target['folder'])) {
            return sprintf('%s of storage %d lies in the web root, which the web server serves.', $target['folder'], $target['storage']);
        }

        return null;
    }

    /**
     * Whether the folder is the storage's processing folder, lies below it,
     * or is a recycler folder. The processing folder is read from the record
     * (core's default `_processed_`); one kept in another storage cannot
     * contain this folder.
     *
     * @param array<string, mixed> $record
     */
    private function managedByCore(array $record, string $folder): bool
    {
        if (in_array('_recycler_', explode('/', $folder), true)) {
            return true;
        }

        $processing = is_string($record['processingfolder'] ?? null) ? trim($record['processingfolder']) : '';
        $processing = $processing !== '' ? $processing : '_processed_';
        if (str_contains($processing, ':')) {
            [$storage, $processing] = explode(':', $processing, 2);
            if ((int) $storage !== (is_numeric($record['uid'] ?? null) ? (int) $record['uid'] : 0)) {
                return false;
            }
        }

        $processing = '/' . trim($processing, '/') . '/';

        return str_starts_with(strtolower($folder), strtolower($processing));
    }

    /**
     * Whether a Local storage's base path, or the folder in it, lies in the
     * web root. A base path that cannot be read counts as inside: the check
     * fails closed.
     *
     * @param array<string, mixed> $record
     */
    private function underWebRoot(array $record, string $folder): bool
    {
        try {
            $configuration = $this->storageRepository->createFromRecord($record)->getConfiguration();
        } catch (Throwable) {
            return true;
        }

        $basePath = is_string($configuration['basePath'] ?? null) ? trim($configuration['basePath']) : '';
        // A relative base path is taken from the web root; an empty one is
        // nothing to compare.
        if (($configuration['pathType'] ?? null) === 'relative' || !str_starts_with($basePath, '/')) {
            return true;
        }

        $webRoots = $this->forms(Environment::getPublicPath());
        $paths    = [...$this->forms($basePath), ...$this->forms(rtrim($basePath, '/') . $folder)];
        foreach ($paths as $path) {
            foreach ($webRoots as $webRoot) {
                if (str_starts_with($path . '/', $webRoot . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A path as written (canonicalised, links kept) and as resolved, where it
     * exists.
     *
     * @return list<string>
     */
    private function forms(string $path): array
    {
        $forms = [rtrim(PathUtility::getCanonicalPath($path), '/')];
        $real  = realpath($path);
        if ($real !== false) {
            $forms[] = rtrim($real, '/');
        }

        return $forms;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storageRecord(int $uid): ?array
    {
        $builder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $builder->getRestrictions()->removeAll();
        $record = $builder->select('*')->from('sys_file_storage')
            ->where(
                $builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)),
                $builder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($record) ? $record : null;
    }
}
