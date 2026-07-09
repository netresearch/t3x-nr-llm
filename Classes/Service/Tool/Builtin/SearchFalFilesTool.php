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
 * Substring search over managed files (ADR-047): file name plus the
 * default-language title/alternative from `sys_file_metadata`.
 *
 * Bound to the effective storages of {@see FalStorageGate}; the model-chosen
 * query is LIKE-escaped so `%`/`_` are matched literally, results are capped,
 * and missing files are excluded (see find_missing_files for those).
 * Identifiers are storage-relative (`storage:/path/name`), never server paths.
 */
final readonly class SearchFalFilesTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'search_fal_files',
            'Search managed files (FAL) by substring in file name, title or alternative text. Returns '
            . 'uid, storage-relative identifier, MIME type and size; use read_fal_asset_meta(uid) for detail.',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Substring to search for (matched literally, case-insensitive).',
                    ],
                    'storage' => [
                        'type'        => 'integer',
                        'description' => 'Optional: restrict to one sys_file_storage uid.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum results (default 20, capped at 50).',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $query = trim(self::toStr($arguments['query'] ?? ''));
        if ($query === '') {
            return 'Error: "query" is required.';
        }

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

        // sys_file has no enable columns; metadata is language-pinned below
        // and the storage gate is the access boundary (cf. read_fal_asset_meta).
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();

        $like = '%' . $queryBuilder->escapeLikeWildcards($query) . '%';

        $rows = $queryBuilder
            ->select('f.uid', 'f.storage', 'f.identifier', 'f.mime_type', 'f.size')
            ->from('sys_file', 'f')
            ->leftJoin('f', 'sys_file_metadata', 'm', (string)$queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('m.file', $queryBuilder->quoteIdentifier('f.uid')),
                $queryBuilder->expr()->eq('m.sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            ))
            ->where(
                $queryBuilder->expr()->in(
                    'f.storage',
                    $queryBuilder->createNamedParameter($effective, Connection::PARAM_INT_ARRAY),
                ),
                $queryBuilder->expr()->eq('f.missing', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('f.name', $queryBuilder->createNamedParameter($like)),
                    $queryBuilder->expr()->like('m.title', $queryBuilder->createNamedParameter($like)),
                    $queryBuilder->expr()->like('m.alternative', $queryBuilder->createNamedParameter($like)),
                ),
            )
            ->orderBy('f.name')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return sprintf('No files match "%s" in the accessible storages.', $query);
        }

        $lines = [sprintf('Files matching "%s" (%d, capped at %d):', $query, count($rows), $limit)];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- [%d] %d:%s (%s, %d bytes)',
                self::toInt($row['uid'] ?? 0),
                self::toInt($row['storage'] ?? 0),
                self::toStr($row['identifier'] ?? ''),
                self::toStr($row['mime_type'] ?? ''),
                self::toInt($row['size'] ?? 0),
            );
        }
        $lines[] = 'Use read_fal_asset_meta(uid) for title/alternative detail.';

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
