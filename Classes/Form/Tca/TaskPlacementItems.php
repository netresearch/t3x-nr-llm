<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\Tca;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;

/**
 * Orders a select of tasks by the placement the field names (ADR-245).
 *
 * A field that offers tasks — another extension's TCA as much as nr-llm's —
 * names its place in `itemsProcConfig.placement`. The tasks marked for that
 * place come first, in the group {@see GROUP_MARKED}; the remaining tasks
 * follow in {@see GROUP_UNMARKED}, or are hidden when `itemsProcConfig.unmarked`
 * is `hide`. Static items of the field (a "none" entry) keep their position
 * ahead of both groups. Hiding never removes the task the record already
 * holds, and never applies while no task carries the mark: a field offering
 * nothing but its static items would leave an installation without a way to
 * choose a task at all.
 *
 * The field declares both groups in its `itemGroups`, with its own labels.
 * FormEngine calls this without arguments, so the database is reached
 * through makeInstance() (as {@see SkillAttachmentItems} does).
 */
final class TaskPlacementItems
{
    public const GROUP_MARKED = 'nrllm_placement';

    public const GROUP_UNMARKED = 'nrllm_unmarked';

    /**
     * @param array<string, mixed> $params
     */
    public function groupByPlacement(array &$params): void
    {
        $config    = is_array($params['config'] ?? null) ? $params['config'] : [];
        $procConf  = is_array($config['itemsProcConfig'] ?? null) ? $config['itemsProcConfig'] : [];
        $placement = is_string($procConf['placement'] ?? null) ? trim($procConf['placement']) : '';
        $items     = is_array($params['items'] ?? null) ? $params['items'] : [];
        if ($placement === '' || $items === []) {
            return;
        }

        $uids = [];
        foreach ($items as $item) {
            $value = $this->valueOf($item);
            if ($value > 0) {
                $uids[] = $value;
            }
        }

        $placements = $this->placementsOf($uids);
        $marked     = [];
        foreach ($placements as $uid => $list) {
            if (in_array($placement, $list, true)) {
                $marked[$uid] = true;
            }
        }

        $hide    = ($procConf['unmarked'] ?? 'after') === 'hide' && $marked !== [];
        $current = array_flip($this->currentValues($params));

        $static        = [];
        $markedItems   = [];
        $unmarkedItems = [];
        foreach ($items as $item) {
            $value = $this->valueOf($item);
            if (!is_array($item) || !isset($placements[$value])) {
                $static[] = $item;
                continue;
            }

            if (isset($marked[$value])) {
                $item['group'] = self::GROUP_MARKED;
                $markedItems[] = $item;
                continue;
            }

            if ($hide && !isset($current[$value])) {
                continue;
            }

            $item['group']   = self::GROUP_UNMARKED;
            $unmarkedItems[] = $item;
        }

        $params['items'] = [...$static, ...$markedItems, ...$unmarkedItems];
    }

    private function valueOf(mixed $item): int
    {
        $value = is_array($item) ? ($item['value'] ?? null) : null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The value the record holds: FormEngine hands a select's value over as a
     * list, a direct call may pass the raw column.
     *
     * @param array<string, mixed> $params
     *
     * @return list<int>
     */
    private function currentValues(array $params): array
    {
        $field = is_string($params['field'] ?? null) ? $params['field'] : '';
        $row   = is_array($params['row'] ?? null) ? $params['row'] : [];
        $raw   = $field !== '' ? ($row[$field] ?? null) : null;
        $list  = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');

        $values = [];
        foreach ($list as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $values[] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, list<string>>
     */
    private function placementsOf(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_nrllm_task');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'placements')
            ->from('tx_nrllm_task')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $placements = [];
        foreach ($rows as $row) {
            $uid  = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            $list = is_string($row['placements'] ?? null) ? $row['placements'] : '';
            if ($uid > 0) {
                $placements[$uid] = array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $p): bool => $p !== ''));
            }
        }

        return $placements;
    }
}
