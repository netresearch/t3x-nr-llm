<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Example tool: return read-only metadata for a single managed file (FAL).
 *
 * Loads one `sys_file` row by its uid and returns name, MIME type and size,
 * plus title/alternative text from `sys_file_metadata` when present.
 *
 * Security contract (see {@see ToolInterface}): the `uid` argument is
 * model-chosen and therefore injection-steerable, so the lookup is
 * **storage-scoped** — a file whose `storage` is not in
 * {@see $allowedStorages} is treated exactly like a missing file. Both a
 * non-permitted storage and an absent uid return the same neutral
 * {@see self::NOT_PERMITTED} string (never an exception and never a
 * storage-specific signal), so the model cannot enumerate arbitrary
 * storages or distinguish "exists elsewhere" from "does not exist".
 */
final readonly class ReadFalAssetMetaTool implements ToolInterface
{
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const FILE_TABLE = 'sys_file';

    private const METADATA_TABLE = 'sys_file_metadata';

    /**
     * @param list<int> $allowedStorages sys_file_storage uids this tool may read from
     */
    public function __construct(
        protected ConnectionPool $connectionPool,
        protected array $allowedStorages = [1],
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'read_fal_asset_meta',
            'Return read-only metadata (file name, MIME type, size, title, alternative text) for a single '
            . 'managed file (sys_file) identified by its uid, scoped to permitted storages.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the asset to inspect.',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return self::NOT_PERMITTED;
        }

        $fileQuery = $this->connectionPool->getQueryBuilderForTable(self::FILE_TABLE);
        $fileQuery->getRestrictions()->removeAll();
        $file = $fileQuery
            ->select('storage', 'name', 'mime_type', 'size')
            ->from(self::FILE_TABLE)
            ->where(
                $fileQuery->expr()->eq('uid', $fileQuery->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($file) || !in_array(self::toInt($file['storage'] ?? 0), $this->allowedStorages, true)) {
            return self::NOT_PERMITTED;
        }

        $lines = [
            'file: ' . self::toStr($file['name'] ?? ''),
            'mime: ' . self::toStr($file['mime_type'] ?? ''),
            'size: ' . self::toInt($file['size'] ?? 0) . ' bytes',
        ];

        $metaQuery = $this->connectionPool->getQueryBuilderForTable(self::METADATA_TABLE);
        $metaQuery->getRestrictions()->removeAll();
        $meta = $metaQuery
            ->select('title', 'alternative')
            ->from(self::METADATA_TABLE)
            ->where(
                $metaQuery->expr()->eq('file', $metaQuery->createNamedParameter($uid, Connection::PARAM_INT)),
                // sys_file_metadata is language-aware: removeAll() above drops the
                // language restriction, so pin to the default language explicitly —
                // otherwise an arbitrary translated row could replace the original
                // metadata. (The table has no soft-delete capability, hence no
                // `deleted` column to filter.)
                $metaQuery->expr()->eq('sys_language_uid', $metaQuery->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($meta)) {
            $title = self::toStr($meta['title'] ?? '');
            if ($title !== '') {
                $lines[] = 'title: ' . $title;
            }
            $alt = self::toStr($meta['alternative'] ?? '');
            if ($alt !== '') {
                $lines[] = 'alt: ' . $alt;
            }
        }

        return implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }
}
