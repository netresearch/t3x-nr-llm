<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Skill;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Skill>
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
class SkillRepository extends Repository
{
    protected $defaultOrderings = [
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    public function findBySourceAndIdentifier(int $source, string $identifier): ?Skill
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('source', $source),
                $query->equals('identifier', $identifier),
            ),
        );
        $result = $query->execute();
        $first = $result->getFirst();
        return $first instanceof Skill ? $first : null;
    }

    /**
     * @return list<Skill>
     */
    public function findBySource(int $source): array
    {
        $query = $this->createQuery();
        $query->matching($query->equals('source', $source));
        /** @var list<Skill> $skills */
        $skills = $query->execute()->toArray();
        return array_values($skills);
    }

    /**
     * Count all synced (non-deleted) skills, regardless of enabled state.
     */
    public function countAll(): int
    {
        return $this->createQuery()->count();
    }

    /**
     * Count skills that are currently enabled.
     */
    public function countEnabled(): int
    {
        $query = $this->createQuery();
        $query->matching($query->equals('enabled', true));

        return $query->count();
    }

    /**
     * Find enabled skills by uid, preserving the input order.
     *
     * Unknown and disabled uids are silently skipped.
     *
     * This is the lookup for NEW composition — a skill an operator switched
     * off must not enter a prompt that is being assembled now. Do not merge it
     * with {@see self::findExistingByUids()}: the two answer different
     * questions and the difference is deliberate (ADR-175, mirroring the
     * snippet pair ADR-166 introduced).
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    public function findByUids(array $uids): array
    {
        return $this->orderedByUid($uids, true);
    }

    /**
     * Find skills by uid regardless of their enabled flag, preserving the
     * input order.
     *
     * Same contract as {@see self::findByUids()} — unknown uids are silently
     * skipped, a deleted record still resolves to nothing — except that a
     * disabled skill still resolves.
     *
     * This is the lookup for text that is ALREADY in a transcript: a resumed
     * agent run re-loads its forced sources to re-gate them (ADR-165), and
     * disabling a skill mid-run must not silently drop the classification of
     * text that is still on the wire (ADR-166). "Disabled" means "not for new
     * composition", not "already-injected text loses its class".
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    public function findExistingByUids(array $uids): array
    {
        return $this->orderedByUid($uids, false);
    }

    /**
     * Resolve uids to skills in the caller's order, optionally restricted to
     * enabled ones.
     *
     * The repository's default query settings ignore enable fields, so TYPO3's
     * own `hidden` plays no part here either way; `enabled` is the extension's
     * own field and $enabledOnly is what decides whether it applies. The
     * deleted restriction is untouched in both modes — a deleted record never
     * resolves. The caller's order replaces $defaultOrderings, which is the
     * point: the fold in {@see \Netresearch\NrLlm\Domain\ValueObject\InputContextClassification::withStricter()}
     * lets the later source win a tie, so the order a run was started with has
     * to be the order every later lookup reproduces.
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    private function orderedByUid(array $uids, bool $enabledOnly): array
    {
        if ($uids === []) {
            return [];
        }

        $query      = $this->createQuery();
        $uidMatches = $query->in('uid', $uids);
        $query->matching(
            $enabledOnly
                ? $query->logicalAnd($query->equals('enabled', true), $uidMatches)
                : $uidMatches,
        );

        /** @var array<int, Skill> $skillsByUid */
        $skillsByUid = [];
        foreach ($query->execute() as $skill) {
            $uid = $skill->getUid();
            if ($uid !== null) {
                $skillsByUid[$uid] = $skill;
            }
        }

        $ordered = [];
        foreach ($uids as $uid) {
            if (isset($skillsByUid[$uid])) {
                $ordered[] = $skillsByUid[$uid];
            }
        }

        return $ordered;
    }
}
