<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Where is this file used? (ADR-047).
 *
 * Lists the `sys_file_reference` usages of one managed file as
 * `table:uid (field)` pairs — the "can I delete this asset?" and "which
 * content shows this image?" answer. Soft references (RTE links, plain-text
 * URLs) are NOT tracked here, which the output states explicitly so the
 * model never concludes "unused" too strongly.
 *
 * Gates: the file's storage must pass {@see FalStorageGate} (same neutral
 * denial as read_fal_asset_meta), and non-admins only see references from
 * tables they may read ({@see TableReadAccessService}).
 */
final readonly class GetFalReferencesTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    /** Upper bound on listed references. */
    private const MAX_REFERENCES = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_fal_references',
            'List where a managed file (sys_file uid) is used: the referencing records as table:uid with '
            . 'the field name. Soft references (RTE links, plain URLs) are not tracked — "no references" '
            . 'does not guarantee the file is unused.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid whose usages to list.',
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

        $user = $this->actingBackendUser();

        $fileQuery = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $fileQuery->getRestrictions()->removeAll();
        $file = $fileQuery
            ->select('storage', 'name')
            ->from('sys_file')
            ->where($fileQuery->expr()->eq('uid', $fileQuery->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($file) || !$this->storageGate->isAllowed($user, self::toInt($file['storage'] ?? 0))) {
            return self::NOT_PERMITTED;
        }

        $refQuery = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $refQuery->getRestrictions()->removeAll();
        $rows = $refQuery
            ->select('tablenames', 'uid_foreign', 'fieldname', 'hidden')
            ->from('sys_file_reference')
            ->where(
                $refQuery->expr()->eq('uid_local', $refQuery->createNamedParameter($uid, Connection::PARAM_INT)),
                $refQuery->expr()->eq('deleted', $refQuery->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tablenames')
            ->addOrderBy('uid_foreign')
            ->executeQuery()
            ->fetchAllAssociative();

        $isAdmin = $user !== null && $user->isAdmin();

        $lines   = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $table = self::toStr($row['tablenames'] ?? '');
            // A reference without a table name is dangling — skip it.
            if ($table === '') {
                continue;
            }
            // Non-admins only see references from tables they may read.
            if (!$isAdmin && !$this->tableAccess->canReadTable($user, $table)) {
                continue;
            }
            if (count($lines) >= self::MAX_REFERENCES) {
                ++$skipped;
                continue;
            }
            $lines[] = sprintf(
                '- %s:%d (field %s)%s',
                $table,
                self::toInt($row['uid_foreign'] ?? 0),
                self::toStr($row['fieldname'] ?? ''),
                self::toInt($row['hidden'] ?? 0) === 1 ? ' [hidden]' : '',
            );
        }

        if ($lines === []) {
            return sprintf(
                'No references to %s — the file appears unused (soft references and inline RTE links are not tracked here).',
                self::toStr($file['name'] ?? ''),
            );
        }

        $header = sprintf(
            'References to %s (%d%s):',
            self::toStr($file['name'] ?? ''),
            count($lines),
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
        // Usable by non-admins; storage gate + per-table read gate self-enforce.
        return false;
    }

    public function getGroup(): string
    {
        return 'files';
    }
}
