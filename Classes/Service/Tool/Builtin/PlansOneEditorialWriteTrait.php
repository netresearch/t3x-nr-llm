<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

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
 * The sixth writer (ADR-180) found two more that had come out identical in all
 * four, and moved them here in the same pass rather than adding a fourth copy:
 *
 * - the guard `execute()` opens with — an acting user, a backend environment,
 *   the live workspace — answered as the user to write as, or the refusal,
 * - the creation of ONE record through the DataHandler and the reading back of
 *   its uid, which is where a missing table grant surfaces.
 *
 * What stays per tool is `plan()` itself and everything that reads its shape:
 * the record to write, the read-back and its fields, the success line.
 *
 * This is NOT {@see WritesThroughDataHandlerTrait}, and the separation is the
 * point. That trait carries the mechanics all FIVE writers share; ADR-146 asked
 * whether it should grow to hold the row lookup and answered no — putting a
 * query into the mechanics trait would either bind it to one table or add a
 * generic getter for call sites that already have one, and it would touch two
 * shipped, tested writers to do it. A trait used by exactly the tools that
 * actually share the shape costs neither. ADR-146 asked the next writer to use
 * it, and {@see CreatePageDraftTool} (ADR-180) does.
 *
 * The consuming class must provide `self::toStr()` / `self::toInt()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}), a
 * `private ConnectionPool $connectionPool`, a `NOT_PERMITTED` constant (the
 * neutral refusal for "no such record or not yours"), the two refusals of
 * {@see WritesThroughDataHandlerTrait}, and the `plan()` declared below.
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
     * The user a write may be performed as — or the refusal that stops it.
     *
     * The three questions every `execute()` opens with, in the order that names
     * the most specific reason first: is there an acting backend user at all
     * (fail closed, with the neutral refusal so nothing is confirmed); does
     * this process carry the backend environment the DataHandler needs; is the
     * user in the live workspace. Only then does a tool resolve its `plan()`.
     *
     * @param non-empty-string $table the table whose TCA proves a TCA is loaded at all
     */
    private function writableActingUser(ToolExecutionContext $context, string $table): BackendUserAuthentication|ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment($table)
            ?? $this->refuseOutsideLiveWorkspace($user);

        return $refusal ?? $user;
    }

    /**
     * {@see self::writableActingUser()} for a writer that writes into the
     * acting user's workspace (ADR-260): in live, or in the draft workspace the
     * run was started in, where the DataHandler stores a workspace version.
     * Refused in a draft workspace as well where an installation took the
     * versioning off `$table`: the DataHandler would write the live row there
     * (ADR-262).
     *
     * @param non-empty-string $table the table the writer writes, whose TCA also proves a TCA is loaded at all
     */
    private function writableActingUserInItsWorkspace(ToolExecutionContext $context, string $table): BackendUserAuthentication|ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment($table)
            ?? $this->refuseUnpinnedWorkspace($context, $user)
            ?? $this->refuseUnversionedTableInDraftWorkspace($context, $table);

        return $refusal ?? $user;
    }

    /**
     * The refusal a writer that writes into its run's workspace meets
     * whatever the approver decides
     * ({@see \Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface},
     * ADR-266), or null — always in live.
     *
     * In a draft workspace: a run that did not record it (ADR-260), a table
     * core keeps no draft of (ADR-262), then, for a call the plan accepts,
     * what `$inWorkspace` refuses there because core's command does something
     * else in a workspace. A call the plan refuses is left to the plan, as
     * before: its card says why. A process without a backend environment is
     * left to `execute()`, which names what is missing.
     *
     * Pure and read-only, as the interface asks; `execute()` and the preview
     * ask it first.
     *
     * @param array<string, mixed>                                                     $arguments
     * @param (Closure(array<string, mixed>, BackendUserAuthentication): ?string)|null $inWorkspace
     * @param non-empty-string                                                         ...$tables   the tables the call writes
     */
    private function refusalInItsWorkspace(array $arguments, ToolExecutionContext $context, ?Closure $inWorkspace, string ...$tables): ?ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication || $user->workspace <= 0
            || $this->refuseWithoutBackendEnvironment('pages') instanceof ToolResult
        ) {
            return null;
        }

        $refusal = $this->refuseUnpinnedWorkspace($context, $user);
        foreach ($tables as $table) {
            $refusal ??= $this->refuseUnversionedTableInDraftWorkspace($context, $table);
        }

        if ($refusal instanceof ToolResult || !$inWorkspace instanceof Closure) {
            return $refusal;
        }

        $plan    = $this->plan($arguments, $user);
        $refused = is_string($plan) ? null : $inWorkspace($plan, $user);

        return $refused === null ? null : ToolResult::error($refused);
    }

    /**
     * Create ONE record through the DataHandler as the given user and hand back
     * its uid with TYPO3's complaints — or the refusal, when no row came into
     * being.
     *
     * A uid is proof that a row exists, not that it carries what was asked for:
     * the caller reads the row back and judges it against its own plan, and
     * takes the row back where it does not. The error log decides nothing
     * (ADR-198): a complaint can be about another record of the run, and a
     * row core inserted all the same is the caller's to judge, not to leave
     * behind under "refused". What is shared is only the part that is the
     * same for every creation — the placeholder, the datamap, the error log,
     * and the one failure the DataHandler reports by silence, a missing grant
     * to create in the table.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $record         the fields of the new row, `pid` included
     * @param string               $whenNotCreated the refusal when no uid came back and TYPO3 said nothing, naming what was most likely missing
     *
     * @return array{int, string}|ToolResult the new uid and TYPO3's complaints as {@see self::complaintsNote()} words them
     */
    private function createRecord(string $table, array $record, BackendUserAuthentication $user, string $whenNotCreated): array|ToolResult
    {
        $placeholder = StringUtility::getUniqueId('NEW');

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->keepSortOrdersInADraftWorkspace();
        $dataHandler->start([$table => [$placeholder => $record]], [], $user);
        $dataHandler->process_datamap();

        $newUid = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        if ($dataHandler->foundNoRoomInASortOrder()) {
            return ToolResult::error($this->takeBackTheUnplacedRecord($table, $newUid, $user));
        }

        if ($newUid < 1) {
            return $this->refuseOnDataHandlerErrors($dataHandler) ?? ToolResult::error($whenNotCreated);
        }

        return [$newUid, $this->complaintsNote($dataHandler)];
    }

    /**
     * The refusal for a create in a draft workspace that found no room in the
     * page's sort order (ADR-264), with the record the run inserted all the
     * same taken back: deleting a row new in a workspace removes it outright.
     *
     * @param non-empty-string $table
     */
    private function takeBackTheUnplacedRecord(string $table, int $uid, BackendUserAuthentication $user): string
    {
        $refusal = 'The record was not created: there is no room in the sort order of the page at that position, and '
            . 'TYPO3 makes room by renumbering the live records of the page and the drafts of every workspace there, '
            . 'which puts the drafts of this workspace out of order. Place it after another record, or have an editor '
            . 'make room on the page in live first.';
        if ($uid < 1) {
            return $refusal;
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $left = self::toInt($queryBuilder->count('uid')->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne());

        return $left === 0 ? $refusal : $refusal . sprintf(
            ' The record %s:%d it inserted COULD NOT BE DELETED and needs a look in the Workspaces module.',
            $table,
            $uid,
        );
    }

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
     * A LIVE row by uid, or null when no undeleted live row carries it.
     *
     * The deleted restriction and the live-version constraints
     * ({@see WritesThroughDataHandlerTrait::liveVersionConstraints()}): a
     * hidden or timed-out record is still a record an editor may work on, but
     * a workspace version row is another workspace's draft and is treated as
     * not there (ADR-198).
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
                ...$this->liveVersionConstraints($queryBuilder, $table),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * A row by uid as the user sees it in their workspace (ADR-260), or null.
     *
     * In live exactly {@see self::fetchRowByUid()}. In a draft workspace the
     * live row overlaid with its version there, or a row new in that
     * workspace; null for a row deleted there, a version row addressed by its
     * own uid and a row of another workspace.
     *
     * @param non-empty-string $table
     *
     * @return array<string, mixed>|null
     */
    private function fetchRowAsTheUserSees(string $table, int $uid, BackendUserAuthentication $user): ?array
    {
        $workspace = max(0, $user->workspace);
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                ...$this->workspaceVersionConstraints($queryBuilder, $table, $workspace),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->rowInWorkspace($table, $row, $workspace) : null;
    }
}
