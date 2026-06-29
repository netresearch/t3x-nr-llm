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
 * Return the backend page tree (`pages`) as a depth-indented outline.
 *
 * Walks children from an optional `rootUid` (default 0 = tree root) down to a
 * bounded `depth`, emitting only `uid`, `title` and `doktype` per node.
 *
 * Security contract (see {@see ToolInterface}): structure only — no page
 * content, TSconfig, slugs or perms columns are read. Soft-deleted and hidden
 * pages are excluded (the model only ever sees the live tree), `depth` is
 * hard-capped at {@see self::MAX_DEPTH} and the total number of emitted nodes
 * at {@see self::NODE_CAP} so a single model-steered call cannot enumerate an
 * unbounded tree into the provider egress.
 */
final readonly class GetPageTreeTool implements ToolInterface
{
    use SafeCastTrait;

    private const TABLE = 'pages';

    private const DEFAULT_DEPTH = 3;

    private const MAX_DEPTH = 5;

    /**
     * Maximum nodes emitted regardless of depth. Bounds the egress volume from
     * a single model-steered call on a large installation.
     */
    private const NODE_CAP = 200;

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_pagetree',
            'Return the backend page tree (uid, title, doktype) as a depth-indented outline. '
            . 'Deleted and hidden pages are excluded; structure only, no page content.',
            [
                'type'       => 'object',
                'properties' => [
                    'rootUid' => [
                        'type'        => 'integer',
                        'description' => 'Page uid to start from (default 0 = tree root).',
                    ],
                    'depth' => [
                        'type'        => 'integer',
                        'description' => 'How many levels deep to descend (default 3, hard cap 5).',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $rootUid = self::toInt($arguments['rootUid'] ?? 0);
        if ($rootUid < 0) {
            $rootUid = 0;
        }

        $depth = self::toInt($arguments['depth'] ?? self::DEFAULT_DEPTH);
        if ($depth < 1) {
            $depth = self::DEFAULT_DEPTH;
        }
        $depth = min($depth, self::MAX_DEPTH);

        $lines = [];
        $count = 0;
        $this->appendChildren($rootUid, 0, $depth, $lines, $count);

        if ($lines === []) {
            return 'No pages.';
        }

        array_unshift($lines, sprintf('Page tree from uid %d (depth %d):', $rootUid, $depth));

        return implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    /**
     * Append the live (non-deleted, non-hidden) children of $pid, recursing
     * until the depth or the global node cap is reached.
     *
     * @param list<string> $lines
     */
    private function appendChildren(int $pid, int $level, int $maxDepth, array &$lines, int &$count): void
    {
        if ($level >= $maxDepth || $count >= self::NODE_CAP) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'title', 'doktype')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // removeAll() above drops the language restriction, so pin to the
                // default language explicitly — otherwise translated page records
                // surface as separate nodes and corrupt the tree structure.
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($count >= self::NODE_CAP) {
                return;
            }

            $uid       = self::toInt($row['uid'] ?? 0);
            $lines[]   = str_repeat('  ', $level) . sprintf(
                '[%d] %s (doktype %d)',
                $uid,
                self::toStr($row['title'] ?? ''),
                self::toInt($row['doktype'] ?? 0),
            );
            ++$count;

            $this->appendChildren($uid, $level + 1, $maxDepth, $lines, $count);
        }
    }
}
