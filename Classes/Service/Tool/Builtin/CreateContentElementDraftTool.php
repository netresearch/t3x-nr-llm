<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
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
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Create ONE hidden content element on a page, through the DataHandler, as the
 * acting backend user (ADR-146).
 *
 * The fourth writing tool and the first that CREATES a record. Everything about
 * it is arranged so that the creation is the smallest possible commitment:
 *
 * - **The element is always hidden.** "Draft" is the whole proposition — a
 *   model-drafted element must be read by a human in the page module before any
 *   visitor sees it, and the approval that let the tool run approved a draft,
 *   not a publication. There is no argument to switch this off; publishing is a
 *   separate act with a separate audience, and this tool does not perform it.
 * - **The content types are read from the live TCA under an exclusion rule
 *   (ADR-196).** Every `CType` an installation declares is offered unless it is
 *   on the deny-list — `list` (the legacy plugin element), `html` (raw output),
 *   `shortcut`, `div`, every `menu_*` — or its item sits in the `plugins` group
 *   `addPlugin()` registers a plugin in, or its form holds a column whose
 *   payload is not prose: a FlexForm, inline children, a group or folder
 *   reference, a slug, a password. File, category and link relations do not
 *   exclude a type; the draft leaves them empty (`textmedia` gets its media
 *   from {@see AttachFileToContentElementTool}).
 * - **The field set is the type's own scalar columns.** Header, body text,
 *   column, language and position as arguments; every other scalar column of
 *   the chosen type's form through `fields`, validated against its TCA type.
 *   Still not a generic record API: the table is fixed, the identity, position,
 *   visibility, publication, audience and translation columns are refused by
 *   name, and a relation is never an argument.
 *
 * The element is created in the language it is asked for, WITHOUT a translation
 * parent. In a connected-mode installation that is a free-mode element, which
 * is a legitimate but different thing from a translation of an existing one —
 * {@see CreateTranslationDraftTool} is the tool for that, and the description
 * says so on the wire so a model can choose.
 *
 * `bodytext` reaches the DataHandler and its RTE transformation exactly as any
 * editor's input does, so it is bounded in length here and nowhere else
 * sanitised: an editor may write the same markup by hand, and a tool that
 * filtered it would be enforcing a rule the CMS itself does not have.
 */
final readonly class CreateContentElementDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the three ADR-146 writers share.
    use PlansOneEditorialWriteTrait;

    /**
     * One string for "no such page", "deleted" and "you may not edit content
     * there", so a refusal never confirms that a page uid exists. Shared with
     * {@see UpdatePageMetadataTool} and the page-reading tools.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const TABLE = 'tt_content';

    private const PAGES_TABLE = 'pages';

    /**
     * Content types no installation may offer through this tool, whatever
     * their form holds (ADR-196): the legacy plugin element, raw HTML, a
     * record shortcut, a divider — and, by prefix, every menu. Their payload
     * references records or pages, or runs code.
     */
    private const DENIED_TYPES = ['list', 'html', 'shortcut', 'div'];

    private const DENIED_TYPE_PREFIX = 'menu_';

    /**
     * The `CType` item group a plugin registers in. Since TYPO3 v13 a plugin
     * is a content type of its own, and one registered without a FlexForm
     * carries the scalar form `addPlugin()` copies from `header` — so the
     * group is what marks it, not its columns.
     */
    private const DENIED_ITEM_GROUP = 'plugins';

    /**
     * TCA column types a model may fill through `fields`. A `select` counts
     * only with static items and no `foreign_table` — see {@see self::columnKind()}.
     */
    private const FILLABLE_COLUMN_TYPES = ['input', 'text', 'select', 'check', 'number', 'datetime', 'radio', 'color', 'email'];

    /**
     * Column types that leave a type offered and are never filled: the
     * relations core attaches to prose — `categories` on every element,
     * `header_link` in the header palette, `assets` on `textmedia` — and the
     * language selector. A draft leaves them empty; a later tool or a human
     * fills them. Every other non-fillable type excludes the type it sits in.
     */
    private const UNFILLED_COLUMN_TYPES = ['file', 'category', 'link', 'language'];

    /**
     * Columns of every element this tool sets itself or must never set:
     * identity, position, visibility, publication, audience and translation
     * topology (ADR-135's exclusions for pages, by analogy). Never a `fields`
     * key and never a reason to exclude a type. `t3ver_*` is matched by prefix.
     */
    private const SYSTEM_COLUMNS = [
        'uid', 'pid', 'CType', 'colPos', 'sorting', 'tstamp', 'crdate', 'deleted',
        'sys_language_uid', 'l18n_parent', 'l10n_source', 'l18n_diffsource',
        'hidden', 'starttime', 'endtime', 'fe_group', 'editlock',
    ];

    private const SYSTEM_COLUMN_PREFIX = 't3ver_';

    /** Arguments of their own; refused as `fields` keys so a value is never asked for twice. */
    private const OWN_ARGUMENTS = ['header', 'bodytext'];

    /** Upper bound for the header. The core column is `varchar(255)`. */
    private const MAX_HEADER_LENGTH = 255;

    /** Upper bound for an `input` column whose TCA declares no `max`; the usual column is `varchar(255)`. */
    private const MAX_INPUT_LENGTH = 255;

    /**
     * Upper bound for the body. The column is `text` and the TCA declares no
     * `max`, so nothing else bounds a model-chosen argument — and a drafted
     * element is a paragraph or two, not a document.
     */
    private const MAX_BODY_LENGTH = 20000;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        // Read at call time: the offered types are the installation's, and a
        // process without a TCA offers none rather than a list it cannot vouch
        // for — execute() refuses such a process outright.
        $available = $this->availableTypes();
        $listed    = $available === [] ? 'none in this process' : implode(', ', $available);

        return ToolSpec::function(
            'create_content_element_draft',
            'Create ONE new content element (tt_content) on a page. The element is always created HIDDEN, so a '
            . 'human must review and unhide it in the page module before it is visible. Writes through the TYPO3 '
            . 'DataHandler as the acting backend user, in the live workspace. Only content types whose form holds '
            . 'scalar columns are available (' . $listed . '); "fields" takes further scalar columns of the '
            . 'chosen type. To translate an EXISTING element, use '
            . 'create_translation_draft instead — this tool creates a standalone element in the language given.',
            [
                'type'       => 'object',
                'properties' => [
                    'page' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the page to create the element on.',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'The content type (CType). One of: ' . $listed . '.',
                    ],
                    'header' => [
                        'type'        => 'string',
                        'description' => 'The element headline. Required — it is how a human recognises the draft.',
                    ],
                    'bodytext' => [
                        'type'        => 'string',
                        'description' => 'The body text. Omit for a "header" element.',
                    ],
                    'column' => [
                        'type'        => 'integer',
                        'description' => 'The backend layout column (colPos) to create in. Defaults to 0.',
                    ],
                    'language' => [
                        'type'        => 'integer',
                        'description' => 'The sys_language_uid to create the element in. Defaults to 0 (default language).',
                    ],
                    'after_content_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the new element directly after this content element. It must be on '
                            . 'the same page. Omit to place it first in the column.',
                    ],
                    'fields' => [
                        'type'                 => 'object',
                        'description'          => 'Further columns of the chosen type, as {column: value}. A key must be a '
                            . 'scalar column of that type\'s form in the TCA (input, text, select with static items, '
                            . 'check, number, datetime, radio, color, email); relations, FlexForms and the identity, '
                            . 'position, visibility, publication, audience and translation columns are refused. A '
                            . 'value is validated against the column\'s TCA type; a refusal names the columns the type '
                            . 'offers. Omit for header and body text only.',
                        'additionalProperties' => ['type' => ['string', 'boolean', 'number']],
                    ],
                ],
                'required' => ['page', 'type', 'header'],
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
            'pid'              => $plan['destination'],
            'CType'            => $plan['type'],
            'header'           => $plan['header'],
            'colPos'           => $plan['column'],
            'sys_language_uid' => $plan['language'],
            // Never negotiable; see the class docblock.
            $this->hiddenField() => 1,
        ];
        if ($plan['bodytext'] !== null) {
            $record['bodytext'] = $plan['bodytext'];
        }

        // Validated against the type's TCA in plan(); the system columns above
        // are refused there by name, so nothing here is overwritten.
        foreach ($plan['fields'] as $column => $value) {
            $record[$column] = $value;
        }

        $newUid = $this->createRecord(
            self::TABLE,
            $record,
            $user,
            'The element was not created. The acting backend user is most likely missing the grant to '
            . 'create records in ' . self::TABLE . ' on that page.',
        );
        if ($newUid instanceof ToolResult) {
            return $newUid;
        }

        // Read back before reporting success. A uid is proof that a row exists,
        // not that it carries what was asked for: the DataHandler SKIPS a field
        // the acting user lacks the `non_exclude_fields` grant for, silently and
        // without logging. For `hidden` that is not a reporting problem but a
        // safety one — the element would be live on the page, which is the one
        // outcome this tool exists to prevent.
        $further = array_keys($plan['fields']);
        if ($plan['bodytext'] !== null) {
            $further[] = 'bodytext';
        }

        $stored = $this->fetchElement($newUid, ...$further);
        if ($stored === null) {
            $removed = $this->discard($newUid, $user);

            return ToolResult::error(sprintf(
                'Content element [%d] was created but could not be read back, so it %s.',
                $newUid,
                $removed ? 'was deleted again' : 'COULD NOT BE DELETED and may be visible — remove it by hand',
            ));
        }

        // Every column the call set is compared: the tool's own five by
        // value, and the two text arguments by the rule the `fields` follow,
        // under the config the type gives them — an RTE body is read for
        // presence. The grants were asked before the write, so a column that
        // still did not take names a second silence, and the element goes
        // with it: an approver agreed to the whole draft.
        $texts = ['header' => $plan['header']];
        if ($plan['bodytext'] !== null) {
            $texts['bodytext'] = $plan['bodytext'];
        }

        $notTaken = [
            ...$this->ownColumnsThatDidNotTake($stored, $plan),
            ...$this->fieldsThatDidNotTake($stored, [...$texts, ...$plan['fields']], $plan['type']),
        ];
        if ($notTaken !== []) {
            // Take it back. A half-made element nobody approved is worse than
            // no element, and leaving it for a human to find is not a remedy
            // when the failure mode is "it is already visible".
            $removed = $this->discard($newUid, $user);

            return ToolResult::error(sprintf(
                'Content element [%d] was created but %s did not carry the value asked for, so it %s. The '
                . 'DataHandler dropped the value without complaint; the acting backend user is most likely '
                . 'missing the field-level ("exclude field") grant for %s.',
                $newUid,
                implode(', ', $notTaken),
                $removed
                    ? 'was deleted again'
                    : (in_array($this->hiddenField(), $notTaken, true)
                        ? 'COULD NOT BE DELETED and may be visible — remove it by hand'
                        : 'COULD NOT BE DELETED — remove it by hand'),
                implode(', ', array_map(static fn(string $column): string => self::TABLE . ':' . $column, $notTaken)),
            ));
        }

        return ToolResult::text(sprintf(
            'Created hidden %s element [%d] "%s" on page [%d], column %d, language %d%s. It is not visible until a '
            . 'human unhides it.',
            $plan['type'],
            $newUid,
            $this->excerpt($plan['header']),
            $plan['page'],
            $plan['column'],
            $plan['language'],
            $plan['fields'] === [] ? '' : ', with ' . implode(', ', array_keys($plan['fields'])),
        ))->withWriteTarget(new RecordReference(self::TABLE, $newUid), WriteKind::CREATED);
    }

    /**
     * What this call would create, as the approver reads it (ADR-136).
     *
     * There is no "before" — the record does not exist yet — so the card shows
     * the whole of what would come into being, which is exactly the set of
     * arguments the model chose. That is the point: an approver judging a
     * creation has nothing to compare against and must be able to read the
     * result in full.
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

        $lines = [
            sprintf('New %s element on page [%d] "%s":', $plan['type'], $plan['page'], $this->excerpt($plan['pageTitle'])),
            sprintf('header: %s', $this->quoted($plan['header'])),
            $plan['bodytext'] === null
                ? 'bodytext: (none)'
                : sprintf('bodytext: %s', $this->quoted($plan['bodytext'])),
        ];
        // One line per further column, so the card shows the whole of what
        // would come into being — the point made above.
        foreach ($plan['fields'] as $column => $value) {
            $lines[] = sprintf('%s: %s', $column, $this->quoted(self::toStr($value)));
        }

        $lines[] = sprintf(
            'position: column %d, language %d, %s',
            $plan['column'],
            $plan['language'],
            $plan['afterUid'] > 0
                ? sprintf('directly after element [%d] "%s"', $plan['afterUid'], $this->excerpt($plan['afterHeader']))
                : 'first in the column',
        );
        $lines[] = 'visibility: hidden — a human must unhide it before anyone sees it';

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page is authorised against the acting
        // user's own content-edit permission, and the DataHandler enforces the
        // same permission a second time inside the creation.
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
        // elements, not one. A reaped run that may already have created the
        // element must fail terminally rather than draft it again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * The subject is `pages`, not `tt_content`: `recordTypes` names the table
     * whose uid the arguments IDENTIFY, and the only record identifier this
     * tool requires is `page`. Declaring `tt_content` would offer the action on
     * an element while the run has no way to learn that element's pid — the
     * catalogue never reads a record and the run is restricted to this one tool
     * — so the model could only refuse or guess. The row it writes is a
     * `tt_content` row; the record an editor selects is the page.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_content_element_draft.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_content_element_draft.description',
            'nrllm-editor-action-create-content',
            [self::PAGES_TABLE],
        );
    }

    /**
     * Everything the creation needs, resolved and authorised — or the refusal
     * message that stops it.
     *
     * One method for both {@see self::execute()} and {@see self::previewCall()}:
     * the approver must read the element the write will actually produce.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{page:int, pageTitle:string, type:string, header:string, bodytext:string|null, fields:array<non-empty-string, string|int>, column:int, language:int, afterUid:int, afterHeader:string, destination:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['page', 'type', 'header', 'bodytext', 'column', 'language', 'after_content_uid', 'fields'],
            'creates one content element',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $pageUid = self::toInt($arguments['page'] ?? 0);
        if ($pageUid < 1) {
            return 'Refused: "page" must be the positive uid of exactly one page.';
        }

        $available = $this->availableTypes();
        $type      = trim(self::toStr($arguments['type'] ?? ''));
        if (!in_array($type, $available, true)) {
            return sprintf(
                'Refused: "%s" is not a content type this tool creates. Allowed here: %s.',
                preg_replace('/[^A-Za-z0-9_-]/', '', $type) ?? '',
                $available === [] ? 'none' : implode(', ', $available),
            );
        }

        // Core declares `CType` with `authMode`, and the DataHandler then drops
        // a value outside the acting user's `explicit_allowdeny` without an
        // error and creates the element as the DEFAULT type — which the
        // read-back would catch and delete again, blaming a grant. Asked here
        // under the same condition the DataHandler uses, so an installation
        // without `authMode` is not refused what the DataHandler would take.
        if ($this->cTypeHasAuthMode() && !$user->checkAuthMode(self::TABLE, 'CType', $type)) {
            return sprintf(
                'Refused: the acting backend user is not allowed content type "%s" (no explicit allow for %s:CType:%s). '
                . 'Nothing was written.',
                $type,
                self::TABLE,
                $type,
            );
        }

        $fields = $this->collectFields($arguments, $type);
        if (is_string($fields)) {
            return $fields;
        }

        // Asked BEFORE the write, as UpdateFalAssetMetaTool asks it: the
        // DataHandler drops an `exclude` column the user holds no grant for in
        // silence and creates the element without it, which is half of an
        // approved draft. Refusing first leaves nothing behind.
        $ungranted = $this->fieldsTheUserMayNotWrite($user, array_keys($fields));
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was '
                . 'written.',
                implode(', ', array_map(static fn(string $column): string => self::TABLE . ':' . $column, $ungranted)),
            );
        }

        $header = $this->text($arguments, 'header', self::MAX_HEADER_LENGTH);
        if (is_string($header)) {
            return $header;
        }

        if ($header[0] === '') {
            return 'Refused: "header" is required and must not be empty — it is how a human recognises the draft.';
        }

        $bodytext = null;
        if (array_key_exists('bodytext', $arguments)) {
            $body = $this->text($arguments, 'bodytext', self::MAX_BODY_LENGTH);
            if (is_string($body)) {
                return $body;
            }

            $bodytext = $body[0];
        }

        $language = self::toInt($arguments['language'] ?? 0);
        if ($language < 0) {
            return 'Refused: "language" must be zero or a positive sys_language_uid.';
        }

        if (!$user->checkLanguageAccess($language)) {
            return sprintf('Refused: you may not edit content in language %d.', $language);
        }

        $column = self::toInt($arguments['column'] ?? 0);
        if ($column < 0) {
            return 'Refused: "column" must be zero or a positive backend-layout column (colPos).';
        }

        // The columns the tool writes itself go through the same question,
        // where the DataHandler's silence would change the row: core marks
        // `sys_language_uid` and the hidden column `exclude`, so without the
        // grant the element lands in the default language while the answer
        // names another — or lands VISIBLE. Position and language are asked
        // only when they differ from the default `0` the silence would leave.
        // The read-back compares the hidden state again whatever the grants say.
        $own = ['header', $this->hiddenField()];
        if ($bodytext !== null) {
            $own[] = 'bodytext';
        }

        if ($column !== 0) {
            $own[] = 'colPos';
        }

        if ($language !== 0) {
            $own[] = 'sys_language_uid';
        }

        $ungranted = $this->fieldsTheUserMayNotWrite($user, $own);
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was '
                . 'written.',
                implode(', ', array_map(static fn(string $column): string => self::TABLE . ':' . $column, $ungranted)),
            );
        }

        $page = $this->fetchPage($pageUid);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
        }

        $afterUid    = self::toInt($arguments['after_content_uid'] ?? 0);
        $afterHeader = '';
        if ($afterUid > 0) {
            $anchor = $this->fetchElement($afterUid);
            if ($anchor === null || self::toInt($anchor['pid'] ?? 0) !== $pageUid) {
                return sprintf(
                    'Refused: content element [%d] is not on page [%d], so it cannot anchor the new element.',
                    $afterUid,
                    $pageUid,
                );
            }

            $afterHeader = self::toStr($anchor['header'] ?? '');
        }

        return [
            'page'        => $pageUid,
            'pageTitle'   => self::toStr($page['title'] ?? ''),
            'type'        => $type,
            'header'      => $header[0],
            'bodytext'    => $bodytext,
            'fields'      => $fields,
            'column'      => $column,
            'language'    => $language,
            'afterUid'    => $afterUid,
            'afterHeader' => $afterHeader,
            // The DataHandler's own convention: a positive pid is the page, a
            // negative one is "directly after the record with that uid".
            'destination' => $afterUid > 0 ? -$afterUid : $pageUid,
        ];
    }

    /**
     * Delete an element this tool created but could not vouch for, reporting
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

        return $this->fetchElement($uid) === null;
    }

    /**
     * A validated text argument WRAPPED in a one-element list, or a refusal
     * message.
     *
     * Wrapped for the same reason {@see SetFileAlternativeTextTool::collectValue()}
     * wraps: both a valid value and a refusal are strings, and a headline that
     * happened to read like a refusal must not be treated as one.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list{string}|string
     */
    private function text(array $arguments, string $field, int $max): array|string
    {
        $raw = $arguments[$field] ?? null;
        if ($raw === null) {
            return sprintf('Refused: "%s" is required.', $field);
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            return sprintf('Refused: the value for "%s" must be a string.', $field);
        }

        $text = trim(self::toStr($raw));
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $field, $max);
        }

        return [$text];
    }

    /**
     * Whether `tt_content.CType` declares `authMode` — the condition under
     * which the DataHandler asks {@see BackendUserAuthentication::checkAuthMode()}.
     */
    private function cTypeHasAuthMode(): bool
    {
        $column = $this->tcaColumnsFor(self::TABLE)['CType'] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;

        return is_array($config) && self::toStr($config['authMode'] ?? '') !== '';
    }

    /**
     * The content types the live TCA declares that pass the exclusion rule
     * (ADR-196): not on the deny-list, not in the plugin item group, a form of
     * their own, and no column in it whose payload is something other than
     * prose.
     *
     * Empty when no TCA is loaded — there is no list to fall back to, and the
     * refusal for a missing backend environment is the one that fires then.
     *
     * @return list<string>
     */
    private function availableTypes(): array
    {
        $column = $this->tcaColumnsFor(self::TABLE)['CType'] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;
        $items  = is_array($config) ? ($config['items'] ?? null) : null;
        if (!is_array($items)) {
            return [];
        }

        $available = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = $item['value'] ?? null;
            if (is_string($type) && $type !== '--div--' && $this->isOffered($type, self::toStr($item['group'] ?? ''))) {
                $available[] = $type;
            }
        }

        return $available;
    }

    /**
     * Whether one declared type passes the exclusion rule.
     *
     * The deny-list is asked first and by name, so `html` stays out of reach
     * on an installation where its form happens to be scalar; the item group
     * is asked next, so a plugin whose form is scalar stays out too. Then
     * every column of the type's form that is not a system column decides: one
     * excluding column excludes the type. A type without a form is excluded
     * too — nothing says what it holds.
     *
     * @param string $itemGroup the `group` of the type's `CType` item, '' when it has none
     */
    private function isOffered(string $type, string $itemGroup): bool
    {
        if (in_array($type, self::DENIED_TYPES, true) || str_starts_with($type, self::DENIED_TYPE_PREFIX)) {
            return false;
        }

        if ($itemGroup === self::DENIED_ITEM_GROUP) {
            return false;
        }

        $columns = $this->columnsOfType($type);
        if ($columns === []) {
            return false;
        }

        foreach ($columns as $name => $config) {
            if (!$this->isSystemColumn($name) && $this->columnKind($config) === 'excluding') {
                return false;
            }
        }

        return true;
    }

    /**
     * The columns a model may fill for the type — its form's fillable columns
     * minus the system columns and the two that are arguments of their own.
     *
     * @return list<non-empty-string>
     */
    private function fillableColumnsOf(string $type): array
    {
        $fillable = [];
        foreach ($this->columnsOfType($type) as $name => $config) {
            if ($this->isSystemColumn($name) || in_array($name, self::OWN_ARGUMENTS, true)) {
                continue;
            }

            if ($this->columnKind($config) === 'fillable') {
                $fillable[] = $name;
            }
        }

        return $fillable;
    }

    /**
     * The columns of one type's form — its `showitem` with every `--palette--`
     * expanded, dividers and line breaks skipped, labels stripped — keyed by
     * name, each with the config the DataHandler will apply to it: the
     * column's own, overlaid with the type's `columnsOverrides`.
     *
     * Read at call time, after core's TcaPreparation has added the general,
     * language, hidden and access palettes to every `tt_content` type, so the
     * system columns are in here and are skipped by name where it matters.
     * A column the showitem names but the TCA does not define is left out.
     *
     * @return array<non-empty-string, array<array-key, mixed>>
     */
    private function columnsOfType(string $type): array
    {
        $tca   = $GLOBALS['TCA'] ?? null;
        $table = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? $tca[self::TABLE] : null;
        if ($table === null) {
            return [];
        }

        $columns   = is_array($table['columns'] ?? null) ? $table['columns'] : [];
        $palettes  = is_array($table['palettes'] ?? null) ? $table['palettes'] : [];
        $types     = is_array($table['types'] ?? null) ? $table['types'] : [];
        $typeConf  = is_array($types[$type] ?? null) ? $types[$type] : [];
        $overrides = is_array($typeConf['columnsOverrides'] ?? null) ? $typeConf['columnsOverrides'] : [];

        $names = [];
        foreach (explode(',', self::toStr($typeConf['showitem'] ?? '')) as $part) {
            $pieces = explode(';', trim($part));
            $name   = trim($pieces[0]);
            if ($name === '' || $name === '--div--' || $name === '--linebreak--') {
                continue;
            }

            if ($name !== '--palette--') {
                $names[] = $name;

                continue;
            }

            $paletteKey = trim($pieces[2] ?? '');
            $palette    = is_array($palettes[$paletteKey] ?? null) ? $palettes[$paletteKey] : [];
            foreach (explode(',', self::toStr($palette['showitem'] ?? '')) as $paletteItem) {
                $paletteName = trim(explode(';', trim($paletteItem))[0]);
                if ($paletteName !== '' && $paletteName !== '--linebreak--') {
                    $names[] = $paletteName;
                }
            }
        }

        $result = [];
        foreach ($names as $name) {
            $column = $columns[$name] ?? null;
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config)) {
                continue;
            }

            $override       = is_array($overrides[$name] ?? null) ? ($overrides[$name]['config'] ?? null) : null;
            $result[$name]  = is_array($override) ? array_replace_recursive($config, $override) : $config;
        }

        return $result;
    }

    /**
     * What one column means for the type it sits in.
     *
     * `fillable`: a scalar a model may set through `fields`. `unfilled`: a
     * relation the draft leaves empty without excluding the type. `excluding`:
     * everything else — a FlexForm, inline children, a group, folder or
     * record-backed select, a slug, a password, and every type this tool does
     * not know, so a new TCA type fails closed rather than open.
     *
     * @param array<array-key, mixed> $config
     *
     * @return 'fillable'|'unfilled'|'excluding'
     */
    private function columnKind(array $config): string
    {
        $type = self::toStr($config['type'] ?? '');
        if ($type === 'select') {
            if (self::toStr($config['foreign_table'] ?? '') !== '') {
                return 'excluding';
            }

            return $this->staticItemValues($config) === [] ? 'unfilled' : 'fillable';
        }

        if (in_array($type, self::FILLABLE_COLUMN_TYPES, true)) {
            return 'fillable';
        }

        return in_array($type, self::UNFILLED_COLUMN_TYPES, true) ? 'unfilled' : 'excluding';
    }

    /**
     * The disabled column is asked under the name the installation gives it
     * (see {@see self::hiddenField()}), not only under the standard `hidden`.
     */
    private function isSystemColumn(string $name): bool
    {
        return in_array($name, self::SYSTEM_COLUMNS, true)
            || str_starts_with($name, self::SYSTEM_COLUMN_PREFIX)
            || $name === $this->hiddenField();
    }

    /**
     * The values a `select`, `radio` or `check` column declares as static
     * items. A divider is an entry of `items` and not a value. Items with
     * non-scalar values are left out.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private function staticItemValues(array $config): array
    {
        $values = [];
        foreach (is_array($config['items'] ?? null) ? $config['items'] : [] as $item) {
            if (!is_array($item) || !array_key_exists('value', $item) || $item['value'] === '--div--') {
                continue;
            }

            if (is_string($item['value']) || is_int($item['value'])) {
                $values[] = (string)$item['value'];
            }
        }

        return $values;
    }

    /**
     * The validated `fields` map — column to the value the DataHandler will be
     * given — or a refusal message. Empty when the argument is absent.
     *
     * The WHOLE call is refused on the first key or value that is wrong, in the
     * order the model sent them. A key is refused first by name (a system
     * column, or one of the tool's own arguments), then against the type's
     * form, and the refusal names the columns the type does offer so the model
     * can correct itself.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<non-empty-string, string|int>|string
     */
    private function collectFields(array $arguments, string $type): array|string
    {
        if (!array_key_exists('fields', $arguments)) {
            return [];
        }

        $raw = $arguments['fields'];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return 'Refused: "fields" must be an object of column names to values.';
        }

        $columns  = $this->columnsOfType($type);
        $fillable = $this->fillableColumnsOf($type);

        $fields = [];
        foreach ($raw as $key => $value) {
            // The key is echoed back so the model can correct itself; it is a
            // name the model itself chose, not instance data.
            $column = preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '';

            if (in_array($column, self::OWN_ARGUMENTS, true)) {
                return sprintf('Refused: "%s" cannot be set through "fields"; pass it as the "%s" argument.', $column, $column);
            }

            if ($this->isSystemColumn($column)) {
                return sprintf(
                    'Refused: "%s" cannot be set through "fields". The tool sets identity, position and visibility '
                    . 'itself, and publication, audience and translation columns are not editorial.',
                    $column,
                );
            }

            if ($column !== $key || !in_array($column, $fillable, true)) {
                return sprintf(
                    'Refused: "%s" is not a scalar column of content type "%s". Columns this tool sets for it: %s.',
                    $column,
                    $type,
                    $fillable === [] ? 'none beyond header and bodytext' : implode(', ', $fillable),
                );
            }

            $checked = $this->fieldValue($column, $value, $columns[$column]);
            if (is_string($checked)) {
                return $checked;
            }

            $fields[$column] = $checked[0];
        }

        return $fields;
    }

    /**
     * One `fields` value, validated against the column's TCA type and WRAPPED
     * in a one-element list — or the refusal. Wrapped for the reason
     * {@see self::text()} wraps.
     *
     * Mirrors what the DataHandler checks, and refuses where it would silently
     * bend the value: a `select` value outside the static items would be
     * stored and shown as invalid; a `number` outside `range` would be clamped;
     * an invalid `email` would be emptied; an `input` below `min` would be
     * stored as ''; a nine-digit `color` would be cut to seven unless the
     * column declares `opacity`. A `datetime` is anything PHP reads — an
     * integer timestamp (seconds of the day on a `time` column) or an ISO 8601
     * date — and is handed over in the shape both cores store
     * ({@see self::datetimeForDataHandler()}).
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string|int}|string
     */
    private function fieldValue(string $column, mixed $value, array $config): array|string
    {
        $type = self::toStr($config['type'] ?? '');

        if ($type === 'check') {
            if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
                return [(int)(bool)$value];
            }

            return sprintf('Refused: the value for "%s" must be true, false, 0 or 1.', $column);
        }

        if ($type === 'number') {
            return $this->numberValue($column, $value, $config);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return sprintf('Refused: the value for "%s" must be a string.', $column);
        }

        $text = trim(self::toStr($value));
        if ($text === '' && (bool)($config['required'] ?? false)) {
            return sprintf('Refused: "%s" is required and must not be empty.', $column);
        }

        if ($type === 'select' || $type === 'radio') {
            $allowed = $this->staticItemValues($config);
            if (!in_array($text, $allowed, true)) {
                return sprintf(
                    'Refused: the value for "%s" must be one of: %s.',
                    $column,
                    implode(', ', array_map(static fn(string $item): string => '"' . $item . '"', $allowed)),
                );
            }

            return [$text];
        }

        if ($type === 'datetime') {
            if ($text === '') {
                return [''];
            }

            // An integer on a `time` column is seconds of the day to both
            // cores, not a Unix timestamp, and is handed over as it is.
            if ($this->isTimeOfDay($config) && MathUtility::canBeInterpretedAsInteger($text)) {
                return [(int)$text];
            }

            try {
                $moment = is_numeric($text) ? (new DateTimeImmutable())->setTimestamp((int)$text) : new DateTimeImmutable($text);
            } catch (Exception) {
                return sprintf(
                    'Refused: the value for "%s" must be a date or time the CMS can read, such as 2026-09-21 or '
                    . '2026-09-21T14:30:00+02:00.',
                    $column,
                );
            }

            return [$this->datetimeForDataHandler($moment, $config)];
        }

        if ($type === 'email' && $text !== '' && !GeneralUtility::validEmail($text)) {
            return sprintf('Refused: the value for "%s" must be a valid e-mail address.', $column);
        }

        // The DataHandler cuts a colour to seven characters unless the column
        // declares `opacity`, so a nine-digit value would be stored shortened.
        if ($type === 'color' && $text !== '') {
            $opacity = (bool)($config['opacity'] ?? false);
            if (preg_match($opacity ? '/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/' : '/^#[0-9A-Fa-f]{6}$/', $text) !== 1) {
                return sprintf(
                    'Refused: the value for "%s" must be a colour such as #1a2b3c%s.',
                    $column,
                    $opacity ? ' or #1a2b3c80' : '',
                );
            }
        }

        // Below `min` the DataHandler stores '' instead of the value.
        $min = self::toInt($config['min'] ?? 0);
        if ($type === 'input' && $min > 0 && $text !== '' && mb_strlen($text) < $min) {
            return sprintf('Refused: the value for "%s" must be at least %d characters.', $column, $min);
        }

        $max = $config['max'] ?? null;
        $max = is_int($max) && $max > 0 ? $max : ($type === 'text' ? self::MAX_BODY_LENGTH : self::MAX_INPUT_LENGTH);
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $column, $max);
        }

        return [$text];
    }

    /**
     * A `datetime` value in the one shape both supported cores store without
     * reinterpreting it.
     *
     * A string is NOT that shape: 13.4's DataHandler reads a string as UTC
     * wall time and subtracts the server's offset from it, so a day given as
     * `2026-09-21` lands on the evening before on any server outside UTC;
     * 14.3 reads the offset. An integer is taken verbatim by both — a Unix
     * timestamp for a date or a moment, seconds of the day for a `time` or
     * `timesec` column, which 14.3 reads as exactly that. A column stored in
     * a native `dbType` takes unqualified local wall time, which 13.4 parses
     * as UTC and writes back with `gmdate()`, and 14.3 parses and writes in
     * the server's zone — the same string either way.
     *
     * @param array<array-key, mixed> $config
     */
    private function datetimeForDataHandler(DateTimeImmutable $moment, array $config): string|int
    {
        $local = $moment->setTimezone(new DateTimeZone(date_default_timezone_get()));

        if ($this->isNativeDateTime($config)) {
            return $local->format('Y-m-d H:i:s');
        }

        if ($this->isTimeOfDay($config)) {
            return (int)$local->format('H') * 3600 + (int)$local->format('i') * 60 + (int)$local->format('s');
        }

        return $moment->getTimestamp();
    }

    /**
     * Whether the column is stored in a native `dbType` — a `DATE`, `DATETIME`
     * or `TIME` column rather than an integer.
     *
     * @param array<array-key, mixed> $config
     */
    private function isNativeDateTime(array $config): bool
    {
        return in_array(self::toStr($config['dbType'] ?? ''), ['date', 'datetime', 'time'], true);
    }

    /**
     * Whether the column stores seconds of the day: a `time` or `timesec`
     * format in an integer column.
     *
     * @param array<array-key, mixed> $config
     */
    private function isTimeOfDay(array $config): bool
    {
        if ($this->isNativeDateTime($config)) {
            return false;
        }

        $format = self::toStr($config['format'] ?? 'datetime');

        return $format === 'time' || $format === 'timesec';
    }

    /**
     * A `number` value: a whole number unless the column declares
     * `format: decimal`, within the TCA `range` where one is declared. The
     * DataHandler would clamp an out-of-range value in silence, and the
     * read-back would then blame a grant.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string|int}|string
     */
    private function numberValue(string $column, mixed $value, array $config): array|string
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            return sprintf('Refused: the value for "%s" must be a number.', $column);
        }

        $number  = is_string($value) ? (float)trim($value) : (float)$value;
        $decimal = self::toStr($config['format'] ?? 'integer') === 'decimal';
        if (!$decimal && floor($number) !== $number) {
            return sprintf('Refused: the value for "%s" must be a whole number.', $column);
        }

        $range = is_array($config['range'] ?? null) ? $config['range'] : [];
        $lower = $range['lower'] ?? null;
        if (is_numeric($lower) && $number < (float)$lower) {
            return sprintf('Refused: the value for "%s" must be at least %s.', $column, self::toStr($lower));
        }

        $upper = $range['upper'] ?? null;
        if (is_numeric($upper) && $number > (float)$upper) {
            return sprintf('Refused: the value for "%s" must be at most %s.', $column, self::toStr($upper));
        }

        // Two decimals is what the DataHandler stores for a decimal column, so
        // the read-back compares like with like.
        return [$decimal ? number_format($number, 2, '.', '') : (int)$number];
    }

    /**
     * The columns among `$columns` this user may not write, because the TCA
     * marks them `exclude` and the user holds no `non_exclude_fields` grant —
     * the same question the DataHandler asks, through the same method, as
     * {@see UpdateFalAssetMetaTool} asks it. `exclude` is read as core's
     * schema reads it, as a boolean cast, so an extension's integer `1` counts.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function fieldsTheUserMayNotWrite(BackendUserAuthentication $user, array $columns): array
    {
        $tcaColumns = $this->tcaColumnsFor(self::TABLE) ?? [];

        $ungranted = [];
        foreach ($columns as $column) {
            $definition = $tcaColumns[$column] ?? null;
            $excluded   = is_array($definition) && (bool)($definition['exclude'] ?? false);
            if ($excluded && !$user->check('non_exclude_fields', self::TABLE . ':' . $column)) {
                $ungranted[] = $column;
            }
        }

        return $ungranted;
    }

    /**
     * The columns the tool wrote itself whose stored value is not the one
     * asked for: the page, the type, the hidden state, the column and the
     * language. The DataHandler drops any of them in silence where the acting
     * user lacks a grant or the column is hidden from non-admins, and the
     * element then carries the default instead.
     *
     * @param array<string, mixed>                                   $stored
     * @param array{page:int, type:string, column:int, language:int} $plan
     *
     * @return list<string>
     */
    private function ownColumnsThatDidNotTake(array $stored, array $plan): array
    {
        $asked = [
            'pid'                => $plan['page'],
            'CType'              => $plan['type'],
            $this->hiddenField() => 1,
            'colPos'             => $plan['column'],
            'sys_language_uid'   => $plan['language'],
        ];

        $notTaken = [];
        foreach ($asked as $column => $value) {
            if (self::toStr($stored[$column] ?? '') !== (string)$value) {
                $notTaken[] = $column;
            }
        }

        return $notTaken;
    }

    /**
     * The columns set through `fields` — and the two text arguments, handed
     * in with them — whose stored value is not the one asked for.
     *
     * Compared as strings, which is how a check, a number and an integer
     * select item come back from the database; a decimal is compared as a
     * number, because the database renders `12.00` as it likes. Three kinds
     * are checked for presence rather than equality, because the DataHandler
     * rewrites them on purpose: a `datetime` is normalised to its `format` and
     * clamped to its `range`; a `text` column with `enableRichtext` passes
     * through the RTE transformation; an `input` column with an `eval` beyond
     * `trim` (`upper`, `lower`, `nospace`, `alphanum`, `unique`, …) is
     * transformed by it. For those, a dropped column reads back as the
     * column's empty value, and that is what is tested.
     *
     * @param array<string, mixed>                $stored
     * @param array<non-empty-string, string|int> $fields
     *
     * @return list<string>
     */
    private function fieldsThatDidNotTake(array $stored, array $fields, string $type): array
    {
        $columns = $this->columnsOfType($type);

        $notTaken = [];
        foreach ($fields as $column => $value) {
            $config     = $columns[$column] ?? [];
            $tcaType    = self::toStr($config['type'] ?? '');
            $storedText = self::toStr($stored[$column] ?? '');

            if ($this->isRewrittenOnPurpose($tcaType, $config)) {
                // Midnight, as seconds of the day, and the epoch are both `0`
                // and read back exactly as an empty column does.
                $asked = !in_array((string)$value, ['', '0'], true);
                $held  = !in_array($storedText, ['', '0', '0000-00-00', '0000-00-00 00:00:00', '00:00:00'], true);
                if ($asked !== $held) {
                    $notTaken[] = $column;
                }

                continue;
            }

            if ($tcaType === 'number' && self::toStr($config['format'] ?? 'integer') === 'decimal') {
                if (!is_numeric($storedText) || number_format((float)$storedText, 2, '.', '') !== (string)$value) {
                    $notTaken[] = $column;
                }

                continue;
            }

            if ($storedText !== (string)$value) {
                $notTaken[] = $column;
            }
        }

        return $notTaken;
    }

    /**
     * Whether the DataHandler stores a column of this kind in a shape other
     * than the one handed over — see {@see self::fieldsThatDidNotTake()}.
     *
     * @param array<array-key, mixed> $config
     */
    private function isRewrittenOnPurpose(string $tcaType, array $config): bool
    {
        if ($tcaType === 'datetime') {
            return true;
        }

        if ($tcaType === 'text') {
            return (bool)($config['enableRichtext'] ?? false);
        }

        if ($tcaType === 'input') {
            $evals = array_filter(array_map('trim', explode(',', self::toStr($config['eval'] ?? ''))));

            return array_diff($evals, ['trim']) !== [];
        }

        return false;
    }

    /**
     * The name of the table's "hidden" column as the installation declares it.
     *
     * Read from the TCA rather than hardcoded because it is the field that
     * makes this a DRAFT tool: an installation that renamed it must not end up
     * with a visible element and a success message that claims otherwise.
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
     * A content element row, or null when no undeleted element carries that uid.
     *
     * @param non-empty-string ...$columns further columns to read, beyond the identity ones
     *
     * @return array<string, mixed>|null
     */
    private function fetchElement(int $uid, string ...$columns): ?array
    {
        return $this->fetchRowByUid(
            self::TABLE,
            $uid,
            'uid',
            'pid',
            'colPos',
            'header',
            'CType',
            'sys_language_uid',
            $this->hiddenField(),
            ...$columns,
        );
    }

    /**
     * A page row, or null when no undeleted page carries that uid.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid): ?array
    {
        return $this->fetchRowByUid(self::PAGES_TABLE, $uid);
    }
}
