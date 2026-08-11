<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 *
 * The destination is always computed to an explicit column, never inherited
 * implicitly: the tool passes the extended paste form of the move command
 * (`['action' => 'paste', 'target' => …, 'update' => ['colPos' => …]]`), so the
 * element lands where the approval card said it would even when the anchor
 * element sits in a different column than the caller assumed.
 */
final readonly class MoveContentElementTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the three ADR-146 writers share: the plan, the viewer gate, the
    // unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;

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
    private const NOT_PERMITTED = 'Content element not found or not permitted.';

    private const TABLE = 'tt_content';

    private const PAGES_TABLE = 'pages';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'move_content_element',
            'Move ONE existing content element (tt_content) to a target page and column. The element keeps its '
            . 'uid, its content and its language — only its position changes. Writes through the TYPO3 '
            . 'DataHandler as the acting backend user, in the live workspace. The acting user must be allowed to '
            . 'edit content on BOTH the source and the target page.',
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

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment(self::TABLE)
            ?? $this->refuseOutsideLiveWorkspace($user);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [self::TABLE => [$plan['uid'] => ['move' => [
            'action' => 'paste',
            'target' => $plan['destination'],
            'update' => ['colPos' => $plan['column']],
        ]]]], $user);
        // `process_cmdmap()` applies the paste `update` itself, through a local
        // DataHandler whose errorLog it merges back — so the column write is
        // covered by the refusal below and needs no second pass here.
        $dataHandler->process_cmdmap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // Read back before reporting success. The DataHandler drops a move it
        // will not perform without always writing to `errorLog`, and a caller
        // told "moved" about an element that did not move is the worst outcome
        // for a tool whose whole point is that a human approved this move.
        $landed = $this->fetchElement($plan['uid']);
        if ($landed === null
            || self::toInt($landed['pid'] ?? 0) !== $plan['targetPage']
            || self::toInt($landed['colPos'] ?? -1) !== $plan['column']
        ) {
            return ToolResult::error(sprintf(
                'The move did not take: content element [%d] is not on page [%d] in column %d afterwards. '
                . 'The acting backend user is most likely missing an edit grant on one of the two pages.',
                $plan['uid'],
                $plan['targetPage'],
                $plan['column'],
            ));
        }

        return ToolResult::text(sprintf(
            'Moved content element [%d] "%s" from page [%d] to page [%d], column %d%s.',
            $plan['uid'],
            $this->excerpt($plan['header']),
            $plan['sourcePage'],
            $plan['targetPage'],
            $plan['column'],
            $plan['afterUid'] > 0 ? sprintf(', after element [%d]', $plan['afterUid']) : '',
        ));
    }

    /**
     * Both sides of the move as the approver reads them (ADR-136).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string — a preview can
     * never name a page or an element the run itself could not have touched.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals. Both describe the PROCESS that performs the write, which is the
     * approver's request rather than this one.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        return [
            sprintf('Content element [%d] "%s" (%s):', $plan['uid'], $this->excerpt($plan['header']), $plan['cType']),
            sprintf('from: page [%d] "%s", column %d', $plan['sourcePage'], $this->excerpt($plan['sourceTitle']), $plan['sourceColumn']),
            $plan['afterUid'] > 0
                ? sprintf(
                    'to: page [%d] "%s", column %d, directly after element [%d] "%s"',
                    $plan['targetPage'],
                    $this->excerpt($plan['targetTitle']),
                    $plan['column'],
                    $plan['afterUid'],
                    $this->excerpt($plan['afterHeader']),
                )
                : sprintf(
                    'to: page [%d] "%s", column %d, first in the column',
                    $plan['targetPage'],
                    $this->excerpt($plan['targetTitle']),
                    $plan['column'],
                ),
        ];
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
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['uid', 'target_page', 'column', 'after_content_uid'],
            'moves one content element',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive tt_content uid of exactly one content element.';
        }

        $targetPageUid = self::toInt($arguments['target_page'] ?? 0);
        if ($targetPageUid < 1) {
            return 'Refused: "target_page" must be the positive uid of exactly one page.';
        }

        // Validated here rather than where it is used: an argument the tool
        // refuses must never reach a query.
        $requestedColumn = array_key_exists('column', $arguments) ? self::toInt($arguments['column']) : null;
        if ($requestedColumn !== null && $requestedColumn < 0) {
            return 'Refused: "column" must be zero or a positive backend-layout column (colPos).';
        }

        $element = $this->fetchElement($uid);
        if ($element === null) {
            return self::NOT_PERMITTED;
        }

        $language = self::toInt($element['sys_language_uid'] ?? 0);
        if (!$user->checkLanguageAccess($language)) {
            return self::NOT_PERMITTED;
        }

        $sourcePageUid = self::toInt($element['pid'] ?? 0);
        $sourcePage    = $this->fetchPage($sourcePageUid);
        $targetPage    = $this->fetchPage($targetPageUid);
        if ($sourcePage === null || $targetPage === null) {
            return self::NOT_PERMITTED;
        }

        // Both ends. Moving an element OUT of a page edits that page's content
        // just as much as moving one in, so one grant is not enough.
        if (!$user->doesUserHaveAccess($sourcePage, Permission::CONTENT_EDIT)
            || !$user->doesUserHaveAccess($targetPage, Permission::CONTENT_EDIT)
        ) {
            return self::NOT_PERMITTED;
        }

        $afterUid    = self::toInt($arguments['after_content_uid'] ?? 0);
        $afterHeader = '';
        $afterColumn = null;
        if ($afterUid > 0) {
            if ($afterUid === $uid) {
                return 'Refused: "after_content_uid" cannot be the element being moved.';
            }

            $anchor = $this->fetchElement($afterUid);
            if ($anchor === null || self::toInt($anchor['pid'] ?? 0) !== $targetPageUid) {
                return sprintf(
                    'Refused: content element [%d] is not on page [%d], so it cannot anchor the move.',
                    $afterUid,
                    $targetPageUid,
                );
            }

            if (self::toInt($anchor['sys_language_uid'] ?? 0) !== $language) {
                return sprintf(
                    'Refused: content element [%d] is in a different language than the element being moved.',
                    $afterUid,
                );
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
     * A content element row, or null when no undeleted element carries that uid.
     *
     * @return array<string, mixed>|null
     */
    private function fetchElement(int $uid): ?array
    {
        return $this->fetchRowByUid(self::TABLE, $uid, 'uid', 'pid', 'colPos', 'header', 'CType', 'sys_language_uid');
    }

    /**
     * A page row, or null when no undeleted page carries that uid.
     *
     * The whole row, because {@see BackendUserAuthentication::doesUserHaveAccess()}
     * reads the permission columns off it.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid): ?array
    {
        return $this->fetchRowByUid(self::PAGES_TABLE, $uid);
    }
}
