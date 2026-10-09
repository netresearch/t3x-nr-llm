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
 * without a delete command per record, so the page delete is checked too.
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

    public function processCmdmap(string $command, string $table, string|int $id, mixed $value, bool &$commandIsProcessed, DataHandler $dataHandler): void
    {
        if ($commandIsProcessed || $command !== 'delete' || !is_numeric($id) || (int)$id <= 0) {
            return;
        }

        if ($table === self::TABLE) {
            $holders = $this->holders((int)$id);
            if ($holders === []) {
                return;
            }

            $commandIsProcessed = true;
            $dataHandler->log(
                $table,
                (int)$id,
                SystemLogDatabaseAction::DELETE,
                null,
                SystemLogErrorClassification::USER_ERROR,
                'Cannot delete skill {uid}: it is still attached to {holders}. Detach it there first.',
                null,
                ['uid' => (int)$id, 'holders' => implode(', ', $holders)],
            );

            return;
        }

        if ($table !== 'pages') {
            return;
        }

        $attached = [];
        foreach ($this->skillsBelow((int)$id) as $skillUid) {
            if ($this->holders($skillUid) !== []) {
                $attached[] = $skillUid;
            }
        }

        if ($attached === []) {
            return;
        }

        $commandIsProcessed = true;
        $dataHandler->log(
            $table,
            (int)$id,
            SystemLogDatabaseAction::DELETE,
            null,
            SystemLogErrorClassification::USER_ERROR,
            'Cannot delete page {uid}: it or a page below it holds skills still attached to a configuration or a task ({skills}). Detach them first.',
            null,
            ['uid' => (int)$id, 'skills' => implode(', ', $attached)],
        );
    }

    /**
     * The uids of the skills on the page and on every page below it.
     *
     * @return list<int>
     */
    private function skillsBelow(int $pageUid): array
    {
        $pages    = [$pageUid];
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
                if (is_numeric($child) && !in_array((int)$child, $pages, true)) {
                    $pages[]    = (int)$child;
                    $frontier[] = (int)$child;
                }
            }
        }

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
     * @return list<string> "table:uid" of every live record that has the skill attached
     */
    private function holders(int $skillUid): array
    {
        $holders = [];
        foreach (self::ATTACHMENTS as $mmTable => $holderTable) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTable);
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder
                ->select('h.uid')
                ->from($mmTable, 'mm')
                ->join('mm', $holderTable, 'h', $queryBuilder->expr()->eq('h.uid', $queryBuilder->quoteIdentifier('mm.uid_local')))
                ->where(
                    $queryBuilder->expr()->eq('mm.uid_foreign', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('h.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($rows as $uid) {
                if (is_numeric($uid)) {
                    $holders[] = $holderTable . ':' . (int)$uid;
                }
            }
        }

        return $holders;
    }
}
