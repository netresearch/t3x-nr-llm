<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
 *   `shortcut`, `div`, every `menu_*` — or it is a plugin, known by its Extbase
 *   registration or its `plugins` or `forms` item group, or its form holds a column whose
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
 * One page is closed to it (ADR-193): a page that already holds CONNECTED
 * translations in that language. A standalone element beside them is what the
 * page module reports as "Inconsistent content detected", so the call is
 * refused and the model is sent to the default language and the translation
 * tool — unless the page's TSconfig sets
 * `mod.web_layout.allowInconsistentLanguageHandling`, the switch that silences
 * the same warning in core.
 *
 * `bodytext` reaches the DataHandler and its RTE transformation exactly as any
 * editor's input does, so it is bounded in length here and nowhere else
 * sanitised: an editor may write the same markup by hand, and a tool that
 * filtered it would be enforcing a rule the CMS itself does not have.
 */
final readonly class CreateContentElementDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface, RecordCreatorInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the three ADR-146 writers share.
    use PlansOneEditorialWriteTrait;
    // The content-type exclusion rule, the form reading and the value checks
    // (ADR-196), shared with the updating writer (ADR-198).
    use ReadsContentTypeFormsTrait;

    /**
     * One string for "no such page", "deleted" and "you may not edit content
     * there", so a refusal never confirms that a page uid exists. Shared with
     * {@see UpdatePageMetadataTool} and the page-reading tools.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const TABLE = 'tt_content';

    private const PAGES_TABLE = 'pages';

    /** Arguments of their own; refused as `fields` keys so a value is never asked for twice. */
    private const OWN_ARGUMENTS = ['header', 'bodytext'];

    /** Upper bound for the header. The core column is `varchar(255)`. */
    private const MAX_HEADER_LENGTH = 255;

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
            . 'DataHandler as the acting backend user, in the live workspace. Only content types that pass the '
            . 'exclusion rule are available (' . $listed . "), and the page's TSconfig may narrow them and their "
            . 'options further; "fields" takes further scalar columns of the chosen type. To translate an EXISTING '
            . 'element, use '
            . 'create_translation_draft instead — this tool creates a standalone element in the language given, '
            . 'and refuses a non-default language on a page that already holds connected translations in it.',
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
                        'description' => 'The body text. Omit for a type whose form shows none, such as "header"; it is '
                            . 'refused there.',
                    ],
                    'column' => [
                        'type'        => 'integer',
                        'description' => 'The backend layout column (colPos) to create in. Defaults to 0.',
                    ],
                    'language' => [
                        'type'        => 'integer',
                        'description' => 'The sys_language_uid to create the element in. Defaults to 0 (default language). '
                            . 'Refused on a page that already holds connected translations in that language: create '
                            . 'the element in 0 there and translate it with create_translation_draft.',
                    ],
                    'after_content_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the new element directly after this content element. It must be on '
                            . 'the same page. Omit to place it first in the column.',
                    ],
                    'fields' => [
                        'type'                 => 'object',
                        'description'          => 'Further columns of the chosen type, as {column: value}. A key must be a '
                            . "scalar column of that type's form in the TCA (input, text, select with static items, "
                            . 'check, number, datetime, radio, color, email); relations, FlexForms and the identity, '
                            . 'position, visibility, publication, audience and translation columns are refused. A '
                            . "value is validated against the column's TCA type; a refusal names the columns the type "
                            . 'offers. Omit for header and body text only.',
                        // The boolean form ReadRecordsTool ships. A type ARRAY
                        // is a union type, which Gemini's schema dialect does
                        // not express and GeminiProvider hands over verbatim;
                        // the value is validated against the TCA anyway.
                        'additionalProperties' => true,
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
                . 'DataHandler dropped or changed the value without complaint: either it was rewritten by TYPO3 '
                . 'under a rule of the column this tool does not check, or the acting backend user is missing the '
                . 'field-level ("exclude field") grant for %s.',
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
        $columns = $this->columnsOfType($plan['type']);
        foreach ($plan['fields'] as $column => $value) {
            $lines[] = sprintf('%s: %s', $column, $this->quoted($this->shownValue($value, $columns[$column] ?? [])));
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
     * The table the element lands in — not the page `getEditorAction()` names as its subject (ADR-152). The generic `create_record_draft` steps back from it (ADR-197).
     */
    public function getCreatedTables(): array
    {
        return [self::TABLE];
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

        $fields = $this->collectFields($arguments, $type, $user);
        if (is_string($fields)) {
            return $fields;
        }

        // Asked BEFORE the write, as UpdateFalAssetMetaTool asks it: the
        // DataHandler drops an `exclude` column the user holds no grant for in
        // silence and creates the element without it, which is half of an
        // approved draft. Refusing first leaves nothing behind.
        $ungranted = $this->columnsTheUserMayNotSet($user, self::TABLE, array_keys($fields));
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
            // Refused where the type's form does not show it, as a `fields`
            // key is: the DataHandler would still write it, under the
            // column's base config, and the read-back compares it under the
            // type's — an RTE base would take a correct element back again.
            if (!array_key_exists('bodytext', $this->columnsOfType($type))) {
                return sprintf(
                    'Refused: content type "%s" shows no "bodytext" in its form; omit "bodytext" for it.',
                    $type,
                );
            }

            $body = $this->text($arguments, 'bodytext', self::MAX_BODY_LENGTH);
            if (is_string($body)) {
                return $body;
            }

            $bodytext = $body[0];
        }

        // The two text arguments are refused where the type's TCA makes them
        // read-only, as a `fields` key is in keyRefusal().
        $typeColumns = $this->columnsOfType($type);
        foreach ($bodytext === null ? ['header'] : ['header', 'bodytext'] as $own) {
            if ((bool)($typeColumns[$own]['readOnly'] ?? false)) {
                return sprintf(
                    'Refused: "%s" is read-only in the form of content type "%s" (TCA readOnly).%s Nothing was written.',
                    $own,
                    $type,
                    $own === 'header' ? $this->headerRequiredNote($type) : '',
                );
            }
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

        $ungranted = $this->columnsTheUserMayNotSet($user, self::TABLE, $own);
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

        // After the neutral refusal, because this one names the page.
        $narrowed = $this->pageTsConfigRefusal($pageUid, $type, $fields, $bodytext !== null, $column, $language);
        if ($narrowed !== null) {
            return $narrowed;
        }

        // After the neutral refusal, because this one names the page.
        if ($language > 0
            && $this->holdsConnectedTranslations($pageUid, $language)
            && !$this->allowsInconsistentLanguageHandling($pageUid)
        ) {
            return sprintf(
                'Refused: page [%d] already holds connected translations in language %d, and a standalone element '
                . 'beside them makes the page module report "Inconsistent content detected". Create the element in '
                . 'the default language (0) and translate it with create_translation_draft or the translation tools '
                . 'of the CMS; tell the editor that the translation is a separate step.',
                $pageUid,
                $language,
            );
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
     * The refusal the page's TSconfig gives this call, or null.
     *
     * `TCEFORM.tt_content` narrows the form per page, and only FormEngine
     * applies it — the DataHandler stores a type or an item the form would
     * not have offered. So the tool asks what FormEngine asks: the page
     * TSconfig as {@see \TYPO3\CMS\Backend\Form\FormDataProvider\PageTsConfig}
     * reads it, with `<column>.types.<CType>.` merged over `<column>.` as
     * {@see \TYPO3\CMS\Backend\Form\FormDataProvider\PageTsConfigMerged}
     * merges it (typo3/cms-backend 14.3.7):
     *
     * - `CType.keepItems` / `CType.removeItems` take the chosen type out of
     *   the selector;
     * - `<column>.disabled` hides a column, so it is not set through `fields`,
     *   the body is not set where `bodytext` is hidden, and the call is
     *   refused where `header` is hidden — the tool always writes it;
     * - `<column>.keepItems` / `<column>.removeItems` take an item out of a
     *   `select`, as `AbstractItemProvider` does — a `fields` value, and the
     *   `column` and `language` arguments against `colPos` and
     *   `sys_language_uid`; FormEngine does not apply them to `radio` or
     *   `check`, and neither does this.
     *
     * The type list in the spec is page-independent — the TCA-level set —
     * and this narrows it at call time.
     *
     * @param array<non-empty-string, string|int> $fields
     */
    private function pageTsConfigRefusal(int $pageUid, string $type, array $fields, bool $withBody, int $position, int $language): ?string
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageUid);
        $tceForm  = is_array($tsConfig['TCEFORM.'] ?? null) ? $tsConfig['TCEFORM.'] : [];
        $rules    = is_array($tceForm[self::TABLE . '.'] ?? null) ? $tceForm[self::TABLE . '.'] : [];
        if ($rules === []) {
            return null;
        }

        $rule = $this->tceFormRuleRemoving($rules, 'CType', $type, $type);
        if ($rule !== null) {
            return sprintf(
                'Refused: content type "%s" is not offered on page [%d] by its page TSconfig (%s). Nothing was written.',
                $type,
                $pageUid,
                $rule,
            );
        }

        // The header is written on every call, so it is asked on every call.
        $shown = ['header', ...array_keys($fields)];
        if ($withBody) {
            $shown[] = 'bodytext';
        }

        $columns = $this->columnsOfType($type);
        foreach ($shown as $column) {
            $rule = $this->tceFormDisabling($rules, $column, $type);
            if ($rule !== null) {
                return sprintf(
                    'Refused: "%s" is not shown on page [%d] by its page TSconfig (%s).%s Nothing was written.',
                    $column,
                    $pageUid,
                    $rule,
                    $column === 'header' ? $this->headerRequiredNote($type) : '',
                );
            }

            $rule = $this->tceFormReadOnly($rules, $column, $type, self::toStr($columns[$column]['type'] ?? ''));
            if ($rule !== null) {
                return sprintf(
                    'Refused: "%s" is read-only on page [%d] by its page TSconfig (%s).%s Nothing was written.',
                    $column,
                    $pageUid,
                    $rule,
                    $column === 'header' ? $this->headerRequiredNote($type) : '',
                );
            }
        }

        // The two positions the tool writes from its own arguments are static
        // selects to FormEngine — `colPos` filtered in TcaSelectItems, the
        // language in TcaLanguage — and both apply the item rules to them.
        foreach (['colPos' => ['column', $position], 'sys_language_uid' => ['language', $language]] as $name => [$label, $value]) {
            $rule = $this->tceFormRuleRemoving($rules, $name, $type, (string)$value);
            if ($rule !== null) {
                return sprintf(
                    'Refused: %s %d is not offered on page [%d] by its page TSconfig (%s). Nothing was written.',
                    $label,
                    $value,
                    $pageUid,
                    $rule,
                );
            }
        }

        foreach ($fields as $column => $value) {
            if (self::toStr($columns[$column]['type'] ?? '') !== 'select') {
                continue;
            }

            $rule = $this->tceFormRuleRemoving($rules, $column, $type, (string)$value);
            if ($rule !== null) {
                return sprintf(
                    'Refused: the value "%s" for "%s" is not offered on page [%d] by its page TSconfig (%s). Nothing '
                    . 'was written.',
                    (string)$value,
                    $column,
                    $pageUid,
                    $rule,
                );
            }
        }

        return null;
    }

    /**
     * What a refusal of the header adds: the header is a required argument, so
     * there is no call that avoids it.
     */
    private function headerRequiredNote(string $type): string
    {
        return sprintf(
            ' "header" is a required argument, so this tool cannot create a "%s" element here.',
            $type,
        );
    }

    /**
     * Whether the page holds at least one connected translation — a content
     * element with a translation parent — in the language (ADR-193).
     *
     * The half of core's mixed-mode condition this tool cannot produce itself:
     * {@see \TYPO3\CMS\Backend\View\BackendLayout\ContentFetcher::getTranslationData()}
     * reports a language as inconsistent when its rows on the page hold both a
     * translation parent and none, and this tool only ever adds the second
     * kind. Counted as core counts them: deleted rows are out, hidden rows are
     * in, and the two column names are the ones core's own loop reads.
     *
     * No workspace restriction, unlike core: the write is refused outside the
     * live workspace anyway, and a connected translation that exists only as
     * another workspace's draft mixes the page the moment it is published.
     */
    private function holdsConnectedTranslations(int $pageUid, int $language): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($language, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->gt('l18n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return self::toInt($count) > 0;
    }

    /**
     * Whether the page's TSconfig opts in to mixed translation modes.
     *
     * Read the way {@see \TYPO3\CMS\Backend\View\Drawing\DrawingConfiguration::create()}
     * reads it for the page module — rootline-merged, cast to bool — so the
     * tool honours the same opt-out as core. The connected-row check above is
     * deliberately wider than core's: it counts every workspace and dispatches
     * no content-query listener (ADR-193).
     */
    private function allowsInconsistentLanguageHandling(int $pageUid): bool
    {
        $tsConfig  = BackendUtility::getPagesTSconfig($pageUid);
        $mod       = is_array($tsConfig['mod.'] ?? null) ? $tsConfig['mod.'] : [];
        $webLayout = is_array($mod['web_layout.'] ?? null) ? $mod['web_layout.'] : [];

        return (bool)($webLayout['allowInconsistentLanguageHandling'] ?? false);
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
    private function collectFields(array $arguments, string $type, BackendUserAuthentication $user): array|string
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

            $refusal = array_key_exists($column, $columns) ? $this->keyRefusal($columns[$column]) : null;
            if ($column === $key && $refusal !== null) {
                return sprintf('Refused: "%s" cannot be set through "fields": %s.', $column, $refusal);
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

            // The DataHandler asks `authMode` for every `select` declaring
            // it, not for `CType` alone, and drops a value the user is not
            // allowed in silence.
            if (self::toStr($columns[$column]['type'] ?? '') === 'select'
                && (bool)($columns[$column]['authMode'] ?? false)
                && !$user->checkAuthMode(self::TABLE, $column, (string)$checked[0])
            ) {
                return sprintf(
                    'Refused: the acting backend user is not allowed the value "%s" for "%s" (no explicit allow for '
                    . '%s:%s:%s). Nothing was written.',
                    (string)$checked[0],
                    $column,
                    self::TABLE,
                    $column,
                    (string)$checked[0],
                );
            }

            $fields[$column] = $checked[0];
        }

        return $fields;
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
