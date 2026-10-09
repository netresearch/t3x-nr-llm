<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Refuses to delete a skill that a configuration or a task still has
 * attached, and a page whose subtree holds such a skill (ADR-214 item 3).
 *
 * An attached skill can restrict a run's tool allow-list. Extbase does not
 * load a deleted record through the attachment, so deleting the skill would
 * take that restriction away, and deleting needs no excluded field: an editor
 * who may write skills could lift a restriction nobody approved lifting. The
 * attachment is detached first, on the configuration or the task, by whoever
 * may edit those. Deleting a page deletes the records on it and below it
 * without a delete action per record, so the page delete is checked too; a
 * holder that sits in the deleted subtree goes with it and does not count.
 *
 * Hooked into the DataHandler's delete action, which every delete passes —
 * a delete command, a delete staged in a workspace, and a workspace publish
 * that applies a delete placeholder to live. A publish refused there has
 * already swapped the placeholder's fields onto the live row, so a publish
 * of a page delete placeholder is also taken out of the command map before
 * it starts (processCmdmap_beforeStart, after EXT:workspaces resolved its
 * dependencies); the delete-action check stays as the backstop.
 *
 * Registered under `processCmdmapClass` in `ext_localconf.php`. A public
 * service, so the DataHandler's makeInstance() gets the container-built
 * instance with its connection pool.
 */
final readonly class SkillDeletionGuardHook
{
    private const TABLE = 'tx_nrllm_skill';

    /** Attachment table => the table of the record that holds the attachment. */
    private const ATTACHMENTS = [
        'tx_nrllm_configuration_skill_mm' => 'tx_nrllm_configuration',
        'tx_nrllm_task_skill_mm'          => 'tx_nrllm_task',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Take a publish of a page delete placeholder out of the command map when
     * the page's subtree holds attached skills, before anything is swapped.
     */
    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        $pageCommands = $dataHandler->cmdmap['pages'] ?? null;
        if (!is_array($pageCommands) || !$this->anyLiveAttachment()) {
            return;
        }

        foreach ($pageCommands as $liveUid => $commands) {
            $version = is_array($commands) ? ($commands['version'] ?? null) : null;
            if (!is_array($version) || !in_array($version['action'] ?? null, ['publish', 'swap'], true)) {
                continue;
            }

            $placeholder = $version['swapWith'] ?? null;
            if (!is_numeric($liveUid) || !is_numeric($placeholder) || !$this->isDeletePlaceholder((int)$placeholder)) {
                continue;
            }

            $pages   = $this->subtree((int)$liveUid);
            $holders = $this->holders($this->skillsOn($pages), $pages);
            if ($holders === []) {
                continue;
            }

            unset($dataHandler->cmdmap['pages'][$liveUid]['version']);
            if ($dataHandler->cmdmap['pages'][$liveUid] === []) {
                unset($dataHandler->cmdmap['pages'][$liveUid]);
            }

            $dataHandler->log(
                'pages',
                (int)$liveUid,
                SystemLogDatabaseAction::DELETE,
                null,
                SystemLogErrorClassification::USER_ERROR,
                'Cannot publish the deletion of page {uid}: it or a page below it holds skills still attached to {holders}. Detach them first.',
                null,
                ['uid' => (int)$liveUid, 'holders' => implode(', ', $holders)],
            );
        }
    }

    /**
     * @param array<string, mixed>|null $record
     */
    public function processCmdmap_deleteAction(string $table, string|int $id, ?array $record, bool &$recordWasDeleted, DataHandler $dataHandler): void
    {
        if ($recordWasDeleted || !is_numeric($id) || (int)$id <= 0 || ($table !== self::TABLE && $table !== 'pages')) {
            return;
        }

        // A record already in the recycle bin (deleted for good there) is
        // no longer loaded through any attachment.
        if (is_numeric($record['deleted'] ?? null) && (int)$record['deleted'] === 1) {
            return;
        }

        $uid = (int)$id;
        if ($table === self::TABLE) {
            $holders = $this->holders([$uid], []);
            if ($holders === []) {
                return;
            }

            $recordWasDeleted = true;
            $dataHandler->log(
                $table,
                $uid,
                SystemLogDatabaseAction::DELETE,
                null,
                SystemLogErrorClassification::USER_ERROR,
                'Cannot delete skill {uid}: it is still attached to {holders}. Detach it there first.',
                null,
                ['uid' => $uid, 'holders' => implode(', ', $holders)],
            );

            return;
        }

        if (!$this->anyLiveAttachment()) {
            return;
        }

        $pages   = $this->subtree($uid);
        $holders = $this->holders($this->skillsOn($pages), $pages);
        if ($holders === []) {
            return;
        }

        $recordWasDeleted = true;
        $dataHandler->log(
            $table,
            $uid,
            SystemLogDatabaseAction::DELETE,
            null,
            SystemLogErrorClassification::USER_ERROR,
            'Cannot delete page {uid}: it or a page below it holds skills still attached to {holders}. Detach them first.',
            null,
            ['uid' => $uid, 'holders' => implode(', ', $holders)],
        );
    }

    private function isDeletePlaceholder(int $pageUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $state = $queryBuilder
            ->select('t3ver_state')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($state) && (int)$state === VersionState::DELETE_PLACEHOLDER->value;
    }

    /**
     * Whether any skill is attached to a live configuration or task at all —
     * the cheap answer for the usual page delete.
     */
    private function anyLiveAttachment(): bool
    {
        foreach (self::ATTACHMENTS as $mmTable => $holderTable) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTable);
            $queryBuilder->getRestrictions()->removeAll();
            $found = $queryBuilder
                ->select('mm.uid_foreign')
                ->from($mmTable, 'mm')
                ->join('mm', $holderTable, 'h', $queryBuilder->expr()->eq('h.uid', $queryBuilder->quoteIdentifier('mm.uid_local')))
                ->where($queryBuilder->expr()->eq('h.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            if ($found !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The page and every live page below it.
     *
     * @return list<int>
     */
    private function subtree(int $pageUid): array
    {
        $pages    = [$pageUid => true];
        $frontier = [$pageUid];
        while ($frontier !== []) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();
            $children = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($frontier, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchFirstColumn();
            $frontier = [];
            foreach ($children as $child) {
                if (is_numeric($child) && !isset($pages[(int)$child])) {
                    $pages[(int)$child] = true;
                    $frontier[]         = (int)$child;
                }
            }
        }

        return array_keys($pages);
    }

    /**
     * The live skills on the given pages.
     *
     * @param list<int> $pages
     *
     * @return list<int>
     */
    private function skillsOn(array $pages): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $skills = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pages, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $uids = [];
        foreach ($skills as $uid) {
            if (is_numeric($uid)) {
                $uids[] = (int)$uid;
            }
        }

        return $uids;
    }

    /**
     * "table:uid" of every live configuration or task that has one of the
     * skills attached, leaving out holders on the pages being deleted.
     *
     * @param list<int> $skillUids
     * @param list<int> $deletedPages
     *
     * @return list<string>
     */
    private function holders(array $skillUids, array $deletedPages): array
    {
        if ($skillUids === []) {
            return [];
        }

        $holders = [];
        foreach (self::ATTACHMENTS as $mmTable => $holderTable) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTable);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->select('h.uid')
                ->from($mmTable, 'mm')
                ->join('mm', $holderTable, 'h', $queryBuilder->expr()->eq('h.uid', $queryBuilder->quoteIdentifier('mm.uid_local')))
                ->where(
                    $queryBuilder->expr()->in('mm.uid_foreign', $queryBuilder->createNamedParameter($skillUids, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('h.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                );
            if ($deletedPages !== []) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->notIn('h.pid', $queryBuilder->createNamedParameter($deletedPages, Connection::PARAM_INT_ARRAY)),
                );
            }

            foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                if (is_numeric($uid)) {
                    $holders[$holderTable . ':' . (int)$uid] = true;
                }
            }
        }

        return array_keys($holders);
    }
}
