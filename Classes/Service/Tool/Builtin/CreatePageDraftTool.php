<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
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
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Create ONE hidden standard page under a parent page, through the
 * DataHandler, as the acting backend user (ADR-180).
 *
 * The sixth writing tool, and the first that creates a PAGE — the one record
 * whose existence changes what a site is. Everything about it keeps that
 * commitment as small as a page can be:
 *
 * - **The page is always hidden.** As with {@see CreateContentElementDraftTool},
 *   "draft" is the whole proposition: a model-drafted page must be read by a
 *   human in the page module before any visitor sees it, and the approval that
 *   let the tool run approved a draft, not a publication. There is no argument
 *   to switch this off.
 * - **The page type is fixed.** A standard page (``doktype`` 1) and nothing
 *   else — no shortcut, no mount point, no link, no folder. Those carry
 *   configuration (a target, a mount, a URL) rather than content, and a model
 *   that needs one needs a different conversation with an editor first.
 * - **The field set is fixed.** Title, navigation title, position. Not a
 *   generic record API — ``slug`` is left to the DataHandler's own generator,
 *   and ``hidden``, ``doktype``, ``fe_group``, ``perms_*``, ``is_siteroot``,
 *   ``TSconfig``, ``backend_layout*`` and every other page field are out of
 *   reach, so the page comes into being exactly as a new page from the
 *   backend's "new page" wizard would, before anyone edited its properties.
 * - **Default language only.** A page in another language is a translation of
 *   an existing page, which has a parent and a tool of its own:
 *   {@see CreateTranslationDraftTool}.
 *
 * It creates the page and NOTHING on it. ADR-146's one-record rule holds: the
 * approver must be able to read the whole of what one call brings into being,
 * and a page with a first element would be two records and two judgements in
 * one card. A model that wants both calls this tool and then
 * {@see CreateContentElementDraftTool} on the page it was given.
 */
final readonly class CreatePageDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the ADR-146 writers share; ADR-146 asks a sixth writer to use it.
    use PlansOneEditorialWriteTrait;

    /**
     * One string for "no such page", "deleted" and "you may not create pages
     * there", so a refusal never confirms that a page uid exists. Shared with
     * the other page-writing and page-reading tools.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const TABLE = 'pages';

    /** The only page type this tool creates. */
    private const DOKTYPE = PageRepository::DOKTYPE_DEFAULT;

    /** Upper bound for the title and the navigation title. Both core columns are `varchar(255)`. */
    private const MAX_TITLE_LENGTH = 255;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_page_draft',
            'Create ONE new standard page under a parent page. The page is always created HIDDEN, so a human must '
            . 'review and unhide it in the page module before it is visible. Writes through the TYPO3 DataHandler '
            . 'as the acting backend user, in the live workspace and in the default language. The page is created '
            . 'empty: to put content on it, call create_content_element_draft with the uid this tool returns. To '
            . 'translate an EXISTING page, use create_translation_draft instead.',
            [
                'type'       => 'object',
                'properties' => [
                    'parent' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the page to create the new page under.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'The page title. Required — it is how a human recognises the draft, and the '
                            . 'URL segment is derived from it.',
                    ],
                    'nav_title' => [
                        'type'        => 'string',
                        'description' => 'A shorter title for menus. Omit to use the page title.',
                    ],
                    'after_page_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the new page directly after this page. It must be a subpage of the '
                            . 'same parent. Omit to place it first among the subpages.',
                    ],
                ],
                'required' => ['parent', 'title'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context, self::TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $record = [
            'pid'     => $plan['destination'],
            'title'   => $plan['title'],
            'doktype' => self::DOKTYPE,
            // Always the default language — stated explicitly because the
            // DataHandler's own permission check on a NEW page reads it from
            // the incoming fields, and a non-admin is refused when it is missing.
            'sys_language_uid' => 0,
            // Never negotiable; see the class docblock.
            $this->hiddenField() => 1,
        ];
        if ($plan['navTitle'] !== null) {
            $record['nav_title'] = $plan['navTitle'];
        }

        $newUid = $this->createRecord(
            self::TABLE,
            $record,
            $user,
            'The page was not created. The acting backend user is most likely missing the grant to create '
            . 'pages under that parent.',
        );
        if ($newUid instanceof ToolResult) {
            return $newUid;
        }

        // Read back before reporting success. A uid is proof that a row exists,
        // not that it carries what was asked for: the DataHandler SKIPS a field
        // the acting user lacks the `non_exclude_fields` grant for, silently and
        // without logging. For `hidden` that is not a reporting problem but a
        // safety one — the page would be live in the site, which is the one
        // outcome this tool exists to prevent.
        $stored = $this->fetchPage($newUid);
        if ($stored === null
            || self::toInt($stored['pid'] ?? 0) !== $plan['parent']
            || self::toInt($stored['doktype'] ?? 0) !== self::DOKTYPE
            || self::toInt($stored[$this->hiddenField()] ?? 0) !== 1
        ) {
            // Take it back. A half-made page nobody approved is worse than no
            // page, and leaving it for a human to find is not a remedy when the
            // failure mode is "it is already reachable".
            $removed = $this->discard($newUid, $user);

            return ToolResult::error(sprintf(
                'Page [%d] was created but did not carry what was asked for (parent, type or hidden state '
                . 'differ), so it %s. The acting backend user is most likely missing the field-level '
                . '("exclude field") grant for %s:%s.',
                $newUid,
                $removed ? 'was deleted again' : 'COULD NOT BE DELETED and may be reachable — remove it by hand',
                self::TABLE,
                $this->hiddenField(),
            ));
        }

        $slug = self::toStr($stored['slug'] ?? '');

        return ToolResult::text(sprintf(
            'Created hidden page [%d] "%s" under page [%d]%s. It is not visible until a human unhides it. '
            . 'To put content on it, call create_content_element_draft with page %d.',
            $newUid,
            $this->excerpt($plan['title']),
            $plan['parent'],
            $slug !== '' ? sprintf(' (URL segment %s)', $slug) : '',
            $newUid,
        ))->withWriteTarget(new RecordReference(self::TABLE, $newUid));
    }

    /**
     * What this call would create, as the approver reads it (ADR-136).
     *
     * There is no "before" — the page does not exist yet — so the card shows
     * the whole of what would come into being, which is exactly the set of
     * arguments the model chose.
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals, which describe the process performing the write.
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
            sprintf('New page under page [%d] "%s":', $plan['parent'], $this->excerpt($plan['parentTitle'])),
            sprintf('title: %s', $this->quoted($plan['title'])),
            $plan['navTitle'] === null
                ? 'navigation title: (same as the title)'
                : sprintf('navigation title: %s', $this->quoted($plan['navTitle'])),
            sprintf(
                'position: %s',
                $plan['afterUid'] > 0
                    ? sprintf('directly after page [%d] "%s"', $plan['afterUid'], $this->excerpt($plan['afterTitle']))
                    : 'first among the subpages',
            ),
            'type: standard page, default language, no content yet',
            'visibility: hidden — a human must unhide it before anyone sees it',
        ];
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the parent is authorised against the acting
        // user's own page-new permission, and the DataHandler enforces the same
        // permission, the table grant and the page-type grant a second time
        // inside the creation.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // A creation with no caller-supplied key: running it twice leaves two
        // pages, not one. A reaped run that may already have created the page
        // must fail terminally rather than draft it again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * The subject is the PARENT page: `recordTypes` names the table whose uid
     * the arguments identify, and the only record identifier this tool
     * requires is `parent`. The row it writes is a new `pages` row; the record
     * an editor selects to get there is the page it will sit under.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_page_draft.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_page_draft.description',
            'nrllm-editor-action-create-page',
            [self::TABLE],
        );
    }

    /**
     * Everything the creation needs, resolved and authorised — or the refusal
     * message that stops it.
     *
     * One method for both {@see self::execute()} and {@see self::previewCall()}:
     * the approver must read the page the write will actually produce.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{parent:int, parentTitle:string, title:string, navTitle:string|null, afterUid:int, afterTitle:string, destination:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['parent', 'title', 'nav_title', 'after_page_uid'],
            'creates one hidden standard page',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $parentUid = self::toInt($arguments['parent'] ?? 0);
        if ($parentUid < 1) {
            return 'Refused: "parent" must be the positive uid of exactly one page.';
        }

        $title = $this->text($arguments, 'title');
        if (is_string($title)) {
            return $title;
        }

        if ($title[0] === '') {
            return 'Refused: "title" is required and must not be empty — it is how a human recognises the draft.';
        }

        $navTitle = null;
        if (array_key_exists('nav_title', $arguments)) {
            $nav = $this->text($arguments, 'nav_title');
            if (is_string($nav)) {
                return $nav;
            }

            $navTitle = $nav[0];
        }

        // A page is created in the default language only; a user without the
        // right to edit it may not draft one either.
        if (!$user->checkLanguageAccess(0)) {
            return 'Refused: you may not edit content in the default language.';
        }

        $parent = $this->fetchPage($parentUid);
        if ($parent === null || !$user->doesUserHaveAccess($parent, Permission::PAGE_NEW)) {
            return self::NOT_PERMITTED;
        }

        $afterUid   = self::toInt($arguments['after_page_uid'] ?? 0);
        $afterTitle = '';
        if ($afterUid > 0) {
            $anchor = $this->fetchPage($afterUid);
            if ($anchor === null || self::toInt($anchor['pid'] ?? 0) !== $parentUid) {
                return sprintf(
                    'Refused: page [%d] is not a subpage of page [%d], so it cannot anchor the new page.',
                    $afterUid,
                    $parentUid,
                );
            }

            $afterTitle = self::toStr($anchor['title'] ?? '');
        }

        return [
            'parent'      => $parentUid,
            'parentTitle' => self::toStr($parent['title'] ?? ''),
            'title'       => $title[0],
            'navTitle'    => $navTitle,
            'afterUid'    => $afterUid,
            'afterTitle'  => $afterTitle,
            // The DataHandler's own convention: a positive pid is the parent, a
            // negative one is "directly after the record with that uid".
            'destination' => $afterUid > 0 ? -$afterUid : $parentUid,
        ];
    }

    /**
     * Delete a page this tool created but could not vouch for, reporting
     * whether it is gone.
     *
     * Through the DataHandler under the same acting user, so the row goes to
     * `deleted = 1` and the removal is in `sys_log` next to the creation rather
     * than appearing out of nowhere.
     */
    private function discard(int $uid, BackendUserAuthentication $user): bool
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [self::TABLE => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        return $this->fetchPage($uid) === null;
    }

    /**
     * A validated text argument WRAPPED in a one-element list, or a refusal
     * message.
     *
     * Wrapped for the same reason {@see CreateContentElementDraftTool::text()}
     * wraps: both a valid value and a refusal are strings, and a title that
     * happened to read like a refusal must not be treated as one.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list{string}|string
     */
    private function text(array $arguments, string $field): array|string
    {
        $raw = $arguments[$field] ?? null;
        if ($raw === null) {
            return sprintf('Refused: "%s" is required.', $field);
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            return sprintf('Refused: the value for "%s" must be a string.', $field);
        }

        $text = trim(self::toStr($raw));
        if (mb_strlen($text) > self::MAX_TITLE_LENGTH) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $field, self::MAX_TITLE_LENGTH);
        }

        return [$text];
    }

    /**
     * The name of the pages table's "hidden" column as the installation
     * declares it.
     *
     * Read from the TCA rather than hardcoded because it is the field that
     * makes this a DRAFT tool: an installation that renamed it must not end up
     * with a reachable page and a success message that claims otherwise.
     *
     * @return non-empty-string
     */
    private function hiddenField(): string
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? ($tca[self::TABLE]['ctrl'] ?? null) : null;
        $cols = is_array($ctrl) ? ($ctrl['enablecolumns'] ?? null) : null;
        $name = is_array($cols) ? ($cols['disabled'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'hidden';
    }

    /**
     * A page row, or null when no undeleted page carries that uid.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid): ?array
    {
        return $this->fetchRowByUid(self::TABLE, $uid);
    }
}
