<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;

/**
 * Decides whether a record, a destination or a page subtree is inside the
 * scope of an assignment (ADR-295). The one place the membership rules live:
 * the invocation rule and the DataHandler hook both ask it, so the two layers
 * cannot drift apart.
 *
 * A row is in scope when
 *
 * - it is a scoped record, or a translation of one, or the metadata of a
 *   scoped file; or
 * - it lies on a scoped page, meaning its pid for any table but pages, and the
 *   page itself, resolved through its default-language row, for pages. The
 *   page is in scope when it is a scoped page, or a page within the depth of
 *   a scoped page above it.
 *
 * The tables and languages of the scope then narrow what is admitted. A row
 * outside the page tree (pid 0) is in scope only through the first rule.
 *
 * @internal
 */
final readonly class AssignmentScopeChecker
{
    /** The most pages a page command may reach before the checker refuses it unread. */
    public const SUBTREE_LIMIT = 5000;

    private RecordTopologyInterface $topology;

    public function __construct(?RecordTopologyInterface $topology = null)
    {
        $this->topology = $topology ?? new DatabaseRecordTopology();
    }

    /**
     * @return list<ScopeRefusal>
     */
    public function refusalsFor(AssignmentScope $scope, ScopedWrite $write, int $workspace): array
    {
        $refusals = [];
        foreach ($write->records as $record) {
            $refusal = $this->recordRefusal($scope, $write->operation, $record->table, $record->uid, $workspace, $write->operation !== 'translate');
            if ($refusal instanceof ScopeRefusal) {
                $refusals[] = $refusal;
            } elseif ($write->reachesSubpages && $record->table === 'pages') {
                $subtree = $this->subtreeRefusal($scope, $write->operation, $record->uid, $workspace);
                if ($subtree instanceof ScopeRefusal) {
                    $refusals[] = $subtree;
                }
            }
        }

        foreach ($write->destinations as $destination) {
            $refusal = $this->destinationRefusal($scope, $write->operation, $destination->table, $destination->pid, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                $refusals[] = $refusal;
            }
        }

        if ($write->language !== null && !$scope->admitsLanguage($write->language)) {
            $first      = $write->destinations[0] ?? null;
            $refusals[] = new ScopeRefusal($write->operation, $first->table ?? ($write->records[0]->table ?? 'pages'), $write->records[0]->uid ?? 0, $first->pid ?? 0, ScopeRefusal::LANGUAGE_NOT_ALLOWED);
        }

        return $refusals;
    }

    /**
     * Whether a row that exists may be written. The row's own language is
     * checked against the scope unless the call is about its translation,
     * where the language that matters is the target's.
     */
    public function recordRefusal(AssignmentScope $scope, string $operation, string $table, int $uid, int $workspace, bool $withLanguage = true): ?ScopeRefusal
    {
        $facts = $this->topology->row($table, $uid, $workspace);
        if (!$facts instanceof RecordFacts) {
            return new ScopeRefusal($operation, $table, $uid, 0, ScopeRefusal::UNRESOLVABLE);
        }

        if (!$scope->admitsTable($table)) {
            return new ScopeRefusal($operation, $table, $uid, $facts->pid, ScopeRefusal::TABLE_NOT_ALLOWED);
        }

        if ($withLanguage && !$scope->admitsLanguage($facts->language)) {
            return new ScopeRefusal($operation, $table, $uid, $facts->pid, ScopeRefusal::LANGUAGE_NOT_ALLOWED);
        }

        if ($this->isScopedRecord($scope, $table, $facts)) {
            return null;
        }

        $page = $table === 'pages' ? ($facts->translationOf ?? $facts->liveUid) : $facts->pid;

        return $page > 0 && $this->pageInScope($scope, $page, $workspace, 0)
            ? null
            : new ScopeRefusal($operation, $table, $uid, $facts->pid, ScopeRefusal::OUTSIDE_SCOPE);
    }

    /**
     * The page a row is on, or for a page its parent: where a record that is
     * put "after" another one lands. Null when the row does not exist.
     */
    public function pidOfRecord(string $table, int $uid, int $workspace): ?int
    {
        return $this->topology->row($table, $uid, $workspace)?->pid;
    }

    /**
     * Whether a row of the table may be put on the page. A page put under a
     * page has to be in scope itself, one level deeper.
     */
    public function destinationRefusal(AssignmentScope $scope, string $operation, string $table, int $pid, int $workspace): ?ScopeRefusal
    {
        if (!$scope->admitsTable($table)) {
            return new ScopeRefusal($operation, $table, 0, max(0, $pid), ScopeRefusal::TABLE_NOT_ALLOWED);
        }

        return $pid > 0 && $this->pageInScope($scope, $pid, $workspace, $table === 'pages' ? 1 : 0)
            ? null
            : new ScopeRefusal($operation, $table, 0, max(0, $pid), ScopeRefusal::OUTSIDE_SCOPE);
    }

    /**
     * Whether every page below the page is in scope. A command that acts on
     * a page acts on its subpages too (a delete takes them along), and a
     * scope reaching less deep than the tree would be left behind otherwise.
     */
    public function subtreeRefusal(AssignmentScope $scope, string $operation, int $pageUid, int $workspace): ?ScopeRefusal
    {
        $below = $this->topology->descendantPages($pageUid, $workspace, self::SUBTREE_LIMIT);
        if ($below === null) {
            return new ScopeRefusal($operation, 'pages', $pageUid, 0, ScopeRefusal::SUBTREE_OUTSIDE_SCOPE);
        }

        foreach ($below as $uid) {
            if (!$this->pageInScope($scope, $uid, $workspace, 0)) {
                return new ScopeRefusal($operation, 'pages', $uid, 0, ScopeRefusal::SUBTREE_OUTSIDE_SCOPE);
            }
        }

        return null;
    }

    private function isScopedRecord(AssignmentScope $scope, string $table, RecordFacts $facts): bool
    {
        if ($scope->holdsRecord($table, $facts->liveUid) || $facts->translationOf !== null && $scope->holdsRecord($table, $facts->translationOf)) {
            return true;
        }

        return $facts->owner instanceof RecordReference && $scope->holdsRecord($facts->owner->table, $facts->owner->uid);
    }

    /**
     * Whether the page, plus $extraDepth levels below it, is within the depth
     * of a scoped page that is the page or one of its ancestors.
     */
    private function pageInScope(AssignmentScope $scope, int $pageUid, int $workspace, int $extraDepth): bool
    {
        $facts = $this->topology->row('pages', $pageUid, $workspace);
        if (!$facts instanceof RecordFacts) {
            return false;
        }

        $current = $facts->translationOf ?? $facts->liveUid;
        $seen    = [];
        for ($hops = 0; $current > 0 && $hops <= AssignmentScope::MAX_DEPTH + 1 && !isset($seen[$current]); ++$hops) {
            $seen[$current] = true;
            foreach ($scope->pages as $page) {
                if ($page->uid === $current && $hops + $extraDepth <= $page->depth) {
                    return true;
                }
            }

            $row     = $this->topology->row('pages', $current, $workspace);
            $current = $row instanceof RecordFacts ? $row->pid : 0;
        }

        return false;
    }
}
