<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Return the backend page tree (`pages`) as a depth-indented outline.
 *
 * Walks children from an optional `rootUid` (default 0 = tree root) down to a
 * bounded `depth`, emitting only `uid`, `title` and `doktype` per node.
 *
 * Security contract (see {@see ToolInterface}): structure only — no page
 * content, TSconfig, slugs or perms columns are read. Soft-deleted and hidden
 * pages are excluded. The model sees the live tree, or, in a run started in a
 * draft workspace, the tree as that workspace shows it: pages renamed, new,
 * moved or deleted there as they are there, each under its live uid, and
 * never another workspace's drafts ({@see ReadsTheRunsWorkspaceTrait},
 * ADR-263). `depth` is
 * hard-capped at {@see self::MAX_DEPTH} and the total number of emitted nodes
 * at {@see self::NODE_CAP} so a single model-steered call cannot enumerate an
 * unbounded tree into the provider egress.
 */
final readonly class GetPageTreeTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    use SafeCastTrait;
    use ReadsTheRunsWorkspaceTrait;

    private const TABLE = 'pages';

    private const DEFAULT_DEPTH = 3;

    private const MAX_DEPTH = 5;

    /**
     * Maximum nodes emitted regardless of depth. Bounds the egress volume from
     * a single model-steered call on a large installation.
     */
    private const NODE_CAP = 200;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_pagetree',
            'Return the backend page tree (uid, title, doktype) as a depth-indented outline, in the default '
            . 'language only: every uid is a default-language page (sys_language_uid 0), and its translations '
            . 'are not listed. Deleted and hidden pages are excluded; structure only, no page content.',
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

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
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
        $this->appendChildren($rootUid, 0, $depth, $lines, $count, $context);

        if ($lines === []) {
            return ToolResult::text('No pages.');
        }

        // The language is stated in the result, not only in the description:
        // a page and its translation share a title, and a model that meets the
        // translation elsewhere must be able to tell that this uid is the
        // default-language one (NEXT-167).
        array_unshift($lines, sprintf(
            'Page tree from uid %d (depth %d; default-language pages, sys_language_uid 0 — translations are not listed):',
            $rootUid,
            $depth,
        ));

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; execute() self-enforces the acting user's TYPO3 permissions.
        return false;
    }

    /**
     * Append the non-deleted, non-hidden children of $pid — live, or as the
     * run's draft workspace shows them — recursing
     * until the depth or the global node cap is reached.
     *
     * @param list<string> $lines
     */
    private function appendChildren(int $pid, int $level, int $maxDepth, array &$lines, int &$count, ToolExecutionContext $context): void
    {
        if ($level >= $maxDepth || $count >= self::NODE_CAP) {
            return;
        }

        // Respect the acting user's page permissions: a non-admin only sees
        // pages they may show. No backend user → no pages (fail-closed).
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return;
        }

        $permsClause = $user->getPagePermsClause(Permission::PAGE_SHOW);
        $workspace   = $this->workspaceToRead($context, $user);

        // Default restrictions (no removeAll) already drop soft-deleted rows;
        // the explicit deleted/hidden filters are kept as belt-and-suspenders,
        // and sys_language_uid is pinned so translated rows do not duplicate the
        // tree.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        if ($workspace === 0) {
            // Never send workspace draft/versioned pages to the external LLM
            // provider (they would also appear as duplicate tree nodes) — in
            // live, and for a run that did not record its draft workspace.
            // Mirrors DatabaseSearchBackend.
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
        }

        $queryBuilder
            ->select('uid', 'title', 'doktype', 't3ver_oid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC');

        if ($workspace > 0) {
            // The pages the run's draft workspace shows under this parent, one
            // per page: a page moved there carries its new parent (ADR-263).
            $queryBuilder->andWhere($this->shownInWorkspace($queryBuilder, self::TABLE, $workspace));
        }

        // For an admin getPagePermsClause() yields an empty string; only add it
        // for non-admins so we never pass an empty constraint to andWhere().
        // In a draft workspace the permission is the live page's, as core's
        // page tree asks it: a version row keeps the perms it was created
        // with, so it is asked of the live uid below instead (ADR-263).
        if ($permsClause !== '' && $workspace === 0) {
            $queryBuilder->andWhere($permsClause);
        }

        $rows = $queryBuilder
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($count >= self::NODE_CAP) {
                return;
            }

            // The live uid: the one its children name as parent.
            $uid = $this->liveUid($row);
            if ($permsClause !== '' && $workspace > 0
                && !is_array(BackendUtility::getRecord(self::TABLE, $uid, 'uid', $permsClause))
            ) {
                continue;
            }

            $lines[] = str_repeat('  ', $level) . sprintf(
                '[%d] %s (doktype %d)',
                $uid,
                self::toStr($row['title'] ?? ''),
                self::toInt($row['doktype'] ?? 0),
            );
            ++$count;

            $this->appendChildren($uid, $level + 1, $maxDepth, $lines, $count, $context);
        }
    }

    public function getGroup(): string
    {
        return 'structure';
    }
}
