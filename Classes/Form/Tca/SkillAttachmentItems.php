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

/**
 * The skill choice of a configuration or a task (ADR-214 item 3).
 *
 * A new attachment may pick only an enabled skill the sync has not orphaned.
 * A skill that is already attached stays in the list whatever its state, so
 * saving the form keeps the attachment — and with it the restriction such a
 * skill still imposes — and its label says why it no longer instructs.
 *
 * The foreign table delivers every skill; this filters and labels them.
 * FormEngine calls it without arguments, so the database is reached through
 * makeInstance() (as {@see SnippetTagItems} does).
 */
final class SkillAttachmentItems
{
    private const LABELS = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:';

    /**
     * @param array{items: array<int, mixed>, row?: array<string, mixed>, config?: array<string, mixed>} $params
     */
    public function filterAndLabel(array &$params): void
    {
        $items = $params['items'];
        $uids  = [];
        foreach ($items as $item) {
            $value = $this->valueOf($item);
            if ($value > 0) {
                $uids[] = $value;
            }
        }

        if ($uids === []) {
            return;
        }

        $states   = $this->states($uids);
        $attached = array_flip($this->attached($params));
        $kept     = [];
        foreach ($items as $item) {
            $value = $this->valueOf($item);
            $state = $states[$value] ?? null;
            if ($state === null || !is_array($item)) {
                $kept[] = $item;
                continue;
            }

            $suffix = $state['orphaned'] ? 'orphaned' : ($state['enabled'] ? null : 'disabled');
            if ($suffix === null) {
                $kept[] = $item;
                continue;
            }

            if (!isset($attached[$value])) {
                continue;
            }

            $label         = is_string($item['label'] ?? null) ? $item['label'] : (string)$value;
            $item['label'] = $label . ' (' . $this->translate(self::LABELS . 'tx_nrllm_skill.attachment.' . $suffix, $suffix) . ')';
            $kept[]        = $item;
        }

        $params['items'] = $kept;
    }

    private function valueOf(mixed $item): int
    {
        $value = is_array($item) ? ($item['value'] ?? null) : null;

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, array{enabled: bool, orphaned: bool}>
     */
    private function states(array $uids): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_nrllm_skill');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'enabled', 'orphaned')
            ->from('tx_nrllm_skill')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $states = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['uid'] ?? null)) {
                continue;
            }

            $states[(int)$row['uid']] = [
                'enabled'  => is_numeric($row['enabled'] ?? null) && (int)$row['enabled'] === 1,
                'orphaned' => is_numeric($row['orphaned'] ?? null) && (int)$row['orphaned'] === 1,
            ];
        }

        return $states;
    }

    /**
     * The skills the edited record has attached now, read from its MM table
     * (FormEngine holds only the relation count at this point).
     *
     * @param array{row?: array<string, mixed>, config?: array<string, mixed>} $params
     *
     * @return list<int>
     */
    private function attached(array $params): array
    {
        $uid     = $params['row']['uid'] ?? null;
        $mmTable = $params['config']['MM'] ?? null;
        if (!is_numeric($uid) || (int)$uid <= 0 || !is_string($mmTable) || $mmTable === '') {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($mmTable);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid_foreign')
            ->from($mmTable)
            ->where($queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter((int)$uid, Connection::PARAM_INT)));
        $matchFields = $params['config']['MM_match_fields'] ?? [];
        if (is_array($matchFields)) {
            foreach ($matchFields as $field => $match) {
                if (is_string($field) && is_scalar($match)) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter((string)$match)));
                }
            }
        }

        $attached = [];
        foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $skillUid) {
            if (is_numeric($skillUid)) {
                $attached[] = (int)$skillUid;
            }
        }

        return $attached;
    }

    private function translate(string $key, string $fallback): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!is_object($languageService) || !method_exists($languageService, 'sL')) {
            return $fallback;
        }

        $translated = $languageService->sL($key);

        return is_string($translated) && $translated !== '' ? $translated : $fallback;
    }
}
