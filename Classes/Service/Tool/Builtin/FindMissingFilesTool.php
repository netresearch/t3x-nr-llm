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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Index records whose physical file is gone (ADR-047): `sys_file` rows with
 * `missing = 1` — the classic "broken image on the page" diagnosis.
 *
 * Bound to the effective storages of {@see FalStorageGate}; identifiers are
 * storage-relative, output is capped, and the total count is always reported
 * so a capped listing cannot read as a complete one.
 */
final readonly class FindMissingFilesTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    /**
     * Non-admins are mount-filtered in PHP after the query; scan up to this
     * many rows so an out-of-mount-heavy window does not starve the in-mount
     * result set, then cap the accessible subset to the requested limit.
     */
    private const MOUNT_SCAN_CAP = 1000;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'find_missing_files',
            'List managed files (FAL) whose physical file is missing on disk (sys_file.missing = 1) — '
            . 'the cause of broken images/downloads. Returns uid, storage-relative identifier and last '
            . 'known size; use get_fal_references(uid) to see where a missing file is still used.',
            [
                'type'       => 'object',
                'properties' => [
                    'storage' => [
                        'type'        => 'integer',
                        'description' => 'Optional: restrict to one sys_file_storage uid.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum results (default 20, capped at 50).',
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
            return 'No accessible file storages.';
        }

        $storageFilter = self::toInt($arguments['storage'] ?? 0);
        if ($storageFilter > 0) {
            $effective = in_array($storageFilter, $effective, true) ? [$storageFilter] : [];
            if ($effective === []) {
                return 'No accessible file storages.';
            }
        }

        $limit = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $isAdmin = $user !== null && $user->isAdmin();

        $countQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $countQuery->getRestrictions()->removeAll();
        $total = self::toInt($countQuery
            ->count('uid')
            ->from('sys_file')
            ->where(
                $countQuery->expr()->eq('missing', $countQuery->createNamedParameter(1, Connection::PARAM_INT)),
                $countQuery->expr()->in(
                    'storage',
                    $countQuery->createNamedParameter($effective, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->executeQuery()
            ->fetchOne());

        if ($total === 0) {
            return 'No missing files in the accessible storages.';
        }

        $listQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $listQuery->getRestrictions()->removeAll();
        $rows = $listQuery
            ->select('uid', 'storage', 'identifier', 'size')
            ->from('sys_file')
            ->where(
                $listQuery->expr()->eq('missing', $listQuery->createNamedParameter(1, Connection::PARAM_INT)),
                $listQuery->expr()->in(
                    'storage',
                    $listQuery->createNamedParameter($effective, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->orderBy('storage')
            ->addOrderBy('identifier')
            ->setMaxResults($isAdmin ? $limit : self::MOUNT_SCAN_CAP)
            ->executeQuery()
            ->fetchAllAssociative();

        // effectiveStorages() only gates the storage; drop identifiers outside
        // the acting user's file mounts so a subfolder-mounted non-admin does
        // not learn missing-file paths elsewhere in the storage, then cap to the
        // requested limit (the DB scanned a wider window above).
        if (!$isAdmin) {
            $rows = array_slice(array_values(array_filter(
                $rows,
                fn(array $row): bool => $this->storageGate->isFileAccessible(
                    $user,
                    self::toInt($row['storage'] ?? 0),
                    self::toStr($row['identifier'] ?? ''),
                ),
            )), 0, $limit);
        }

        if ($rows === []) {
            return 'No missing files in the accessible storages.';
        }

        // The storage-wide $total is only meaningful (and non-leaking) for an
        // admin; a non-admin sees just the count of files within their mounts.
        $lines = [$isAdmin
            ? sprintf('%d missing file%s (showing %d):', $total, $total === 1 ? '' : 's', count($rows))
            : sprintf('%d missing file%s shown:', count($rows), count($rows) === 1 ? '' : 's')];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- [%d] %d:%s (last known size %d bytes)',
                self::toInt($row['uid'] ?? 0),
                self::toInt($row['storage'] ?? 0),
                self::toStr($row['identifier'] ?? ''),
                self::toInt($row['size'] ?? 0),
            );
        }

        return implode("\n", $lines);
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
