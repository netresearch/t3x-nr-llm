<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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
     * Create ONE record through the DataHandler as the given user and hand back
     * its uid — or the refusal, when the DataHandler complained or no row came
     * into being.
     *
     * A uid is proof that a row exists, not that it carries what was asked for:
     * the caller reads the row back and judges it against its own plan. What is
     * shared is only the part that is the same for every creation — the
     * placeholder, the datamap, the error log, and the one failure the
     * DataHandler reports by silence, a missing grant to create in the table.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $record         the fields of the new row, `pid` included
     * @param string               $whenNotCreated the refusal when no uid came back, naming what was most likely missing
     */
    private function createRecord(string $table, array $record, BackendUserAuthentication $user, string $whenNotCreated): int|ToolResult
    {
        $placeholder = StringUtility::getUniqueId('NEW');

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$placeholder => $record]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $newUid = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);

        return $newUid < 1 ? ToolResult::error($whenNotCreated) : $newUid;
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
