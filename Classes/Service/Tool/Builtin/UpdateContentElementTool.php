<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Set fields of ONE existing content element, through the DataHandler, as the
 * acting backend user (ADR-198).
 *
 * The field set is the one {@see CreateContentElementDraftTool} offers for a
 * new element of the same type (ADR-196), read from the live TCA of the
 * element's own `CType` and checked by the same rules
 * ({@see ReadsContentTypeFormsTrait}): the scalar columns of the type's form —
 * header, subheader, body text, the header layout, a date and the like —
 * each validated against its TCA type and its `columnsOverrides` before
 * anything is written. Relations, FlexForms and the identity, position,
 * visibility, publication, audience and translation columns are never
 * arguments; an element whose type the exclusion rule leaves out (raw HTML,
 * a plugin, a menu, a shortcut, a form holding a FlexForm or inline children)
 * is refused whole. Publishing is {@see PublishRecordTool}'s act and moving
 * is {@see MoveContentElementTool}'s; this tool changes what an element says.
 *
 * What it refuses, and why:
 *
 * - **A column the element's form does not offer, and a value its TCA would
 *   bend.** The refusal names the columns the type does offer.
 * - **A column a translation takes from its default-language element**
 *   (`l10n_mode = exclude`): the DataHandler would ignore it on the
 *   translation, and the refusal names the element to change instead.
 * - **A column the page's TSconfig takes out of the form** — hidden, read-only,
 *   or a select value it does not offer — read as FormEngine reads it.
 * - **An element the acting user may not edit**: `CONTENT_EDIT` on its page,
 *   the record-level rights ({@see ActsOnAnExistingRecordTrait::mayEditRecord()})
 *   and the field-level grant for every column set. A missing element and a
 *   forbidden one return the same neutral string.
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 *
 * The approval card shows every column before and after. The write is read
 * back column by column, and what did not take is named; values that took
 * stay written, as {@see UpdatePageMetadataTool} leaves them.
 */
final readonly class UpdateContentElementTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Language and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;
    // The content-type exclusion rule, the form reading and the value checks (ADR-196).
    use ReadsContentTypeFormsTrait;

    /**
     * One string for "no such element", "deleted", "on a page you may not
     * edit" and "in a language you may not touch", shared with
     * {@see MoveContentElementTool}.
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
            'update_content_element',
            'Change fields of ONE existing content element (tt_content): the scalar columns its content type shows '
            . 'in the backend form, such as header, subheader and bodytext. Each value is checked against the TCA of '
            . "the element's type; relations, FlexForms and the identity, position, visibility, publication, "
            . 'audience and translation columns cannot be set, and an element of a type outside the offered set '
            . '(raw HTML, plugins, menus, shortcuts) is refused. Writes through the TYPO3 DataHandler as the acting '
            . 'backend user, in the live workspace only; the whole call is refused rather than partially applied.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The tt_content uid of the single element to change.',
                    ],
                    'fields' => [
                        'type'                 => 'object',
                        'description'          => 'The columns to set, as {column: value}. A refusal names the columns '
                            . "the element's type offers.",
                        // The boolean form the creating writer ships: a type
                        // ARRAY is a union type Gemini's dialect cannot express.
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['uid', 'fields'],
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

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$plan['uid'] => $plan['fields']]], [], $user);
        $dataHandler->process_datamap();

        // Read back whatever the error log says: an empty one is no proof —
        // the DataHandler drops a column it will not take from this user, and
        // rewrites a value under a rule of the column, without a word — and a
        // non-empty one does not mean nothing was written.
        $stored   = $this->fetchRowByUid(self::TABLE, $plan['uid']);
        $notTaken = $stored === null
            ? array_keys($plan['fields'])
            : [...$this->fieldsThatDidNotTake($stored, $plan['fields'], $plan['type']), ...$this->unchangedThoughAsked($stored, $plan)];
        $notTaken   = array_values(array_unique($notTaken));
        $complaints = $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog);

        // What this call changed on the row: the columns that took and did
        // not already hold the value.
        $changed = [];
        foreach ($plan['fields'] as $column => $value) {
            if (!in_array($column, $notTaken, true) && (string)$value !== self::toStr($plan['before'][$column] ?? '')) {
                $changed[] = $column;
            }
        }

        if ($notTaken === [] && $complaints === '') {
            return ToolResult::text(sprintf(
                'Updated content element [%d] "%s": %s.',
                $plan['uid'],
                $this->excerpt($plan['header']),
                implode(', ', array_keys($plan['fields'])),
            ))->withWriteTarget(new RecordReference(self::TABLE, $plan['uid']), WriteKind::UPDATED);
        }

        $notTakenText = $notTaken === []
            ? ''
            : sprintf(
                ' Did not take: %s — the DataHandler dropped or changed the value, under a rule of the column this tool '
                . 'does not check or a hook of the installation.',
                implode(', ', $notTaken),
            );

        if ($changed === []) {
            return ToolResult::error(sprintf(
                'The update did not take on content element [%d].%s%s',
                $plan['uid'],
                $notTakenText,
                $complaints,
            ));
        }

        // Part of it took: the row changed, so the answer names it as written.
        return ToolResult::text(sprintf(
            'Updated content element [%d] "%s" in part: %s took.%s%s',
            $plan['uid'],
            $this->excerpt($plan['header']),
            implode(', ', $changed),
            $notTakenText,
            $complaints,
        ))->withWriteTarget(new RecordReference(self::TABLE, $plan['uid']), WriteKind::UPDATED);
    }

    /**
     * Every column before and after (ADR-136).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string. NOT checked
     * here: the live-workspace and backend-environment refusals, which describe
     * the process performing the write.
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

        $lines = [sprintf(
            'Content element [%d] "%s" (%s) on page [%d] "%s", language %d — %d field(s):',
            $plan['uid'],
            $this->excerpt($plan['header']),
            $plan['type'],
            $plan['page'],
            $this->excerpt($plan['pageTitle']),
            $plan['language'],
            count($plan['fields']),
        )];

        $columns = $this->columnsOfType($plan['type']);
        foreach ($plan['fields'] as $column => $new) {
            $config = $columns[$column] ?? [];
            $old    = $plan['before'][$column] ?? '';
            $before  = $this->shownValue(is_int($old) ? $old : self::toStr($old), $config);
            $after   = $this->shownValue($new, $config);
            $lines[] = sprintf('%s: %s', $column, $this->beforeAfter($before, $after));
        }

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page permission, the record-level rights
        // and the field-level grants are the acting user's own, checked here and
        // enforced a second time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Setting named columns to given values converges on repeat.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * Everything the write needs, resolved and authorised — or the refusal.
     *
     * The order names instance data last: argument shape first, then the
     * element and the user's rights to it (one neutral refusal), then what the
     * element's type and page allow.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{uid:int, header:string, type:string, page:int, pageTitle:string, language:int, fields:array<non-empty-string, string|int>, before:array<string, mixed>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments($arguments, ['uid', 'fields'], 'changes fields of one content element');
        if ($unknown !== null) {
            return $unknown;
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive tt_content uid of exactly one content element.';
        }

        $raw = $arguments['fields'] ?? null;
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            return 'Refused: "fields" must be an object with at least one column to set.';
        }

        $element = $this->fetchRowByUid(self::TABLE, $uid);
        $page    = $element === null ? null : $this->fetchRowByUid(self::PAGES_TABLE, self::toInt($element['pid'] ?? 0));
        if ($element === null || $page === null
            || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)
            || !$this->mayEditRecord(self::TABLE, $element, $user)
        ) {
            return self::NOT_PERMITTED;
        }

        $type = self::toStr($element['CType'] ?? '');
        if (!in_array($type, $this->availableTypes(), true)) {
            return sprintf(
                'Refused: content element [%d] is of type "%s", which this tool does not edit — raw HTML, plugins, menus, '
                . 'shortcuts and types whose form holds a FlexForm or inline children are left to the backend form '
                . '(ADR-196).',
                $uid,
                preg_replace('/[^A-Za-z0-9_-]/', '', $type) ?? '',
            );
        }

        $fields = $this->collectUpdateFields($raw, $type, $element, $user);
        if (is_string($fields)) {
            return $fields;
        }

        $ungranted = $this->fieldsTheUserMayNotWrite($user, array_keys($fields));
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was '
                . 'written.',
                implode(', ', array_map(static fn(string $column): string => self::TABLE . ':' . $column, $ungranted)),
            );
        }

        $pageUid  = self::toInt($page['uid'] ?? 0);
        $narrowed = $this->pageTsConfigRefusalFor($pageUid, $type, $fields);
        if ($narrowed !== null) {
            return $narrowed;
        }

        $before = [];
        foreach (array_keys($fields) as $column) {
            $before[$column] = $element[$column] ?? '';
        }

        return [
            'uid'       => $uid,
            'header'    => self::toStr($element['header'] ?? ''),
            'type'      => $type,
            'page'      => $pageUid,
            'pageTitle' => self::toStr($page['title'] ?? ''),
            'language'  => $this->languageOf(self::TABLE, $element),
            'fields'    => $fields,
            'before'    => $before,
        ];
    }

    /**
     * The validated column map — the value the DataHandler will be given per
     * column — or the refusal for the first key or value that does not hold,
     * in the order the model sent them.
     *
     * @param array<array-key, mixed> $raw
     * @param array<string, mixed>    $element
     *
     * @return array<non-empty-string, string|int>|string
     */
    private function collectUpdateFields(array $raw, string $type, array $element, BackendUserAuthentication $user): array|string
    {
        $columns  = $this->columnsOfType($type);
        $editable = $this->editableColumnsOf($type);
        $isTranslation = $this->translationParentOf(self::TABLE, $element) > 0;

        $fields = [];
        foreach ($raw as $key => $value) {
            // The key is echoed back so the model can correct itself; it is a
            // name the model itself chose, not instance data.
            $column = preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '';

            if ($column !== '' && $column === $key && $this->isSystemColumn($column)) {
                return sprintf(
                    'Refused: "%s" cannot be set through this tool. Identity, position and visibility are other '
                    . "tools' acts (move_content_element, publish_record), and publication, audience and translation "
                    . 'columns are not editorial.',
                    $column,
                );
            }

            $refusal = array_key_exists($column, $columns) ? $this->keyRefusal($columns[$column]) : null;
            if ($column === $key && $refusal !== null) {
                return sprintf('Refused: "%s" cannot be set: %s.', $column, $refusal);
            }

            if ($column === '' || $column !== $key || !in_array($column, $editable, true)) {
                return sprintf(
                    'Refused: "%s" is not a scalar column of content type "%s". Columns this tool sets for it: %s.',
                    $column,
                    $type,
                    $editable === [] ? 'none' : implode(', ', $editable),
                );
            }

            if ($isTranslation && $this->takenFromTheDefaultLanguage($column)) {
                return sprintf(
                    'Refused: "%s" of a translation is taken from its default-language element [%d] (l10n_mode '
                    . 'exclude); change it there.',
                    $column,
                    $this->translationParentOf(self::TABLE, $element),
                );
            }

            // Bounded by the column's TCA `max`, or by the creating writer's
            // bounds for an unbounded input or text column.
            $checked = $this->fieldValue($column, $value, $columns[$column]);
            if (is_string($checked)) {
                return $checked;
            }

            // The DataHandler asks `authMode` for every select declaring it and
            // drops a value the user is not allowed in silence.
            if (self::toStr($columns[$column]['type'] ?? '') === 'select'
                && (bool)($columns[$column]['authMode'] ?? false)
                && !$user->checkAuthMode(self::TABLE, $column, (string)$checked[0])
            ) {
                return sprintf(
                    'Refused: the acting backend user is not allowed the value "%s" for "%s". Nothing was written.',
                    (string)$checked[0],
                    $column,
                );
            }

            $fields[$column] = $checked[0];
        }

        return $fields;
    }

    /**
     * The columns TYPO3 stores in a shape of its own — rich text, a datetime,
     * an input with an `eval` — that still hold exactly the value they had
     * before although the call asked for a different one.
     *
     * {@see ReadsContentTypeFormsTrait::fieldsThatDidNotTake()} checks those
     * for presence only, which was enough for the creating writer, whose
     * columns start empty. Here the column already holds a value, so a value
     * the DataHandler dropped reads as present; compared with the value
     * before the write, it does not.
     *
     * @param array<string, mixed>                                                                        $stored
     * @param array{type:string, fields:array<non-empty-string, string|int>, before:array<string, mixed>} $plan
     *
     * @return list<string>
     */
    private function unchangedThoughAsked(array $stored, array $plan): array
    {
        $columns  = $this->columnsOfType($plan['type']);
        $notTaken = [];
        foreach ($plan['fields'] as $column => $value) {
            $config = $columns[$column] ?? [];
            if (!$this->isRewrittenOnPurpose(self::toStr($config['type'] ?? ''), $config)) {
                continue;
            }

            $before = self::toStr($plan['before'][$column] ?? '');
            if ((string)$value !== $before && self::toStr($stored[$column] ?? '') === $before) {
                $notTaken[] = $column;
            }
        }

        return $notTaken;
    }

    /**
     * The columns this tool sets for a type: the fillable columns of its form,
     * header and body text included, system columns excluded.
     *
     * @return list<non-empty-string>
     */
    private function editableColumnsOf(string $type): array
    {
        $editable = [];
        foreach ($this->columnsOfType($type) as $name => $config) {
            if (!$this->isSystemColumn($name) && $this->columnKind($config) === 'fillable') {
                $editable[] = $name;
            }
        }

        return $editable;
    }

    /**
     * Whether a translation takes this column from its default-language
     * element: `l10n_mode = exclude` in the column's TCA.
     */
    private function takenFromTheDefaultLanguage(string $column): bool
    {
        $definition = ($this->tcaColumnsFor(self::TABLE) ?? [])[$column] ?? null;

        return is_array($definition) && self::toStr($definition['l10n_mode'] ?? '') === 'exclude';
    }

    /**
     * The refusal the page's TSconfig gives these columns, or null — the
     * column rules of {@see CreateContentElementDraftTool::pageTsConfigRefusal()}
     * applied to the columns this call sets: hidden (`disabled`), read-only
     * (`config.readOnly`), and a select value outside `keepItems` or inside
     * `removeItems`.
     *
     * @param array<non-empty-string, string|int> $fields
     */
    private function pageTsConfigRefusalFor(int $pageUid, string $type, array $fields): ?string
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageUid);
        $tceForm  = is_array($tsConfig['TCEFORM.'] ?? null) ? $tsConfig['TCEFORM.'] : [];
        $rules    = is_array($tceForm[self::TABLE . '.'] ?? null) ? $tceForm[self::TABLE . '.'] : [];
        if ($rules === []) {
            return null;
        }

        $columns = $this->columnsOfType($type);
        foreach ($fields as $column => $value) {
            $rule = $this->tceFormDisabling($rules, $column, $type);
            if ($rule !== null) {
                return sprintf('Refused: "%s" is not shown on page [%d] by its page TSconfig (%s). Nothing was written.', $column, $pageUid, $rule);
            }

            $rule = $this->tceFormReadOnly($rules, $column, $type, self::toStr($columns[$column]['type'] ?? ''));
            if ($rule !== null) {
                return sprintf('Refused: "%s" is read-only on page [%d] by its page TSconfig (%s). Nothing was written.', $column, $pageUid, $rule);
            }

            if (self::toStr($columns[$column]['type'] ?? '') !== 'select') {
                continue;
            }

            $rule = $this->tceFormRuleRemoving($rules, $column, $type, (string)$value);
            if ($rule !== null) {
                return sprintf(
                    'Refused: the value "%s" for "%s" is not offered on page [%d] by its page TSconfig (%s). Nothing was '
                    . 'written.',
                    (string)$value,
                    $column,
                    $pageUid,
                    $rule,
                );
            }
        }

        return null;
    }
}
