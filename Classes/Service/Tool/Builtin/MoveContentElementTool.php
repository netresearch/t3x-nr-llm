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
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\Exception\PlanRefusedException;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Move ONE content element to a page and a column, through the DataHandler, as
 * the acting backend user (ADR-146).
 *
 * The move is the one editorial write that creates nothing and destroys
 * nothing: the same record keeps its uid, its content, its history and its
 * references, and only its place changes. That is what makes it a safe third
 * writer — everything an approver must judge is "from here to there", and both
 * sides are named on the card (ADR-136).
 *
 * What it refuses, and why:
 *
 * - **Anything but `tt_content`.** A generic "move a record" tool is the
 *   question #692 keeps open deliberately; this one moves content elements.
 * - **A source or target page the acting user may not edit content on.** Both
 *   ends are checked with {@see Permission::CONTENT_EDIT} against the EXPLICIT
 *   acting user (ADR-083). Moving OUT of a page is an edit of that page's
 *   content just as much as moving in.
 * - **An element in a language the acting user has no access to.** The move
 *   does not change the language, but a user who may not edit a language may
 *   not relocate its elements either.
 * - **An `after_content_uid` that is not on the target page**, or is in another
 *   language. A model that names an anchor from the wrong page has misread the
 *   page tree, and placing the element "somewhere" instead would be a silent
 *   correction of a wrong instruction.
 * - **A process without a backend environment**, and a draft workspace the run
 *   did not record, through {@see WritesThroughDataHandlerTrait}.
 *
 * In the draft workspace the run was started in, the move is core's move
 * there (ADR-266): a move version of the element, its translations moved
 * along, and live unchanged until the workspace is published. Element,
 * pages and anchor are read as that workspace shows them — an element or a
 * page new or moved there included, another workspace's draft never.
 *
 * The destination is always computed to an explicit column, never inherited
 * implicitly: the tool passes the extended paste form of the move command
 * (`['action' => 'paste', 'target' => …, 'update' => ['colPos' => …]]`), so the
 * element lands where the approval card said it would even when the anchor
 * element sits in a different column than the caller assumed.
 */
final readonly class MoveContentElementTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, EditorActionInterface, RefusesBeforeApprovalInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the three ADR-146 writers share: the plan, the viewer gate, the
    // unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;

    // The refusal before approval opens both entry points (ADR-266).
    use AsksItsRefusalFirstTrait;
    // The plan's checks throw their refusal instead of returning it.
    use RefusesPlanStepsTrait;

    // The column's order as the run's workspace shows it, for the read-back.
    use ReadsTheRunsWorkspaceTrait;

    /**
     * One string for "no such element", "deleted", "on a page you may not edit"
     * and "in a language you may not touch", so a refusal never confirms that a
     * uid exists.
     *
     * It is this tool's own rather than a read tool's: no read tool addresses a
     * single content element BY ITS UID — `get_page_content` denies by page —
     * so there is no pairing to preserve, and a page-shaped refusal would be
     * the wrong sentence for an element-shaped argument.
     */
    /** Where {@see self::whereItLanded()} finds the element after the move. */
    private const LANDED_IN_PLACE = 'in place';

    private const LANDED_OUT_OF_PLACE = 'out of place';

    private const LANDED_ELSEWHERE = 'elsewhere';

    private const NOT_PERMITTED = 'Content element not found or not permitted.';

    private const TABLE = 'tt_content';

    private const PAGES_TABLE = 'pages';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ApprovalPreviewTranslator $translator,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'move_content_element',
            'Move ONE existing content element (tt_content) to a target page and column. The element keeps its '
            . 'uid, its content and its language — only its position changes. Writes through the TYPO3 '
            . 'DataHandler as the acting backend user, in the workspace the request was started in: in live, or as a '
            . 'move in a draft workspace, where live stays unchanged until the workspace is published. Pages and '
            . 'elements are taken as that workspace shows them. The acting user must be allowed to edit content on '
            . 'BOTH the source and the target page.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The tt_content uid of the single element to move.',
                    ],
                    'target_page' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the page the element should end up on.',
                    ],
                    'column' => [
                        'type'        => 'integer',
                        'description' => 'The backend layout column (colPos) to move into. Omit to keep the '
                            . 'element\'s current column, or to adopt the column of "after_content_uid".',
                    ],
                    'after_content_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the element directly after this content element. It must be on '
                            . 'the target page and in the same language. Omit to place it first in the column.',
                    ],
                ],
                'required' => ['uid', 'target_page'],
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
        $user = $this->actingUserForTheWrite($arguments, $context, self::TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [self::TABLE => [$plan['uid'] => ['move' => [
            'action' => 'paste',
            'target' => $plan['destination'],
            'update' => ['colPos' => $plan['column']],
        ]]]], $user);
        // `process_cmdmap()` applies the paste `update` itself, through a local
        // DataHandler whose errorLog it merges back — so the column write is
        // covered by the read-back below and needs no second pass here.
        $dataHandler->process_cmdmap();

        // Read back whatever the error log says (ADR-198). The DataHandler
        // drops a move it will not perform without always writing to
        // `errorLog`, and a caller told "moved" about an element that did not
        // move is the worst outcome for a tool whose whole point is that a
        // human approved this move. A complaint can be about another record of
        // the run while this one moved — so the read-back sees the position
        // too: a move on the element's own page and column that core refused
        // leaves page and column as asked.
        // In a draft workspace the move version, read through the live uid
        // (ADR-266).
        $landed = $this->whereItLanded($plan, $user);
        $place  = $plan['afterUid'] > 0 ? sprintf('directly after element [%d]', $plan['afterUid']) : 'first on the page';

        // Not where it was asked, and nothing of the move took: on another
        // page or column, or a reorder on its own page and column that did
        // not happen.
        if ($landed === self::LANDED_ELSEWHERE
            || ($landed === self::LANDED_OUT_OF_PLACE && $plan['sourcePage'] === $plan['targetPage'] && $plan['sourceColumn'] === $plan['column'])
        ) {
            return ToolResult::error(sprintf(
                'The move did not take: content element [%d] is not on page [%d] in column %d %s afterwards. '
                . 'The acting backend user is most likely missing an edit grant on one of the two pages.%s',
                $plan['uid'],
                $plan['targetPage'],
                $plan['column'],
                $place,
                $this->complaintsNote($dataHandler),
            ));
        }

        // On the page and in the column asked for, but not at the place:
        // the element moved, so the answer names it as written in part.
        if ($landed === self::LANDED_OUT_OF_PLACE) {
            return ToolResult::text(sprintf(
                'Moved content element [%d] "%s" from page [%d] to page [%d], column %d in part: it is not %s.%s%s',
                $plan['uid'],
                $this->excerpt($plan['header']),
                $plan['sourcePage'],
                $plan['targetPage'],
                $plan['column'],
                $place,
                $this->draftWorkspaceNote($user),
                $this->complaintsNote($dataHandler),
            ))->withWriteTarget(new RecordReference(self::TABLE, $plan['uid']), WriteKind::TEXT_UNCHANGED, WriteCompleteness::PARTIAL);
        }

        return ToolResult::text(sprintf(
            'Moved content element [%d] "%s" from page [%d] to page [%d], column %d%s.%s%s',
            $plan['uid'],
            $this->excerpt($plan['header']),
            $plan['sourcePage'],
            $plan['targetPage'],
            $plan['column'],
            $plan['afterUid'] > 0 ? sprintf(', after element [%d]', $plan['afterUid']) : '',
            $this->draftWorkspaceNote($user),
            $this->complaintsNote($dataHandler),
        ))->withWriteTarget(new RecordReference(self::TABLE, $plan['uid']), WriteKind::TEXT_UNCHANGED, WriteCompleteness::COMPLETE);
    }

    /**
     * Both sides of the move as the approver reads them (ADR-136).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string — a preview can
     * never name a page or an element the run itself could not have touched.
     *
     * NOT checked here, deliberately: the backend-environment refusal, which
     * describes the PROCESS that performs the write. A run that did not record
     * its draft workspace gets that refusal instead of the card (ADR-260).
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

        // ADR-213, in the order of the editorial guidelines: what, which
        // element, where it is now, where it goes.
        $details = [
            $t(ApprovalPreviewLabel::TechnicalRecord, self::TABLE, $plan['uid']),
            $t(ApprovalPreviewLabel::TechnicalContentType, $plan['cType']),
            $t(ApprovalPreviewLabel::TechnicalCurrentPage, $plan['sourcePage']),
            $t(ApprovalPreviewLabel::TechnicalCurrentColumn, $plan['sourceColumn']),
            $t(ApprovalPreviewLabel::TechnicalTargetPage, $plan['targetPage']),
            $t(ApprovalPreviewLabel::TechnicalColumn, $plan['column']),
        ];
        if ($plan['afterUid'] > 0) {
            $details[] = $t(ApprovalPreviewLabel::TechnicalAnchorElement, $plan['afterUid']);
        }

        return [
            $t(ApprovalPreviewLabel::MoveContentHeading),
            $t(ApprovalPreviewLabel::ObjectContent, $q($plan['header'])),
            $t(ApprovalPreviewLabel::ContentType, $this->translator->itemLabel($user, self::TABLE, 'CType', $plan['cType'])),
            $t(ApprovalPreviewLabel::MoveContentCurrent, $q($plan['sourceTitle']), $this->translator->contentColumnLabel($user, $plan['sourceColumn'])),
            $plan['afterUid'] > 0
                ? $t(ApprovalPreviewLabel::MoveContentNewAfter, $q($plan['targetTitle']), $this->translator->contentColumnLabel($user, $plan['column']), $q($plan['afterHeader']))
                : $t(ApprovalPreviewLabel::MoveContentNewFirst, $q($plan['targetTitle']), $this->translator->contentColumnLabel($user, $plan['column'])),
            ...array_filter([$this->workspaceLine($t, $q, $context, $user)], is_string(...)),
            $this->translator->technical($user, $details),
        ];
    }

    /**
     * Refused whatever the approver decides (ADR-266): in a draft workspace
     * the run did not record.
     *
     * @param array<string, mixed> $arguments
     */
    private function refusalBeforeApprovalInItsWorkspace(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->refusalInItsWorkspace($arguments, $context, null, self::TABLE);
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: both pages are authorised against the acting
        // user's own content-edit permission, and the DataHandler enforces the
        // same permissions a second time inside the move.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    /**
     * The content element the call moves, with no field (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::TABLE, $arguments['uid'] ?? null);
    }

    public function getEffect(): ToolEffect
    {
        // Moving an element to a named page, column and anchor converges: the
        // second move finds the element already there and puts it in the same
        // place. Nothing is created and nothing accumulates, so a reaped and
        // requeued run may safely repeat it.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * `tt_content` is the subject: `uid` is the element an editor selects. The
     * second required argument, `target_page`, is NOT derivable from that
     * subject — no read tool is offered beside this one — so the destination
     * can only come from the editor's free-text note. The human description
     * says so; the approval card shows the destination the preview resolved,
     * which is where a guessed page is caught.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.move_content_element.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.move_content_element.description',
            'nrllm-editor-action-move-content',
            [self::TABLE],
        );
    }

    /**
     * Everything the move needs, resolved and authorised — or the refusal
     * message that stops it.
     *
     * One method for both `execute()` and `previewCall()` on purpose: the
     * approver must read the destination the write will actually use, and two
     * implementations of "where does this land" would eventually disagree.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{uid:int, header:string, cType:string, sourcePage:int, sourceTitle:string, sourceColumn:int, targetPage:int, targetTitle:string, column:int, afterUid:int, afterHeader:string, destination:int}|string
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
     * @return array{uid:int, header:string, cType:string, sourcePage:int, sourceTitle:string, sourceColumn:int, targetPage:int, targetTitle:string, column:int, afterUid:int, afterHeader:string, destination:int}
     *
     * @throws PlanRefusedException
     */
    private function resolvePlan(array $arguments, BackendUserAuthentication $user): array
    {
        self::refuseIf($this->refuseUnknownArguments(
            $arguments,
            ['uid', 'target_page', 'column', 'after_content_uid'],
            'moves one content element',
        ));

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            self::refuse('Refused: "uid" must be the positive tt_content uid of exactly one content element.');
        }

        $targetPageUid = self::toInt($arguments['target_page'] ?? 0);
        if ($targetPageUid < 1) {
            self::refuse('Refused: "target_page" must be the positive uid of exactly one page.');
        }

        // Validated here rather than where it is used: an argument the tool
        // refuses must never reach a query.
        $requestedColumn = array_key_exists('column', $arguments) ? self::toInt($arguments['column']) : null;
        if ($requestedColumn !== null && $requestedColumn < 0) {
            self::refuse('Refused: "column" must be zero or a positive backend-layout column (colPos).');
        }

        // As the user's workspace shows them (ADR-266): the element where it
        // is there, a page new there, never another workspace's draft.
        $element = $this->fetchElement($uid, $user);
        if ($element === null) {
            self::refuse(self::NOT_PERMITTED);
        }

        $language = self::toInt($element['sys_language_uid'] ?? 0);
        if (!$user->checkLanguageAccess($language)) {
            self::refuse(self::NOT_PERMITTED);
        }

        $sourcePageUid = self::toInt($element['pid'] ?? 0);
        $sourcePage    = $this->fetchPage($sourcePageUid, $user);
        $targetPage    = $this->fetchPage($targetPageUid, $user);
        if ($sourcePage === null || $targetPage === null) {
            self::refuse(self::NOT_PERMITTED);
        }

        // Both ends. Moving an element OUT of a page edits that page's content
        // just as much as moving one in, so one grant is not enough.
        if (!$user->doesUserHaveAccess($sourcePage, Permission::CONTENT_EDIT)
            || !$user->doesUserHaveAccess($targetPage, Permission::CONTENT_EDIT)
        ) {
            self::refuse(self::NOT_PERMITTED);
        }

        $afterUid    = self::toInt($arguments['after_content_uid'] ?? 0);
        $afterHeader = '';
        $afterColumn = null;
        if ($afterUid > 0) {
            if ($afterUid === $uid) {
                self::refuse('Refused: "after_content_uid" cannot be the element being moved.');
            }

            $anchor = $this->fetchElement($afterUid, $user);
            if ($anchor === null || self::toInt($anchor['pid'] ?? 0) !== $targetPageUid) {
                self::refuse(sprintf(
                    'Refused: content element [%d] is not on page [%d], so it cannot anchor the move.',
                    $afterUid,
                    $targetPageUid,
                ));
            }

            if (self::toInt($anchor['sys_language_uid'] ?? 0) !== $language) {
                self::refuse(sprintf(
                    'Refused: content element [%d] is in a different language than the element being moved.',
                    $afterUid,
                ));
            }

            $afterHeader = self::toStr($anchor['header'] ?? '');
            $afterColumn = self::toInt($anchor['colPos'] ?? 0);
        }

        $sourceColumn = self::toInt($element['colPos'] ?? 0);
        // An explicit column wins; otherwise the anchor's column, so "after that
        // element" means beside it and not in a column of its own; otherwise the
        // column the element already sits in.
        $column = $requestedColumn ?? ($afterColumn ?? $sourceColumn);

        return [
            'uid'          => $uid,
            'header'       => self::toStr($element['header'] ?? ''),
            'cType'        => self::toStr($element['CType'] ?? ''),
            'sourcePage'   => $sourcePageUid,
            'sourceTitle'  => self::toStr($sourcePage['title'] ?? ''),
            'sourceColumn' => $sourceColumn,
            'targetPage'   => $targetPageUid,
            'targetTitle'  => self::toStr($targetPage['title'] ?? ''),
            'column'       => $column,
            'afterUid'     => $afterUid,
            'afterHeader'  => $afterHeader,
            // The DataHandler's own convention: a positive destination is a page,
            // a negative one is "directly after the record with that uid".
            'destination' => $afterUid > 0 ? -$afterUid : $targetPageUid,
        ];
    }

    /**
     * Where the element sits after the move, as the user's workspace shows
     * it: {@see self::LANDED_IN_PLACE} on the target page, in the column and
     * at the place asked for; {@see self::LANDED_OUT_OF_PLACE} on the page and
     * in the column but elsewhere in the order; {@see self::LANDED_ELSEWHERE}
     * otherwise.
     *
     * The place is core's: it sorts a moved element across the whole page,
     * whatever the column — directly after the anchor, or first on the page
     * without one (`DataHandler::getSortNumber()`) — so the order is read
     * among the elements of the page in the element's language, every column
     * included. Rows that share a sorting value are taken as one place: an
     * anchor among them counts as directly before.
     *
     * @param array{uid:int, targetPage:int, column:int, afterUid:int} $plan
     *
     * @return self::LANDED_*
     */
    private function whereItLanded(array $plan, BackendUserAuthentication $user): string
    {
        $landed = $this->fetchElement($plan['uid'], $user);
        if ($landed === null
            || self::toInt($landed['pid'] ?? 0) !== $plan['targetPage']
            || self::toInt($landed['colPos'] ?? -1) !== $plan['column']
        ) {
            return self::LANDED_ELSEWHERE;
        }

        $sorting  = self::toInt($landed['sorting'] ?? 0);
        $siblings = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $siblings->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $siblings
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $siblings->expr()->eq('pid', $siblings->createNamedParameter($plan['targetPage'], Connection::PARAM_INT)),
                $siblings->expr()->eq('sys_language_uid', $siblings->createNamedParameter(self::toInt($landed['sys_language_uid'] ?? 0), Connection::PARAM_INT)),
                $siblings->expr()->lt('sorting', $siblings->createNamedParameter($sorting, Connection::PARAM_INT)),
                ...$this->rowsShownIn($siblings, self::TABLE, max(0, $user->workspace), $this->liveVersionConstraints($siblings, self::TABLE)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // The elements directly before this one: those at the highest sorting
        // below it, under their live uids.
        $highest = null;
        $before  = [];
        foreach ($rows as $row) {
            $rowSorting = self::toInt($row['sorting'] ?? 0);
            if ($this->liveUid($row) === $plan['uid'] || ($highest !== null && $rowSorting < $highest)) {
                continue;
            }

            if ($highest === null || $rowSorting > $highest) {
                $highest = $rowSorting;
                $before  = [];
            }

            $before[] = $this->liveUid($row);
        }

        $inPlace = $plan['afterUid'] > 0 ? in_array($plan['afterUid'], $before, true) : $before === [];

        return $inPlace ? self::LANDED_IN_PLACE : self::LANDED_OUT_OF_PLACE;
    }

    /**
     * A content element row as the user's workspace shows it, or null when
     * no undeleted element carries that uid there.
     *
     * @return array<string, mixed>|null
     */
    private function fetchElement(int $uid, BackendUserAuthentication $user): ?array
    {
        return $this->fetchRowAsTheUserSees(self::TABLE, $uid, $user);
    }

    /**
     * A page row as the user's workspace shows it, or null when no undeleted
     * page carries that uid there.
     *
     * The whole row, because {@see BackendUserAuthentication::doesUserHaveAccess()}
     * reads the permission columns off it.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid, BackendUserAuthentication $user): ?array
    {
        return $this->fetchRowAsTheUserSees(self::PAGES_TABLE, $uid, $user);
    }
}
