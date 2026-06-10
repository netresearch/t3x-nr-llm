<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for PromptSnippet domain model.
 *
 * @extends Repository<PromptSnippet>
 */
class PromptSnippetRepository extends Repository
{
    protected $defaultOrderings = [
        'sorting' => QueryInterface::ORDER_ASCENDING,
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Initialize repository for backend module use.
     * Ignores storage page and enable fields restrictions.
     */
    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Find active snippets carrying the given tag.
     *
     * The tag is matched as an exact, case-insensitive token against the
     * comma-separated tags field — never as a substring: tag 'style' does
     * NOT match a snippet tagged 'lifestyle'.
     *
     * @return list<PromptSnippet> ordered by sorting, then name
     */
    public function findActiveByTag(string $tag): array
    {
        $normalizedTag = strtolower(trim($tag));
        if ($normalizedTag === '') {
            return [];
        }

        $query = $this->createQuery();
        $query->matching(
            $query->equals('isActive', true),
        );
        $query->setOrderings([
            'sorting' => QueryInterface::ORDER_ASCENDING,
            'name' => QueryInterface::ORDER_ASCENDING,
        ]);

        $snippets = [];
        foreach ($query->execute() as $snippet) {
            if (in_array($normalizedTag, $snippet->getTagList(), true)) {
                $snippets[] = $snippet;
            }
        }

        return $snippets;
    }

    /**
     * Find active snippets by uid, preserving the input order.
     *
     * Unknown and inactive uids are silently skipped.
     *
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    public function findByUids(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->in('uid', $uids),
            ),
        );

        /** @var array<int, PromptSnippet> $snippetsByUid */
        $snippetsByUid = [];
        foreach ($query->execute() as $snippet) {
            $uid = $snippet->getUid();
            if ($uid !== null) {
                $snippetsByUid[$uid] = $snippet;
            }
        }

        $ordered = [];
        foreach ($uids as $uid) {
            if (isset($snippetsByUid[$uid])) {
                $ordered[] = $snippetsByUid[$uid];
            }
        }

        return $ordered;
    }
}
