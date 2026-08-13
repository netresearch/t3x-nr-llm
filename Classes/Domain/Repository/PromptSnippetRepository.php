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
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
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
     * Find a snippet by its identifier, hidden ones included.
     *
     * Read by {@see \Netresearch\NrLlm\Service\UseCase\UseCasePackInstaller} to
     * decide whether a declared snippet is already installed. Including hidden
     * records is the point: a snippet the operator switched off must not be
     * recreated by a second install.
     */
    public function findOneByIdentifier(string $identifier): ?PromptSnippet
    {
        $query = $this->createQuery();
        $query->matching($query->equals('identifier', $identifier));

        /** @var PromptSnippet|null $result */
        $result = $query->execute()->getFirst();

        return $result;
    }

    /**
     * Count all non-deleted snippets — including hidden ones, matching what
     * the Snippets backend module lists (the repository default query
     * settings from initializeObject() ignore enable-fields).
     */
    public function countActive(): int
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

        return $query->count();
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
     * This is the lookup for NEW composition — a snippet an operator switched
     * off must not enter a prompt that is being assembled now. Do not merge it
     * with {@see self::findExistingByUids()}: the two answer different
     * questions and the difference is deliberate (ADR-166).
     *
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    public function findByUids(array $uids): array
    {
        return $this->orderedByUid($uids, true);
    }

    /**
     * Find snippets by uid regardless of their active flag, preserving the
     * input order.
     *
     * Same contract as {@see self::findByUids()} — unknown uids are silently
     * skipped, a deleted record still resolves to nothing — except that an
     * inactive (or hidden) snippet still resolves.
     *
     * This is the lookup for text that is ALREADY in a transcript: a resumed
     * agent run re-loads its forced sources to re-gate them (ADR-165), and
     * deactivating a snippet mid-run must not silently drop the classification
     * of text that is still on the wire (ADR-166). "Inactive" means "not for
     * new composition", not "already-injected text loses its class".
     *
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    public function findExistingByUids(array $uids): array
    {
        return $this->orderedByUid($uids, false);
    }

    /**
     * Resolve uids to snippets in the caller's order, optionally restricted to
     * active ones.
     *
     * The repository's default query settings ignore enable fields, so `hidden`
     * plays no part here either way; `is_active` is the only filter, and
     * $activeOnly is what decides whether it applies. The deleted restriction
     * is untouched in both modes — a deleted record never resolves.
     *
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    private function orderedByUid(array $uids, bool $activeOnly): array
    {
        if ($uids === []) {
            return [];
        }

        $query      = $this->createQuery();
        $uidMatches = $query->in('uid', $uids);
        $query->matching(
            $activeOnly
                ? $query->logicalAnd($query->equals('isActive', true), $uidMatches)
                : $uidMatches,
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
