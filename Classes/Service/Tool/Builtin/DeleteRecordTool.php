<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Exception\PlanRefusedException;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWriteInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Delete ONE page or content element, through the DataHandler, as the acting
 * backend user (ADR-198).
 *
 * The delete is the DataHandler's own: both tables declare a `delete` column,
 * so the row is flagged `deleted = 1` and stays recoverable from the recycler
 * and the history — nothing is removed from the database. What goes with it is
 * what goes with it in the page module, and the approval card counts it
 * before anything happens:
 *
 * - **A default-language record takes its translations along.** Their
 *   languages must all be the acting user's, or the call is refused — the
 *   DataHandler would delete what it may and leave the rest.
 * - **A page takes every record stored on it along**, and every subpage: core
 *   deletes a default-language page's whole branch, and has no switch to
 *   leave it. A page with subpages is therefore refused unless the call sets
 *   `include_subpages`, and the card names how many there are. The branch is
 *   bounded ({@see self::MAX_BRANCH_PAGES}); a larger one is not an act one
 *   approval card can describe.
 *
 * What it refuses, and why:
 *
 * - **Any table but `pages` and `tt_content`** (ADR-198).
 * - **A site root.** Deleting it takes a site offline; that is an
 *   administrator's decision in the site configuration, not an editorial one.
 * - **A record the acting user may not delete.** `PAGE_DELETE` on a page — on
 *   the default-language page for a page translation, as core asks — and on
 *   every subpage; `CONTENT_EDIT` on the page of a content element; the
 *   record-level rights ({@see ActsOnAnExistingRecordTrait::mayEditRecord()})
 *   on the record and every subpage. A missing record and a forbidden one
 *   return the same neutral string.
 * - **A process without a backend environment**, and a draft workspace the run
 *   did not record, through {@see WritesThroughDataHandlerTrait}.
 *
 * In the draft workspace the run was started in, the delete is core's delete
 * there (ADR-266): the record and its translations are marked for deletion
 * (a delete version, `t3ver_state` 2), live stays as it is, and publishing the
 * workspace deletes them live with everything core deletes with them. Two
 * cases are refused there, because core's delete does something else: a
 * record new in the workspace is discarded for good, and a record moved
 * there has its move discarded rather than being deleted.
 *
 * The card also names how many other records still point at the one being
 * deleted (the reference index), so a link or a shortcut that will break is
 * visible before the approval rather than after.
 */
final readonly class DeleteRecordTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, ScopedWriteInterface, RefusesBeforeApprovalInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;

    // The refusal before approval opens both entry points (ADR-266).
    use AsksItsRefusalFirstTrait;
    // Table, language, translations and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;
    // The plan's checks throw their refusal instead of returning it.
    use RefusesPlanStepsTrait;

    /**
     * One string for "no such record", "deleted" and "you may not delete it",
     * so a refusal never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Record not found or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const CONTENT_TABLE = 'tt_content';

    /**
     * The largest branch one call deletes, the page itself not counted. Core
     * would delete any size; an approval card that has to summarise hundreds
     * of pages no longer shows the approver what they approve.
     */
    private const MAX_BRANCH_PAGES = 50;

    /** `t3ver_state` of a record new in a workspace. */
    private const WORKSPACE_NEW_RECORD = 1;

    /** `t3ver_state` of a record's move version in a workspace. */
    private const WORKSPACE_MOVE_POINTER = 4;

    /** How many subpage uids the approval card lists before it says "and N more". */
    private const LISTED_UIDS = 10;

    /** How a record is named in an answer or a refusal: table and uid. */
    private const RECORD_NAME = '%s [%d]';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ApprovalPreviewTranslator $translator,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'delete_record',
            'Delete ONE page or content element. The record is flagged deleted (recoverable from the recycler), '
            . 'and what core deletes with it goes too: the translations of a default-language record, and for a '
            . 'page every record on it and every subpage. A page with subpages is refused unless include_subpages '
            . 'is true. A site root is never deleted. Writes through the TYPO3 DataHandler as the acting backend '
            . 'user, in the workspace the request was started in: in live, or in a draft workspace, where the record '
            . 'is marked for deletion and deleted live only when the workspace is published. In a draft workspace a '
            . 'record new or moved there is refused.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'enum'        => self::EXISTING_RECORD_TABLES,
                        'description' => 'pages for a page, tt_content for a content element.',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single record to delete.',
                    ],
                    'include_subpages' => [
                        'type'        => 'boolean',
                        'description' => 'Pages only: true to delete a page that has subpages together with its whole '
                            . 'branch. Omit or false to have such a page refused.',
                    ],
                ],
                'required' => ['table', 'uid'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function executeInItsWorkspace(array $arguments, ToolExecutionContext $context): ToolResult
    {
        // Asked first, as the approval scan asked it (ADR-266): a call it
        // refuses was not shown for approval and must write nothing.
        $user = $this->actingUserForTheWrite($arguments, $context, self::PAGES_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [$plan['table'] => [$plan['uid'] => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        // Read back whatever the error log says. The DataHandler declines a
        // delete without always writing to `errorLog` — and it goes on after
        // refusing one translation, so a complaint does not mean nothing was
        // deleted. The answer reports what is actually gone and what is left.
        // In a draft workspace core marks the record and its translations for
        // deletion there and leaves the rest to publishing (ADR-266): only
        // those are read back, as the workspace shows them.
        $workspace = max(0, $user->workspace);
        $survivors = [];
        foreach ($workspace > 0 ? $plan['translations'] : [...$plan['subpages'], ...$plan['translations'], ...$plan['subpageTranslations']] as [$table, $uid]) {
            if ($this->fetchRowAsTheUserSees($table, $uid, $user) !== null) {
                $survivors[] = sprintf(self::RECORD_NAME, $table, $uid);
            }
        }

        // What core deletes from the pages themselves, table by table.
        foreach ($workspace > 0 ? [] : $this->recordCountsOn($plan['pageUids'], 0) as $table => $left) {
            if ($left > 0) {
                $survivors[] = sprintf('%d %s record(s) on the deleted page(s)', $left, $table);
            }
        }

        // A page translation takes the content of its language on the
        // default-language page with it.
        if ($plan['translationOf'] > 0 && $workspace === 0) {
            $left = $this->contentCountOn([$plan['translationOf']], 0, $plan['language']);
            if ($left > 0) {
                $survivors[] = sprintf('%d content element(s) in language %d on page [%d]', $left, $plan['language'], $plan['translationOf']);
            }
        }

        $recordGone = $this->fetchRowAsTheUserSees($plan['table'], $plan['uid'], $user) === null;
        $complaints = $this->complaintsNote($dataHandler);
        if (!$recordGone) {
            $survivorNote = '';
            if ($survivors !== []) {
                $survivorNote = ', and so ' . (count($survivors) === 1 ? 'is ' : 'are ') . implode(', ', array_slice($survivors, 0, 10));
            }

            return ToolResult::error(sprintf(
                'The delete did not take: %s [%d] is still there%s.%s The acting backend user is most likely missing a '
                . 'permission core asks for.',
                $plan['table'],
                $plan['uid'],
                $survivorNote,
                $complaints,
            ));
        }

        if ($workspace > 0) {
            $incomplete = '';
            if ($survivors !== []) {
                $incomplete = sprintf(', but not completely: %s %s still there', implode(', ', array_slice($survivors, 0, 10)), count($survivors) === 1 ? 'is' : 'are');
            }

            return ToolResult::text(sprintf(
                'Marked %s [%d] "%s"%s for deletion in the workspace "%s"%s. Live is unchanged: publishing the workspace '
                . 'deletes it live, with what core deletes with it; discarding the mark in the Workspaces module brings it '
                . 'back.%s',
                $plan['table'],
                $plan['uid'],
                $this->excerpt($plan['label']),
                $plan['translations'] === [] ? '' : sprintf(' and its %d translation(s)', count($plan['translations'])),
                $this->workspaceTitleOf($user),
                $incomplete,
                $complaints,
            ))->withWriteTarget(new RecordReference($plan['table'], $plan['uid']), WriteKind::DELETED, $survivors === [] ? WriteCompleteness::COMPLETE : WriteCompleteness::PARTIAL);
        }

        if ($survivors !== []) {
            // The record IS deleted, so the answer names it as written even
            // though part of what should have gone with it did not (ADR-214:
            // partial by the read-back).
            return ToolResult::text(sprintf(
                'Deleted %s [%d] "%s", but not completely: %s%s %s still there.%s It is flagged deleted and can be restored from the recycler.',
                $plan['table'],
                $plan['uid'],
                $this->excerpt($plan['label']),
                implode(', ', array_slice($survivors, 0, 10)),
                count($survivors) > 10 ? sprintf(' and %d more', count($survivors) - 10) : '',
                count($survivors) === 1 ? 'is' : 'are',
                $complaints,
            ))->withWriteTarget(new RecordReference($plan['table'], $plan['uid']), WriteKind::DELETED, WriteCompleteness::PARTIAL);
        }

        // Everything that should have gone is gone. A complaint of TYPO3
        // beside it is named, and leaves nothing undone.
        return ToolResult::text(sprintf(
            'Deleted %s [%d] "%s"%s. It is flagged deleted and can be restored from the recycler.%s',
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $this->alongWith($plan),
            $complaints,
        ))->withWriteTarget(new RecordReference($plan['table'], $plan['uid']), WriteKind::DELETED, WriteCompleteness::COMPLETE);
    }

    /**
     * The record and everything that goes with it, as the approver reads it
     * (ADR-136).
     *
     * The lines are in the language of the ACTING user and carry no field
     * names or table names (ADR-213); a refusal line stays English, because it
     * is the string {@see self::execute()} hands the model as well.
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string. NOT checked
     * here: the backend-environment refusal, which describes the process
     * performing the write. A run that did not record its draft workspace,
     * and a delete core would not perform as one in the run's workspace, get
     * their refusal instead of the card (ADR-260, ADR-266).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewCallInItsWorkspace(array $arguments, ToolExecutionContext $context): array
    {
        // Refused whatever the approver decides: the card says so instead
        // of describing a write that will not happen (ADR-266).
        $user = $this->actingUserForTheCard($arguments, $context);
        if (!$user instanceof BackendUserAuthentication) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        [$t, $q] = $this->translator->boundTo($user, $this->excerpt(...));

        $isPage = $plan['table'] === self::PAGES_TABLE;

        // ADR-213 and the editorial guidelines' rule for destructive actions:
        // the object, where it is, its language versions, what goes with it,
        // what points at it, whether it can come back. Only what this tool
        // already reads for the plan; there is no redirect line because the
        // tool does not look at redirects.
        $lines = [
            $t($isPage ? ApprovalPreviewLabel::DeletePageHeading : ApprovalPreviewLabel::DeleteContentHeading),
            $t($isPage ? ApprovalPreviewLabel::DeletePageObject : ApprovalPreviewLabel::DeleteContentObject, $q($plan['label'])),
        ];
        if (!$isPage) {
            $lines[] = $t(ApprovalPreviewLabel::DeleteLocation, $q($plan['pageTitle']));
        }

        $lines[] = $t($plan['language'] === 0 ? ApprovalPreviewLabel::DeleteLanguageDefault : ApprovalPreviewLabel::DeleteLanguageTranslation);

        if ($plan['language'] === 0) {
            $lines[] = $plan['translations'] === []
                ? $t(ApprovalPreviewLabel::DeleteTranslationsNone)
                : $t(ApprovalPreviewLabel::DeleteTranslationsSome, count($plan['translations']));
        }

        if ($isPage) {
            if ($plan['language'] > 0) {
                $lines[] = $t(ApprovalPreviewLabel::DeleteTranslationContent, $plan['contentCount']);
            } else {
                $lines[] = $plan['subpages'] === []
                    ? $t(ApprovalPreviewLabel::DeleteSubpagesNone)
                    : $t(ApprovalPreviewLabel::DeleteSubpagesSome, count($plan['subpages']), count($plan['subpageTranslations']));
                $counts = [];
                foreach ($plan['recordCounts'] as $table => $count) {
                    $name     = $this->translator->tableLabel($user, $table);
                    $counts[] = $count < 0
                        ? $t(ApprovalPreviewLabel::DeleteStoredUncountable, $name)
                        : $name . ': ' . $count;
                }

                $lines[] = $counts === []
                    ? $t(ApprovalPreviewLabel::DeleteStoredNone)
                    : $t(ApprovalPreviewLabel::DeleteStoredSome, implode('; ', $counts));
            }
        }

        $lines[] = $plan['referencedBy'] === 0
            ? $t(ApprovalPreviewLabel::DeleteReferencesNone)
            : $t(ApprovalPreviewLabel::DeleteReferencesSome, $plan['referencedBy']);
        // In a draft workspace the delete is a mark there, undone by
        // discarding it; the recycler has nothing to restore (ADR-266).
        $lines[] = $user->workspace > 0
            ? $t(ApprovalPreviewLabel::DeleteInWorkspace, $q($this->workspaceTitleOf($user)))
            : $t(ApprovalPreviewLabel::DeleteRecoverable);

        $details = [$t(ApprovalPreviewLabel::TechnicalRecord, $plan['table'], $plan['uid'])];
        if (!$isPage) {
            $details[] = $t(ApprovalPreviewLabel::TechnicalPage, $plan['page']);
        }

        $details[] = $t(ApprovalPreviewLabel::TechnicalLanguage, $plan['language']);
        if ($plan['translations'] !== []) {
            $details[] = $t(ApprovalPreviewLabel::TechnicalTranslations, implode(', ', array_column($plan['translations'], 1)));
        }

        if ($plan['subpages'] !== []) {
            $listed    = implode(', ', array_slice(array_column($plan['subpages'], 1), 0, self::LISTED_UIDS));
            $more      = count($plan['subpages']) - self::LISTED_UIDS;
            $details[] = $t(
                ApprovalPreviewLabel::TechnicalSubpages,
                $more > 0 ? $listed . ' ' . $t(ApprovalPreviewLabel::TechnicalMore, $more) : $listed,
            );
        }

        $lines[] = $this->translator->technical($user, $details);

        return $lines;
    }

    /**
     * Refused whatever the approver decides (ADR-266): in a draft workspace
     * the run did not record, and where core does something else in the workspace.
     *
     * @param array<string, mixed> $arguments
     */
    private function refusalBeforeApprovalInItsWorkspace(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->refusalInItsWorkspace($arguments, $context, $this->refusalOfPlanInTheWorkspace(...), ...array_filter([$this->existingRecordTable($arguments)]));
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the delete permission and the record-level
        // rights are the acting user's own, checked here and enforced a second
        // time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    /**
     * The record the call deletes, with no field (ADR-214).
     */
    /**
     * What the call touches, for the scope of the run's assignment (ADR-295).
     *
     * @param array<string, mixed> $arguments
     */
    public function scopedWrite(array $arguments): ?ScopedWrite
    {
        $record = ScopedWrite::record($arguments['table'] ?? null, $arguments['uid'] ?? null);

        return $record instanceof RecordReference ? new ScopedWrite('delete', [$record], [], $record->table === 'pages') : null;
    }

    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments($arguments['table'] ?? null, $arguments['uid'] ?? null);
    }

    public function getEffect(): ToolEffect
    {
        // A delete converges: a repeat finds the record gone and deletes
        // nothing further. It fails with the neutral refusal instead of
        // succeeding again, which a reaped and requeued run may safely see.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * Everything the delete needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:'pages'|'tt_content', uid:int, label:string, page:int, pageTitle:string, language:int, translations:list<array{non-empty-string, int}>, subpages:list<array{non-empty-string, int}>, subpageTranslations:list<array{non-empty-string, int}>, pageUids:list<int>, recordCounts:array<string, int>, translationOf:int, contentCount:int, referencedBy:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        return self::planOrRefusal(fn (): array => $this->resolvePlan($arguments, $user));
    }

    /**
     * The checks of {@see self::plan()}, in order; the first that fails throws
     * its refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:'pages'|'tt_content', uid:int, label:string, page:int, pageTitle:string, language:int, translations:list<array{non-empty-string, int}>, subpages:list<array{non-empty-string, int}>, subpageTranslations:list<array{non-empty-string, int}>, pageUids:list<int>, recordCounts:array<string, int>, translationOf:int, contentCount:int, referencedBy:int}
     *
     * @throws PlanRefusedException
     */
    private function resolvePlan(array $arguments, BackendUserAuthentication $user): array
    {
        self::refuseIf($this->refuseUnknownArguments(
            $arguments,
            ['table', 'uid', 'include_subpages'],
            'deletes one page or content element',
        ));

        $table = $this->existingRecordTable($arguments);
        if ($table === null) {
            self::refuse('Refused: "table" must be "pages" or "tt_content".');
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            self::refuse('Refused: "uid" must be the positive uid of exactly one record.');
        }

        $includeSubpages = $arguments['include_subpages'] ?? false;
        if (!is_bool($includeSubpages)) {
            self::refuse('Refused: "include_subpages" must be true or false.');
        }

        if ($includeSubpages && $table !== self::PAGES_TABLE) {
            self::refuse('Refused: "include_subpages" applies to pages only.');
        }

        // As the user's workspace shows them (ADR-266); in live the live rows.
        $workspace = max(0, $user->workspace);
        $row       = $this->fetchRowAsTheUserSees($table, $uid, $user);
        if ($row === null) {
            self::refuse(self::NOT_PERMITTED);
        }

        $language = $this->languageOf($table, $row);
        $parent   = $this->translationParentOf($table, $row);
        $page     = $table === self::PAGES_TABLE ? $row : $this->fetchRowAsTheUserSees(self::PAGES_TABLE, self::toInt($row['pid'] ?? 0), $user);
        // A page translation is deleted under the default-language page's
        // delete permission, as core's canDeletePage() asks it.
        $permissionPage = $table === self::PAGES_TABLE && $parent > 0 ? $this->fetchRowAsTheUserSees(self::PAGES_TABLE, $parent, $user) : $page;
        if ($page === null || $permissionPage === null
            || !$user->doesUserHaveAccess($permissionPage, $table === self::PAGES_TABLE ? Permission::PAGE_DELETE : Permission::CONTENT_EDIT)
            || !$this->mayEditRecord($table, $row, $user)
        ) {
            self::refuse(self::NOT_PERMITTED);
        }

        if ($table === self::PAGES_TABLE && (bool) ($row['is_siteroot'] ?? false)) {
            self::refuse(sprintf(
                "Refused: page [%d] is a site root. Deleting it takes a site offline, which is an administrator's "
                . 'decision and not something this tool does.',
                $uid,
            ));
        }

        $translations = [];
        if ($language === 0) {
            $refusedLanguages = [];
            foreach ($this->translationsShownTo($table, $uid, $user) as $translation) {
                // The record-level rights, not only the language: core deletes
                // each translation under them and goes on when one is refused,
                // which would leave a half-done delete behind.
                if (!$this->mayEditRecord($table, $translation, $user)) {
                    $refusedLanguages[] = $this->languageOf($table, $translation);
                }

                $translations[] = [$table, self::toInt($translation['uid'] ?? 0)];
            }

            if ($refusedLanguages !== []) {
                self::refuse(sprintf(
                    'Refused: %s [%d] has translations in language(s) %s which the acting backend user may not edit '
                    . '(language, lock or content type), and core deletes them with it. Nothing was written.',
                    $table,
                    $uid,
                    implode(', ', array_unique($refusedLanguages)),
                ));
            }
        }

        $subpages            = [];
        $subpageTranslations = [];
        $pageUids            = [self::toInt($page['uid'] ?? 0)];
        if ($table === self::PAGES_TABLE && $parent === 0) {
            $branch = $this->branchOf($uid, $workspace);
            if ($branch === null) {
                self::refuse(sprintf(
                    'Refused: page [%d] has more than %d subpages. A branch that large is not deleted through one '
                    . 'approval; delete it in the page tree.',
                    $uid,
                    self::MAX_BRANCH_PAGES,
                ));
            }

            if ($branch !== [] && !$includeSubpages) {
                self::refuse(sprintf(
                    'Refused: page [%d] has %d subpage(s), and core deletes a page together with its whole branch. '
                    . 'Set "include_subpages" to true to delete them with it, or move them away first.',
                    $uid,
                    count($branch),
                ));
            }

            foreach ($branch as $subpage) {
                if (!$user->doesUserHaveAccess($subpage, Permission::PAGE_DELETE) || !$this->mayEditRecord(self::PAGES_TABLE, $subpage, $user)) {
                    self::refuse(sprintf(
                        'Refused: the branch below page [%d] holds a page the acting backend user may not delete, and '
                        . 'core deletes the branch whole or not at all. Nothing was written.',
                        $uid,
                    ));
                }

                $subpageUid = self::toInt($subpage['uid'] ?? 0);
                $subpages[] = [self::PAGES_TABLE, $subpageUid];
                $pageUids[] = $subpageUid;
                foreach ($this->translationsShownTo(self::PAGES_TABLE, $subpageUid, $user) as $subpageTranslation) {
                    $subpageTranslations[] = [self::PAGES_TABLE, self::toInt($subpageTranslation['uid'] ?? 0)];
                }
            }

            self::refuseIf($this->refuseWhatIsOnThePages($uid, $pageUids, $user, $workspace));
        }

        // Core discards the workspace drafts of what it deletes for good —
        // the recycler cannot bring a discarded draft back — and leaves other
        // drafts behind on a page it deletes. This tool does not do either to
        // another workspace's work as a side effect (ADR-198). Counted, never
        // named.
        // In a draft workspace the live translations too: one this workspace
        // marked for deletion already is not shown there, and the publication
        // of this delete takes it along with its other workspaces' drafts.
        $draftTranslations = array_column($translations, 1);
        if ($workspace > 0) {
            foreach ($this->translationsOf($table, $uid) as $liveTranslation) {
                $draftTranslations[] = self::toInt($liveTranslation['uid'] ?? 0);
            }

            $draftTranslations = array_values(array_unique($draftTranslations));
        }

        $drafts = $this->workspaceDraftsTouched(
            $table,
            $uid,
            $draftTranslations,
            $table === self::PAGES_TABLE && $parent === 0 ? $pageUids : [],
            array_column($subpageTranslations, 1),
            $table === self::PAGES_TABLE && $parent > 0 ? [$parent, $language] : null,
            $workspace,
        );
        if ($drafts === null) {
            self::refuse(sprintf(
                'Refused: the workspace drafts deleting %s [%d] would take along could not be counted, so the delete is '
                . 'not offered. Nothing was written.',
                $table,
                $uid,
            ));
        }

        if ($drafts > 0) {
            self::refuse(sprintf(
                'Refused: deleting %s [%d] would take along %d workspace draft(s) — core discards the versions and new '
                . 'translations of what it deletes for good, and strands the other drafts without their page or record. '
                . 'Publish or discard them in their workspace first. Nothing was written.',
                $table,
                $uid,
                $drafts,
            ));
        }

        return [
            'table'        => $table,
            'uid'          => $uid,
            'label'        => $this->labelOf($table, $row),
            'page'         => self::toInt($page['uid'] ?? 0),
            'pageTitle'    => self::toStr($page['title'] ?? ''),
            'language'     => $language,
            'translations' => $translations,
            'subpages'     => $subpages,
            // A page translation takes the content of its language on the
            // default-language page with it, as core's deleteSpecificPage()
            // deletes it.
            'contentCount' => match (true) {
                $table !== self::PAGES_TABLE => 0,
                $parent > 0                  => $this->contentCountOn([$parent], $workspace, $language),
                default                      => $this->contentCountOn($pageUids, $workspace),
            },
            'referencedBy'        => $this->referencingRecordCount($table, $uid, [[$table, $uid], ...$translations, ...$subpages]),
            'subpageTranslations' => $subpageTranslations,
            'translationOf'       => $table === self::PAGES_TABLE ? $parent : 0,
            'pageUids'            => $table === self::PAGES_TABLE && $parent === 0 ? $pageUids : [],
            'recordCounts'        => $table === self::PAGES_TABLE && $parent === 0 ? $this->recordCountsOn($pageUids, $workspace) : [],
        ];
    }

    /**
     * What went with the record, for the success line.
     *
     * @param array{translations:list<array{non-empty-string, int}>, subpages:list<array{non-empty-string, int}>} $plan
     */
    private function alongWith(array $plan): string
    {
        $parts = [];
        if ($plan['translations'] !== []) {
            $parts[] = sprintf('%d translation(s)', count($plan['translations']));
        }

        if ($plan['subpages'] !== []) {
            $parts[] = sprintf('%d subpage(s)', count($plan['subpages']));
        }

        return $parts === [] ? '' : ' with ' . implode(' and ', $parts);
    }

    /**
     * Every undeleted default-language page below a page, depth first — the
     * set core's `getSubPagesOfPage()` deletes with it — or null when it holds
     * more than {@see self::MAX_BRANCH_PAGES}. In a draft workspace as that
     * workspace shows the tree, each page under its live uid.
     *
     * @return list<array<string, mixed>>|null
     */
    private function branchOf(int $uid, int $workspace): ?array
    {
        $branch  = [];
        $pending = [$uid];
        while ($pending !== []) {
            $parent = array_shift($pending);

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::PAGES_TABLE);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            /** @var list<array<string, mixed>> $children */
            $children = $queryBuilder
                ->select('*')
                ->from(self::PAGES_TABLE)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parent, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ...$this->rowsShownIn($queryBuilder, self::PAGES_TABLE, $workspace, $this->liveVersionConstraints($queryBuilder, self::PAGES_TABLE)),
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($children as $child) {
                $child['uid'] = $this->liveUid($child);
                $branch[]     = $child;
                $pending[]    = self::toInt($child['uid'] ?? 0);
                if (count($branch) > self::MAX_BRANCH_PAGES) {
                    return null;
                }
            }
        }

        return $branch;
    }

    /**
     * The undeleted content elements on the given pages — every language, or
     * the one given — as the workspace shows them.
     *
     * @param list<int> $pageUids
     */
    private function contentCountOn(array $pageUids, int $workspace, ?int $language = null): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $constraints = [
            $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
            ...$this->rowsShownIn($queryBuilder, self::CONTENT_TABLE, $workspace, $this->liveVersionConstraints($queryBuilder, self::CONTENT_TABLE)),
        ];
        if ($language !== null) {
            $constraints[] = $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language, Connection::PARAM_INT));
        }

        return self::toInt($queryBuilder
            ->count('uid')
            ->from(self::CONTENT_TABLE)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * The refusal for what core would delete from the pages without the
     * acting user's rights, or null.
     *
     * Core deletes every record stored on a deleted page, in every language,
     * without asking for each one; before it starts, it refuses a non-admin
     * whose `tables_modify` misses one of their tables
     * ({@see DataHandler::canDeletePage()}) and whose languages miss one of
     * the pages' translations. The tool asks the same, and one thing more
     * (ADR-198): the languages of the content elements on those pages, which
     * core deletes without a language check.
     *
     * @param list<int> $pageUids the page and its branch
     */
    private function refuseWhatIsOnThePages(int $uid, array $pageUids, BackendUserAuthentication $user, int $workspace): ?string
    {
        if ($user->isAdmin()) {
            return null;
        }

        foreach ($pageUids as $pageUid) {
            foreach ($this->translationsShownTo(self::PAGES_TABLE, $pageUid, $user) as $translation) {
                if (!$this->mayEditRecord(self::PAGES_TABLE, $translation, $user)) {
                    return sprintf(
                        'Refused: a page to be deleted with page [%d] has a translation in language %d the acting backend '
                        . 'user may not edit, and core deletes it with the page. Nothing was written.',
                        $uid,
                        $this->languageOf(self::PAGES_TABLE, $translation),
                    );
                }
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $languages = $queryBuilder
            ->select('sys_language_uid')
            ->from(self::CONTENT_TABLE)
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                ...$this->rowsShownIn($queryBuilder, self::CONTENT_TABLE, $workspace, $this->liveVersionConstraints($queryBuilder, self::CONTENT_TABLE)),
            )
            ->groupBy('sys_language_uid')
            ->executeQuery()
            ->fetchFirstColumn();
        foreach ($languages as $language) {
            if (!$user->checkLanguageAccess(self::toInt($language))) {
                return sprintf(
                    'Refused: page [%d] or its branch holds content in language %d, which the acting backend user may not '
                    . 'edit, and core deletes it with the page. Nothing was written.',
                    $uid,
                    self::toInt($language),
                );
            }
        }

        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        foreach ($tca as $table => $definition) {
            $table = self::toStr($table);
            $ctrl  = is_array($definition) && is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
            if ($table === '' || $table === self::PAGES_TABLE
                || ($user->check('tables_modify', $table) && !(bool) ($ctrl['readOnly'] ?? false))
            ) {
                continue;
            }

            if ($this->holdsRecordsOn($table, $pageUids)) {
                return sprintf(
                    'Refused: page [%d] or its branch holds records of %s, which the acting backend user may not modify, '
                    . 'and core refuses to delete a page with such records on it. Nothing was written.',
                    $uid,
                    $table,
                );
            }
        }

        return null;
    }

    /**
     * The undeleted records stored on the pages, as the workspace shows them
     * (live in live), table by table —
     * every table of the TCA but `pages`, whose translations are counted on
     * their own. Tables without such records are left out; a table that
     * cannot be queried is listed as -1, "could not be counted". These are counts,
     * never titles or uids: they include records the acting user cannot see
     * (ADR-198), and the approver needs to know how much goes, not what.
     *
     * @param list<int> $pageUids
     *
     * @return array<string, int>
     */
    private function recordCountsOn(array $pageUids, int $workspace): array
    {
        if ($pageUids === []) {
            return [];
        }

        $tca    = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $counts = [];
        foreach (array_keys($tca) as $table) {
            $table = self::toStr($table);
            if ($table === '' || $table === self::PAGES_TABLE) {
                continue;
            }

            try {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $count = self::toInt($queryBuilder
                    ->count('uid')
                    ->from($table)
                    ->where(
                        $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                        ...$this->rowsShownIn($queryBuilder, $table, $workspace, $this->liveVersionConstraints($queryBuilder, $table)),
                    )
                    ->executeQuery()
                    ->fetchOne());
            } catch (Throwable) {
                // Said on the card rather than left out: an approver must not
                // read a short list as a complete one.
                $counts[$table] = -1;

                continue;
            }

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * How many workspace draft rows a delete takes along, or null when a
     * table could not be asked.
     *
     * Mirrors what core's delete does in the live workspace. It discards,
     * for every live row it deletes — the record, its translations, the
     * records on deleted pages, the language's records behind a deleted page
     * translation, and the inline and file children of each (linked by
     * `foreign_field`) — the row's versions in any workspace, a move version
     * on another page included, and the new workspace translations of it.
     * It strands the other draft rows it meets: on a deleted page, a draft
     * row of any table stored there (a new page or record of another
     * workspace); behind a deleted page translation, a draft row of its
     * language; and a new workspace translation of a deleted page. Each
     * draft row is counted once.
     *
     * In a draft workspace the delete only marks the record there; core
     * deletes live, and discards these drafts, when that workspace is
     * published (ADR-266). The drafts of the run's own workspace are its
     * own work and are not counted; every other workspace's still are.
     *
     * @param list<int>            $translations        the live translations deleted with the record
     * @param list<int>            $pageUids            for a default-language page: the page and its branch
     * @param list<int>            $subpageTranslations the live translations of the subpages
     * @param array{int, int}|null $pageTranslation     for a page translation: its default-language page and language
     */
    private function workspaceDraftsTouched(
        string $table,
        int $uid,
        array $translations,
        array $pageUids,
        array $subpageTranslations,
        ?array $pageTranslation,
        int $workspace,
    ): ?int {
        try {
            $deleted = [$table => [$uid, ...$translations]];
            if ($pageUids !== []) {
                $deleted[self::PAGES_TABLE] = [...$pageUids, ...$translations, ...$subpageTranslations];
            }

            foreach ($this->workspaceTables() as $other) {
                if ($other === self::PAGES_TABLE) {
                    continue;
                }

                if ($pageUids !== []) {
                    $deleted[$other] = [...($deleted[$other] ?? []), ...$this->liveUids($other, [['pid', $pageUids]])];
                } elseif ($pageTranslation !== null && ($languageField = $this->ctrlColumn($other, 'languageField')) !== null) {
                    $deleted[$other] = [
                        ...($deleted[$other] ?? []),
                        ...$this->liveUids($other, [['pid', [$pageTranslation[0]]], [$languageField, [$pageTranslation[1]]]]),
                    ];
                }
            }

            $deleted = $this->withInlineChildren($deleted);

            $drafts = [];
            foreach ($deleted as $name => $uids) {
                if ($uids === [] || !$this->isWorkspaceAware($name)) {
                    continue;
                }

                $drafts[$name] = [...($drafts[$name] ?? []), ...$this->draftUids($name, [['t3ver_oid', $uids]], $workspace)];
                $originPointer = $this->ctrlColumn($name, 'transOrigPointerField');
                if ($originPointer !== null) {
                    $drafts[$name] = [...$drafts[$name], ...$this->draftUids($name, [[$originPointer, $uids], ['t3ver_state', [1]]], $workspace)];
                }
            }

            foreach ($this->workspaceTables() as $name) {
                if ($pageUids !== []) {
                    $drafts[$name] = [...($drafts[$name] ?? []), ...$this->draftUids($name, [['pid', $pageUids]], $workspace)];
                } elseif ($pageTranslation !== null && $name !== self::PAGES_TABLE
                    && ($languageField = $this->ctrlColumn($name, 'languageField')) !== null
                ) {
                    $drafts[$name] = [
                        ...($drafts[$name] ?? []),
                        ...$this->draftUids($name, [['pid', [$pageTranslation[0]]], [$languageField, [$pageTranslation[1]]]], $workspace),
                    ];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return array_sum(array_map(static fn (array $uids): int => count(array_unique($uids)), $drafts));
    }

    /**
     * The given live rows and, table by table, the live inline and file
     * children core deletes with them, down every level.
     *
     * @param array<string, list<int>> $deleted
     *
     * @return array<string, list<int>>
     */
    private function withInlineChildren(array $deleted): array
    {
        $pending = $deleted;
        while ($pending !== []) {
            $next = [];
            foreach ($pending as $parentTable => $parentUids) {
                foreach ($this->inlineRelationsOf($parentTable) as [$childTable, $foreignField, $tableField]) {
                    $constraints = [[$foreignField, $parentUids]];
                    $children    = $this->liveUids($childTable, $constraints, $tableField === null ? null : [$tableField, $parentTable]);
                    $new         = array_values(array_diff($children, $deleted[$childTable] ?? []));
                    if ($new !== []) {
                        $deleted[$childTable] = [...($deleted[$childTable] ?? []), ...$new];
                        $next[$childTable]    = [...($next[$childTable] ?? []), ...$new];
                    }
                }
            }

            $pending = $next;
        }

        return $deleted;
    }

    /**
     * The inline and file columns of a table that link their children by
     * `foreign_field`: child table, foreign field, foreign table field.
     *
     * @return list<array{non-empty-string, non-empty-string, non-empty-string|null}>
     */
    private function inlineRelationsOf(string $table): array
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $columns = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['columns'] ?? null) ? $tca[$table]['columns'] : [];

        $relations = [];
        foreach ($columns as $column) {
            $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
            $type   = $config['type'] ?? null;
            if ($type !== 'inline' && $type !== 'file') {
                continue;
            }

            $childTable   = self::toStr($config['foreign_table'] ?? ($type === 'file' ? 'sys_file_reference' : ''));
            $foreignField = self::toStr($config['foreign_field'] ?? ($type === 'file' ? 'uid_foreign' : ''));
            $tableField   = self::toStr($config['foreign_table_field'] ?? ($type === 'file' ? 'tablenames' : ''));
            if ($childTable !== '' && $foreignField !== '') {
                $relations[$childTable . "\0" . $foreignField . "\0" . $tableField] = [$childTable, $foreignField, $tableField === '' ? null : $tableField];
            }
        }

        return array_values($relations);
    }

    /**
     * Every table of the TCA that declares workspace versioning.
     *
     * @return list<non-empty-string>
     */
    private function workspaceTables(): array
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];

        return array_values(array_filter(
            array_map(self::toStr(...), array_keys($tca)),
            fn (string $table): bool => $table !== '' && $this->isWorkspaceAware($table),
        ));
    }

    /**
     * Undeleted live uids of a table matching every `[column, values]` pair.
     *
     * @param list<array{string, list<int>}> $constraints
     * @param array{string, string}|null     $stringConstraint one `[column, value]` compared as a string
     *
     * @return list<int>
     */
    private function liveUids(string $table, array $constraints, ?array $stringConstraint = null): array
    {
        if (in_array([], array_column($constraints, 1), true)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $where = $this->liveVersionConstraints($queryBuilder, $table);
        foreach ($constraints as [$column, $values]) {
            $where[] = $queryBuilder->expr()->in($column, $queryBuilder->createNamedParameter($values, Connection::PARAM_INT_ARRAY));
        }

        if ($stringConstraint !== null) {
            $where[] = $queryBuilder->expr()->eq($stringConstraint[0], $queryBuilder->createNamedParameter($stringConstraint[1]));
        }

        return array_map(self::toInt(...), $queryBuilder->select('uid')->from($table)->where(...$where)->executeQuery()->fetchFirstColumn());
    }

    /**
     * Undeleted uids of a workspace-aware table in any workspace but live
     * and `$except` matching every `[column, values]` pair.
     *
     * @param list<array{string, list<int>}> $constraints
     *
     * @return list<int>
     */
    private function draftUids(string $table, array $constraints, int $except): array
    {
        if (in_array([], array_column($constraints, 1), true)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $where = [$queryBuilder->expr()->notIn('t3ver_wsid', $queryBuilder->createNamedParameter([0, $except], Connection::PARAM_INT_ARRAY))];
        foreach ($constraints as [$column, $values]) {
            $where[] = $queryBuilder->expr()->in($column, $queryBuilder->createNamedParameter($values, Connection::PARAM_INT_ARRAY));
        }

        return array_map(self::toInt(...), $queryBuilder->select('uid')->from($table)->where(...$where)->executeQuery()->fetchFirstColumn());
    }

    /**
     * Whether any undeleted record of the table is stored on the pages. A
     * table that cannot be queried holds none as far as this check goes; the
     * DataHandler asks the same again and refuses the delete.
     *
     * @param list<int> $pageUids
     */
    private function holdsRecordsOn(string $table, array $pageUids): bool
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            return self::toInt($queryBuilder
                ->count('uid')
                ->from($table)
                ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchOne()) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * How many OTHER live records the reference index says point at this
     * one — a link, a shortcut, a relation — leaving out the records that are
     * deleted together with it.
     *
     * @param list<array{non-empty-string, int}> $goingAlong
     */
    private function referencingRecordCount(string $table, int $uid, array $goingAlong): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');

        $rows = $queryBuilder
            ->select('tablename', 'recuid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('ref_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $excluded = array_map(static fn (array $record): string => $record[0] . ':' . $record[1], $goingAlong);
        $sources  = [];
        foreach ($rows as $row) {
            $source = self::toStr($row['tablename'] ?? '') . ':' . self::toInt($row['recuid'] ?? 0);
            if (!in_array($source, $excluded, true)) {
                $sources[$source] = true;
            }
        }

        return count($sources);
    }

    /**
     * {@see self::refusalInTheWorkspace()} for a plan of {@see self::plan()}.
     *
     * @param array<string, mixed> $plan
     */
    private function refusalOfPlanInTheWorkspace(array $plan, BackendUserAuthentication $user): ?string
    {
        $translations = [];
        foreach (is_array($plan['translations'] ?? null) ? $plan['translations'] : [] as $translation) {
            $translations[] = self::toInt(is_array($translation) ? ($translation[1] ?? 0) : 0);
        }

        return $this->refusalInTheWorkspace(self::toStr($plan['table'] ?? ''), self::toInt($plan['uid'] ?? 0), $translations, $user);
    }

    /**
     * The refusal of a delete core would not perform as one in the user's
     * draft workspace (ADR-266), or null — always in live.
     *
     * Core's delete in a workspace marks a live record for deletion there.
     * It does something else to two records, both measured on TYPO3 14.3 and
     * 13.4 (`DataHandler::deleteAction()`): a record NEW in the workspace is
     * discarded — removed from the database, beyond the recycler — and a
     * record MOVED there has its move discarded, so it is back where it was
     * and nothing is deleted. Neither is what the card promises. Core deletes
     * each translation of the record the same way
     * (`deleteL10nOverlayRecords()`), so the translations are asked too.
     *
     * Asked after the plan, so a uid the user may not touch keeps the
     * neutral refusal.
     *
     * @param list<int> $translations the live uids of the translations deleted with the record
     */
    private function refusalInTheWorkspace(string $table, int $uid, array $translations, BackendUserAuthentication $user): ?string
    {
        $workspace = max(0, $user->workspace);
        if (!$this->readsWorkspaceRows($table, $workspace)) {
            return null;
        }

        foreach ([$uid, ...$translations] as $candidate) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $state = $queryBuilder
                ->select('t3ver_state')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                    $this->isRecordInWorkspace($queryBuilder, $candidate),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            $what = $candidate === $uid
                ? sprintf(self::RECORD_NAME, $table, $uid)
                : sprintf('the translation %s [%d] of %s [%d]', $table, $candidate, $table, $uid);
            $refusal = match ($state === false ? null : self::toInt($state)) {
                self::WORKSPACE_NEW_RECORD => sprintf(
                    'Refused: %s is new in the workspace "%s", and deleting it there discards it for good, beyond the '
                    . 'recycler. Discard it in the Workspaces module instead. Nothing was written.',
                    $what,
                    $this->workspaceTitleOf($user),
                ),
                self::WORKSPACE_MOVE_POINTER => sprintf(
                    'Refused: %s was moved in the workspace "%s", and deleting it there discards the move instead: the '
                    . 'record would be back where it was, not deleted. Discard the move in the Workspaces module first, or '
                    . 'delete it in the live workspace. Nothing was written.',
                    $what,
                    $this->workspaceTitleOf($user),
                ),
                default => null,
            };
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $movedOut = $table === self::PAGES_TABLE ? $this->movedOutOfTheBranch($uid, $workspace) : null;

        return $movedOut === null ? null : sprintf(
            'Refused: %s lies on page [%d] or below it in the live tree but was moved away in the workspace "%s". '
            . 'Publishing the deletion of the page would delete it live from where it still is and discard the move. '
            . 'Publish or discard that move first. Nothing was written.',
            $movedOut,
            $uid,
            $this->workspaceTitleOf($user),
        );
    }

    /**
     * A record of the live branch of a page that the workspace moved out of
     * it, as `table [uid]`, or null.
     *
     * Core's delete in a workspace marks the page only. Publishing that mark
     * runs core's live delete, which deletes the live branch and every record
     * stored on it — a record whose move away was not published yet with it,
     * and its versions, the move included (`DataHandler::deleteEl()`).
     */
    private function movedOutOfTheBranch(int $uid, int $workspace): ?string
    {
        $branch   = $this->branchOf($uid, 0) ?? [];
        $pageUids = [$uid, ...array_map(static fn (array $page): int => self::toInt($page['uid'] ?? 0), $branch)];

        foreach ($this->workspaceTables() as $table) {
            $live = $table === self::PAGES_TABLE
                ? array_slice($pageUids, 1)
                : $this->liveUids($table, [['pid', $pageUids]]);
            if ($live === []) {
                continue;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $moved = $queryBuilder
                ->select('t3ver_oid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(self::WORKSPACE_MOVE_POINTER, Connection::PARAM_INT)),
                    $queryBuilder->expr()->in('t3ver_oid', $queryBuilder->createNamedParameter($live, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->notIn('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            if ($moved !== false) {
                return sprintf(self::RECORD_NAME, $table, self::toInt($moved));
            }
        }

        return null;
    }
}
