<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Service\Tool\Change\ObservedChangeResolver;
use Netresearch\NrLlm\Service\Tool\Change\ToolChangeCollector;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The third check of the scope of an assignment (ADR-295): what a call really
 * wrote.
 *
 * The two checks before it read what a call says and what the DataHandler is
 * given, and each has to understand the shapes of that input. This one does
 * not: before the call's transaction is committed it takes the records the
 * DataHandler reported as written, moved, copied, localized or deleted (the
 * hooks' records and the DataHandler's own history, without the limit of the
 * list a run step keeps), reads each from the database as it now is (final pid,
 * language, translation parent, parent pointer) and judges it by the same
 * membership rules. A write a parser let through is found here, and the caller
 * rolls the transaction back ({@see AssignmentScopeTransaction}).
 *
 * A discard, a publish and the other `version` actions leave nothing to read
 * afterwards that says where they acted: a discard hard-deletes the draft, a
 * publish moves the live row to the draft's position. The hook judges them
 * before core carries them out ({@see AssignmentScopeHook}), cascades included,
 * and what it found outside the scope is part of the answer here (#1442). It
 * has to be called before the guard of the call is taken.
 *
 * @internal
 */
final class AssignmentScopeEffect
{
    /**
     * The records of the innermost armed call that lie outside the scope.
     *
     * @return list<array{refusal: ScopeRefusal, workspace: int}>
     */
    public static function violations(AssignmentScope $scope, AssignmentScopeChecker $checker, int $workspace): array
    {
        $observed = AssignmentScopeGuard::observed();

        try {
            $frame = ToolChangeCollector::peek();
            if ($frame === null) {
                return $observed;
            }

            $truncated = false;
            $entries   = (new ObservedChangeResolver(GeneralUtility::makeInstance(ConnectionPool::class)))->entries($frame['records'], $frame['scopes'], $frame['watermark'], $truncated);
            if ($truncated) {
                // Rows after the cap, or a failed read, were not judged: the check
                // cannot clear this call, so it is rolled back.
                return [...$observed, ['refusal' => new ScopeRefusal('effect', 'pages', 0, 0, ScopeRefusal::UNRESOLVABLE), 'workspace' => $workspace]];
            }

            $found = $observed;
            foreach ($entries as $entry) {
                $refusal = $checker->recordRefusal($scope, 'effect_' . $entry['action']->value, $entry['table'], $entry['uid'], $workspace);
                if (!$refusal instanceof ScopeRefusal) {
                    continue;
                }

                // A hard-deleted row is gone and cannot be read; nothing is left of it.
                if ($refusal->reason === ScopeRefusal::UNRESOLVABLE && $entry['action'] === ChangeAction::DELETED) {
                    continue;
                }

                $found[] = ['refusal' => $refusal, 'workspace' => $entry['workspace']];
            }

            return $found;
        } catch (Throwable) {
            // A check that cannot read what was written has not cleared it: the
            // call is judged out of scope, so it is rolled back, never left open.
            return [...$observed, ['refusal' => new ScopeRefusal('effect', 'pages', 0, 0, ScopeRefusal::UNRESOLVABLE), 'workspace' => $workspace]];
        }
    }
}
