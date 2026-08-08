<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\Tca;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TCA itemsProcFunc listing the prompt-snippet tags actually in use for the
 * `snippet_tags` select on tx_nrllm_configuration (ADR-031).
 *
 * The tag vocabulary is free-form and consumer-owned, so the list is derived
 * from the snippet records rather than from an enum — a new fragment kind needs
 * no nr_llm release. Tags are read straight from the table (not through the
 * Extbase repository): an itemsProcFunc runs inside FormEngine, where no
 * Extbase context is guaranteed.
 *
 * Tags are normalized the same way {@see \Netresearch\NrLlm\Domain\Model\PromptSnippet::getTagList()}
 * normalizes them — trimmed and lowercased — so what an editor picks matches
 * what the runtime compares. Values already stored on the record but no longer
 * carried by any snippet are appended so the stored selection stays visible and
 * editable rather than silently dropped.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class SnippetTagItems
{
    /**
     * @param array{items: array<int, array{label: string, value: string}>, row?: array<string, mixed>} $params
     */
    public function addItems(array &$params): void
    {
        $tags = [];
        foreach ($this->tagsInUse() as $tag) {
            $tags[$tag] = true;
        }

        ksort($tags);

        // Keep already-stored but currently unused tags selectable.
        $row    = is_array($params['row'] ?? null) ? $params['row'] : [];
        $stored = $row['snippet_tags'] ?? '';
        if (is_array($stored)) {
            $stored = implode(',', array_map(
                static fn(mixed $value): string => is_scalar($value) ? (string)$value : '',
                $stored,
            ));
        } elseif (!is_scalar($stored)) {
            $stored = '';
        } else {
            $stored = (string)$stored;
        }

        foreach (GeneralUtility::trimExplode(',', $stored, true) as $known) {
            $tags[strtolower($known)] ??= true;
        }

        foreach (array_keys($tags) as $tag) {
            $params['items'][] = ['label' => $tag, 'value' => $tag];
        }
    }

    /**
     * Every tag token carried by a non-deleted snippet, normalized.
     *
     * A database error yields an empty list rather than a broken edit form:
     * the field must stay usable (the stored selection is still appended
     * above) when the snippet table cannot be read.
     *
     * @return list<string>
     */
    private function tagsInUse(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrllm_promptsnippet');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        try {
            $rows = $queryBuilder
                ->select('tags')
                ->from('tx_nrllm_promptsnippet')
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (Exception) {
            return [];
        }

        $tags = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }

            foreach (explode(',', $row) as $tag) {
                $tag = strtolower(trim($tag));
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }
}
