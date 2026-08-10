<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the three ADR-146 writers share because they were written together —
 * and deliberately nothing the two older writers use.
 *
 * {@see MoveContentElementTool}, {@see CreateContentElementDraftTool} and
 * {@see CreateTranslationDraftTool} all resolve and authorise once, in a private
 * `plan()`, and use the result for both the write and the approval preview. Three
 * consequences of that shape came out identical, in one pull request, which makes
 * them copy-paste rather than three decisions:
 *
 * - the refusal of an argument the tool does not know,
 * - the viewer-side preview gate, which is `plan()` asked about the viewer,
 * - the row lookup: by uid, with only the deleted restriction.
 *
 * This is NOT {@see WritesThroughDataHandlerTrait}, and the separation is the
 * point. That trait carries the mechanics all FIVE writers share; ADR-146 asked
 * whether it should grow to hold the row lookup and answered no — putting a
 * query into the mechanics trait would either bind it to one table or add a
 * generic getter for call sites that already have one, and it would touch two
 * shipped, tested writers to do it. A trait used by exactly the three tools that
 * actually share the shape costs neither.
 *
 * The consuming class must provide `self::toStr()` / `self::toInt()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}), a
 * `private ConnectionPool $connectionPool`, and the `plan()` declared below.
 */
trait PlansOneEditorialWriteTrait
{
    /**
     * Everything the write needs, resolved and authorised against the given
     * user — or the refusal message that stops it.
     *
     * Declared here so the viewer gate below can rely on it. The return SHAPE is
     * each tool's own: a move, a new element and a translation do not describe
     * themselves with the same fields.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|string
     */
    abstract private function plan(array $arguments, BackendUserAuthentication $user): array|string;

    /**
     * Whether the person LOOKING at the approval card may see this call
     * (ADR-136).
     *
     * Deliberately the same resolution the write uses, run against the VIEWER
     * instead of the acting user: a second authorisation path would be a second
     * answer to the same question. A viewer who could not have made the call
     * gets no preview, and "you may not" collapses into "there is nothing
     * there", so the card never confirms that a uid exists.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return !is_string($this->plan($arguments, $viewer));
    }

    /**
     * Refuse an argument the tool does not know, or null when every key is one
     * of `$known`.
     *
     * The WHOLE call is refused on the first unknown key. Applying the known
     * half of a call the model got wrong leaves a record nobody asked for, and
     * for these three tools the unknown key is often the interesting one — a
     * model that asks to move an element AND rename it must hear "no" rather
     * than get the move alone.
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $known
     * @param string               $whatItDoes a clause completing "It …", naming the one thing the tool does
     */
    private function refuseUnknownArguments(array $arguments, array $known, string $whatItDoes): ?string
    {
        foreach (array_keys($arguments) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            return sprintf(
                'Refused: "%s" is not an argument of this tool. It %s; allowed: %s.',
                // The key is echoed back so the model can correct itself; it is
                // a name the model itself chose, not instance data.
                preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                $whatItDoes,
                implode(', ', $known),
            );
        }

        return null;
    }

    /**
     * A row by uid, or null when no undeleted row carries it.
     *
     * Only the deleted restriction, and that is the decision this method makes
     * for all three tools alike: a hidden or timed-out record is still a record
     * an editor may work on, and none of the three changes what is published.
     *
     * @param non-empty-string $table
     * @param non-empty-string ...$columns the columns to read; none means all
     *
     * @return array<string, mixed>|null
     */
    private function fetchRowByUid(string $table, int $uid, string ...$columns): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select(...($columns === [] ? ['*'] : $columns))
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
