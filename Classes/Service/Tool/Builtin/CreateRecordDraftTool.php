<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use DateTimeImmutable;
use Exception;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Create ONE hidden record in a TCA table for which no narrow writer exists,
 * through the DataHandler, as the acting backend user (ADR-197).
 *
 * The one writing tool that is not narrow, and every condition below is what
 * makes that acceptable — removing one reopens ADR-135's argument:
 *
 * - **Tables by exclusion, reviewed once.** The table must be in the loaded
 *   TCA; `pages` and `tt_content` have writers of their own; the read-side
 *   denylist ({@see TableReadAccessService}) and the `sys_*` prefix are out;
 *   so is a table declared `adminOnly`, `hideTable` or `readOnly`; an
 *   installation narrows further through `tools.createRecordDraft.deniedTables`
 *   and cannot widen. The tool steps back wherever another REGISTERED tool
 *   declares that it creates records in the table
 *   ({@see RecordCreatorInterface}), read at call time from the tagged tool
 *   set, so an extension that ships its own creator withdraws this one without
 *   a release here.
 * - **Scalar columns only**, and only those the record type shows. A relation,
 *   a file, a FlexForm, a link, a slug: not an argument. Values are checked by
 *   type, against the record type's own configuration (`columnsOverrides`),
 *   before anything is written; a value the DataHandler would rewrite (an
 *   `eval`, a `min`, a clamp) is refused, and so is what the backend form
 *   shows read-only on the page or the page's TSconfig (TCEFORM) takes out
 *   of it. The record type is resolved the way the DataHandler resolves it,
 *   `TCAdefaults` included, and written where the acting user may write the
 *   type column.
 * - **Always hidden, always the default language, one record.** `hidden` is
 *   forced to 1 and cannot be an argument; the language columns are refused; a
 *   table without a "disabled" enable column is refused outright.
 * - **The acting user's rights, before and after.** `tables_modify`, the
 *   content-edit permission on the pid, the field-level grant for every column
 *   the call sets (the hidden column included), the default language. The
 *   record type the call does not name is not refused for a missing grant: it
 *   is written only where the user holds the type column's field-level and
 *   `authMode` grants, and left to the DataHandler otherwise. What the
 *   DataHandler still drops in silence is read back, the record is deleted
 *   again and the fields are named.
 *
 * It declares no editor action: an editor action is offered on a record of the
 * table it names (ADR-152), and this tool has no such subject. It is reached
 * through the assistant only.
 */
final readonly class CreateRecordDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the ADR-146 writers share.
    use PlansOneEditorialWriteTrait;

    /**
     * One string for "no such page", "deleted" and "you may not edit content
     * there", so a refusal never confirms that a page uid exists. Shared with
     * the other page-addressing tools.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const PAGES_TABLE = 'pages';

    /**
     * The core tables with creators of their own, refused by name as well as
     * through their {@see RecordCreatorInterface} declarations, so the refusal
     * holds even where those creators are not registered, and names the tool
     * to use instead.
     */
    private const TABLES_WITH_A_WRITER = [
        'pages'      => 'create_page_draft',
        'tt_content' => 'create_content_element_draft',
    ];

    private const SYSTEM_TABLE_PREFIX = 'sys_';

    private const CONFIGURATION_KEY = 'tools.createRecordDraft.deniedTables';

    /** The column types a value may be given for; everything else is refused by type. */
    private const SCALAR_TYPES = ['input', 'text', 'number', 'email', 'color', 'datetime', 'check', 'radio', 'select'];

    /**
     * The datetime formats the DataHandler stores verbatim as a timestamp.
     * `date`, `time` and `timesec` are normalised on the way in, and a native
     * `dbType` column is stored in a format the read-back cannot compare.
     */
    private const DATETIME_FORMATS = ['datetime', 'datetimesec'];

    /**
     * Columns that are never arguments, whatever the TCA says about them:
     * identity, visibility, timing, language, ownership and versioning. The
     * table's own `ctrl` adds to this list ({@see self::deniedColumnsOf()}).
     */
    private const DENIED_COLUMNS = [
        'uid', 'pid', 'deleted', 'tstamp', 'crdate', 'cruser_id', 'sorting', 't3_origuid', 'editlock',
        'hidden', 'starttime', 'endtime', 'fe_group',
        'sys_language_uid', 'l10n_parent', 'l18n_parent', 'l10n_source', 'l10n_diffsource', 'l18n_diffsource', 'l10n_state',
    ];

    private const DENIED_COLUMN_PREFIXES = ['perms_', 'TSconfig', 't3ver_'];

    /** The `ctrl` keys whose value names a column the DataHandler owns. */
    private const CTRL_COLUMN_KEYS = [
        'delete', 'tstamp', 'crdate', 'cruser_id', 'sortby', 'origUid', 'editlock',
        'languageField', 'transOrigPointerField', 'transOrigDiffSourceField', 'translationSource',
    ];

    /**
     * The scalar types whose `readOnly` page TSconfig may override in the
     * backend form (`FormEngineUtility::$allowOverrideMatrix`); `radio` has
     * no entry there.
     */
    private const PAGE_READ_ONLY_TYPES = ['input', 'text', 'number', 'email', 'color', 'datetime', 'check', 'select'];

    /** The `eval` tokens {@see DataHandler::checkValue_input_Eval()} acts on in every supported core, `trim` aside. */
    private const INPUT_EVALUATIONS = ['md5', 'upper', 'lower', 'is_in', 'nospace', 'alpha', 'num', 'alphanum', 'alphanum_x', 'domainname'];

    /**
     * The `eval` token only TYPO3 13's checkValue_input_Eval() acts on — it
     * casts the value to an integer; 14 no longer knows it.
     */
    private const INPUT_EVALUATION_BEFORE_14 = 'year';

    /** The `eval` tokens that make an `input` or `email` value unique. */
    private const UNIQUE_EVALUATIONS = ['unique', 'uniqueInPid'];

    /** The tool's own bound for an `input` or `email` column without a TCA `max`; core's default column is `varchar(255)`. */
    private const MAX_INPUT_LENGTH = 255;

    /** The tool's own bound for a `text` column without a TCA `max` — a drafted record, not a document. */
    private const MAX_TEXT_LENGTH = 20000;

    private const TABLE_NAME = '/^[a-z0-9_]{1,64}$/';

    private const COLUMN_NAME = '/^[A-Za-z0-9_]{1,64}$/';

    /** An ISO 8601 date or date-time, with or without seconds and an offset — the subset of what the DataHandler parses that a preview can show unambiguously. */
    private const ISO_DATETIME = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * @param iterable<ToolInterface> $tools every tool registered through the `nr_llm.tool` tag, this one excluded — iterated at call time only, never while the container builds the registry from the same tag
     */
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableReadAccessService $tableReadAccess,
        #[AutowireIterator(ToolInterface::TAG_NAME)]
        private iterable $tools = [],
        private ?ExtensionConfiguration $extensionConfiguration = null,
        private ?LanguageServiceFactory $languageServiceFactory = null,
        private ?Typo3Version $typo3Version = null,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_record_draft',
            'Create ONE new record in a TCA table that has no dedicated writing tool — the fallback for extension '
            . 'tables such as a news record. The record is always created HIDDEN and in the default language, so a '
            . 'human must review and unhide it before it is visible. Writes through the TYPO3 DataHandler as the '
            . 'acting backend user, in the live workspace. Refused: pages and tt_content (use create_page_draft and '
            . 'create_content_element_draft), system and sensitive tables, tables another tool creates records in, and '
            . 'tables the installation excludes. Only scalar columns the record type shows can be set — input, '
            . 'text, number, email, color, datetime, check, radio and select with static items; relations, files, '
            . "links, FlexForms and slugs cannot, and every required column must be given. Read the table's TCA "
            . 'with get_tca first to learn its columns.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The TCA table to create the record in, e.g. tx_news_domain_model_news.',
                    ],
                    'pid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the page or folder to create the record on.',
                    ],
                    'fields' => [
                        'type'        => 'object',
                        'description' => 'Column name to value. A string for input, text, email, color, select and radio; '
                            . 'a number for number; 0 or 1 for check; a UNIX timestamp or an ISO 8601 date-time for '
                            . 'datetime. hidden, uid, pid and the language, timing and ownership columns are never '
                            . 'arguments.',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['table', 'pid', 'fields'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        // `pages` proves that a TCA is loaded at all; the table the call names
        // is checked against it in plan().
        $user = $this->writableActingUser($context, self::PAGES_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $record = ['pid' => $plan['pid']] + $plan['values'];
        // Never negotiable; see the class docblock.
        $record[$plan['hiddenField']] = 1;
        if ($plan['languageField'] !== null) {
            // Stated explicitly: the DataHandler's own permission check on a
            // NEW record reads the language from the incoming fields.
            $record[$plan['languageField']] = 0;
        }

        if ($plan['typeField'] !== null && $plan['typeValue'] !== null) {
            // Stated explicitly as well, where the acting user may write the
            // type column: the record carries the type its fields were checked
            // against, whichever default resolved it.
            $record[$plan['typeField']] = $plan['typeValue'];
        }

        $newUid = $this->createRecord(
            $plan['table'],
            $record,
            $user,
            sprintf(
                'The record was not created. The acting backend user is most likely missing the grant to create '
                . 'records in %s on page [%d], or the table is not allowed on that page type.',
                $plan['table'],
                $plan['pid'],
            ),
        );
        if ($newUid instanceof ToolResult) {
            return $newUid;
        }

        // Read back before reporting success. A uid is proof that a row exists,
        // not that it carries what was asked for: the DataHandler SKIPS a value
        // it does not accept for the acting user — an exclude field without the
        // grant, a select item outside an authMode grant — silently and without
        // logging. For `hidden` that is a safety problem, for every other field
        // a record nobody approved in that shape; both are taken back. A hook
        // can rewrite anything, the language and the record type included.
        $stored = $this->fetchRowByUid($plan['table'], $newUid);
        $wrong  = [];
        if ($stored === null || self::toInt($stored['pid'] ?? 0) !== $plan['pid']) {
            $wrong[] = 'the page differs';
        }

        if ($stored === null || self::toInt($stored[$plan['hiddenField']] ?? 0) !== 1) {
            $wrong[] = 'it is not hidden';
        }

        // What the record carries without an argument naming it: the default
        // language the tool forces, and the record type every value above was
        // checked against — written by the tool, or by the DataHandler from
        // `TCAdefaults` or the TCA default. Not compared where the type is
        // core's fallback and the tool could not write it: the column keeps
        // its database default, which need not be the type's name.
        if ($stored !== null && $plan['languageField'] !== null && self::toInt($stored[$plan['languageField']] ?? 0) !== 0) {
            $wrong[] = sprintf('the language differs (%s)', $plan['languageField']);
        }

        if ($stored !== null && $plan['typeField'] !== null && $plan['typeToVerify'] !== null
            && self::toStr($stored[$plan['typeField']] ?? '') !== $plan['typeToVerify']
        ) {
            $wrong[] = sprintf('the record type differs (%s)', $plan['typeField']);
        }

        $missed = $stored === null
            ? array_keys($plan['values'])
            : $this->fieldsThatDidNotTake($plan['table'], $plan['recordType'], $plan['values'], $stored);
        if ($missed !== []) {
            $wrong[] = 'these fields did not take: ' . implode(', ', $missed);
        }

        if ($wrong !== []) {
            $removed = $this->discard($plan['table'], $newUid, $user);

            return ToolResult::error(sprintf(
                'Record %s:%d was created but did not carry what was asked for (%s), so it %s. The value was dropped '
                . 'or rewritten by TYPO3 without an error — a missing field-level ("exclude field") grant or '
                . '"explicitly allow" grant on a select for the acting backend user, or a hook or evaluation of the '
                . 'installation that changes the record on the way in.',
                $plan['table'],
                $newUid,
                implode('; ', $wrong),
                $removed ? 'was deleted again' : 'COULD NOT BE DELETED and may be reachable — remove it by hand',
            ));
        }

        return ToolResult::text(sprintf(
            'Created hidden %s record [%d] on page [%d] with %s. It is not visible until a human unhides it.',
            $plan['table'],
            $newUid,
            $plan['pid'],
            $this->summarised($plan['display']),
        ))->withWriteTarget(new RecordReference($plan['table'], $newUid), WriteKind::CREATED);
    }

    /**
     * What this call would create, as the approver reads it (ADR-136).
     *
     * One line per field: the column name with its TCA label in English, the
     * value as the record will carry it — a timestamp as an ISO 8601 date-time
     * in UTC, a select or radio value with its item's English label. English,
     * never the viewer's language: ADR-184 compares these lines byte for byte
     * on resume, which can run in another request, worker or language.
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

        $lines = [sprintf(
            'New "%s" record (%s) on page [%d] "%s":',
            $this->excerpt($plan['tableLabel']),
            $plan['table'],
            $plan['pid'],
            $this->excerpt($plan['pageTitle']),
        )];
        foreach ($plan['display'] as $column => $value) {
            $label   = $plan['labels'][$column] ?? '';
            $note    = $plan['notes'][$column] ?? '';
            $lines[] = sprintf(
                '%s%s: %s%s',
                $column,
                $label !== '' ? ' (' . $label . ')' : '',
                $this->quoted($value),
                $note !== '' ? ' (' . $note . ')' : '',
            );
        }

        if ($plan['languageField'] !== null) {
            $lines[] = 'language: default';
        }

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
        // Usable by a non-admin: `tables_modify`, the content-edit permission
        // on the pid and the field-level grants decide, checked by the tool and
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
        // A creation with no caller-supplied key: running it twice leaves two
        // records, not one. A reaped run that may already have created the
        // record must fail terminally rather than draft it again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * Everything the creation needs, resolved and authorised — or the refusal
     * message that stops it.
     *
     * One method for both {@see self::execute()} and {@see self::previewCall()}:
     * the approver must read the record the write will actually produce. The
     * rules that read only the TCA and the configuration come first, the
     * table-level rights of the acting user next, then the page — the one
     * instance datum — so the neutral refusal is the only thing a user without
     * access learns. Everything that depends on the record type comes after
     * the page, because the page's TSconfig (`TCAdefaults`) can decide the
     * type, and a refusal naming the type would otherwise tell a user without
     * access what that page configures.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:non-empty-string, tableLabel:string, recordType:string, pid:int, pageTitle:string, values:array<string, int|float|string>, display:array<string, string>, labels:array<string, string>, notes:array<string, string>, hiddenField:string, languageField:string|null, typeField:string|null, typeValue:string|null, typeToVerify:string|null}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['table', 'pid', 'fields'],
            'creates one hidden record in a table no other writer covers',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $table = $arguments['table'] ?? null;
        if (!is_string($table) || $table === '' || preg_match(self::TABLE_NAME, $table) !== 1) {
            // Not echoed back: a name that is not an identifier is not a name.
            return 'Refused: "table" must be the name of one TCA table — lowercase letters, digits and underscores.';
        }

        $refused = $this->refuseTable($table);
        if ($refused !== null) {
            return $refused;
        }

        $pid = self::toInt($arguments['pid'] ?? 0);
        if ($pid < 1) {
            return 'Refused: "pid" must be the positive uid of exactly one page or folder; records at the root level are not created.';
        }

        $fields = $arguments['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return 'Refused: "fields" must be an object with at least one column to set.';
        }

        // Always the default language; a user without the right to edit it may
        // not draft in it either.
        if (!$user->checkLanguageAccess(0)) {
            return 'Refused: you may not edit records in the default language.';
        }

        if (!$user->isAdmin() && !$user->check('tables_modify', $table)) {
            return sprintf('Refused: the acting backend user may not modify %s (no tables_modify grant).', $table);
        }

        // After every rule that reads only the TCA, the configuration and the
        // user, because this one names instance data. The content-edit
        // permission is what the DataHandler asks for every table but `pages`
        // ({@see DataHandler::hasPermissionToInsert()}); asked here so a user
        // without it gets the neutral words rather than the DataHandler's,
        // which name the page.
        $page = $this->fetchRowByUid(self::PAGES_TABLE, $pid);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
        }

        $type = $this->recordType($table, $fields, $pid, $user);
        if (is_string($type)) {
            return $type;
        }

        $collected = $this->collectValues($table, $type, $fields);
        if (is_string($collected)) {
            return $collected;
        }

        $missing = $this->missingRequiredColumns($table, $type, $collected['values']);
        if ($missing !== []) {
            return sprintf(
                'Refused: %s %s required for record type "%s" of %s and must be given; the tool does not invent values.',
                implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', $missing)),
                count($missing) === 1 ? 'is' : 'are',
                $type['name'],
                $table,
            );
        }

        $hiddenField = $this->hiddenFieldOf($table) ?? 'hidden';
        $ungranted   = $this->columnsTheUserMayNotWrite($user, $table, [...array_keys($collected['values']), $hiddenField]);
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was '
                . 'written — the DataHandler would drop the field in silence and leave a record nobody approved.',
                implode(', ', array_map(static fn(string $column): string => $table . ':' . $column, $ungranted)),
            );
        }

        // The rules are the page's.
        $pageRule = $this->refuseByPageTsConfig($table, $pid, $type['name'], $collected['values']);
        if ($pageRule !== null) {
            return $pageRule;
        }

        // The record type the call does not name is written only where the
        // DataHandler would take it from this user; otherwise it is left to
        // the DataHandler, and the read-back expects what that stores.
        $typeField    = $type['value'] !== null ? $this->typeFieldOf($table) : null;
        $typeValue    = null;
        $typeToVerify = null;
        if ($typeField !== null && $type['value'] !== null) {
            if (array_key_exists($typeField, $collected['values'])) {
                $typeToVerify = $type['value'];
            } elseif ($this->mayWriteTheRecordType($user, $table, $typeField, $type['name'], $type['value'])) {
                $typeValue    = $type['value'];
                $typeToVerify = $type['value'];
            } else {
                $typeToVerify = $type['implied'];
            }
        }

        return [
            'table'         => $table,
            'tableLabel'    => $this->tableLabelOf($table),
            'recordType'    => $type['name'],
            'pid'           => $pid,
            'pageTitle'     => self::toStr($page['title'] ?? ''),
            'values'        => $collected['values'],
            'display'       => $collected['display'],
            'labels'        => $collected['labels'],
            'notes'         => $collected['notes'],
            'hiddenField'   => $hiddenField,
            'languageField' => $this->languageFieldOf($table),
            'typeField'     => $typeField,
            'typeValue'     => $typeValue,
            'typeToVerify'  => $typeToVerify,
        ];
    }

    /**
     * Whether the DataHandler takes the record type the tool resolved from this
     * user: an admin; else the type column is no exclude field or the user
     * holds its `non_exclude_fields` grant ({@see DataHandler::fillInFieldArray()}
     * drops it in silence otherwise), and on a select with `authMode` the user
     * holds the grant for that value ({@see DataHandler::checkValueForSelect()}
     * drops it as silently).
     */
    private function mayWriteTheRecordType(BackendUserAuthentication $user, string $table, string $typeField, string $recordType, string $value): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->columnsTheUserMayNotWrite($user, $table, [$typeField]) !== []) {
            return false;
        }

        $config = $this->columnConfig($table, $typeField, $recordType) ?? [];
        // Any truthy `authMode`, as the DataHandler reads it.
        if (self::toStr($config['type'] ?? '') !== 'select' || !(bool)($config['authMode'] ?? false)) {
            return true;
        }

        return $user->checkAuthMode($table, $typeField, $value);
    }

    /**
     * The refusal for what the page's TSconfig takes out of the backend form,
     * or null.
     *
     * `TCEFORM.<table>.<column>` is enforced by FormEngine only — `disabled`
     * drops the field from the form ({@see \TYPO3\CMS\Backend\Form\Container\SingleFieldContainer}),
     * `keepItems` and `removeItems` filter a select's items
     * ({@see \TYPO3\CMS\Backend\Form\FormDataProvider\AbstractItemProvider}) — and
     * the DataHandler writes either without a word. So a column the form hides
     * on this page is refused, a select value it does not offer is refused, and
     * so is a record type its type field does not offer, whether the call names
     * it or it is the column's default. So is a column the form renders
     * read-only on this page — one flag per column, as FormEngine resolves it:
     * the page's `config.readOnly` where it sets one and the column's type is
     * one whose `readOnly` the form lets page TSconfig override
     * (`FormEngineUtility::overrideFieldConf()`, every scalar type but
     * `radio`), the record type's merged TCA `readOnly` otherwise. A page rule
     * of 0 therefore lifts a TCA `readOnly` of 1. A `types.<type>.` block of
     * the same rule overrides it for that record type, as
     * {@see \TYPO3\CMS\Backend\Form\FormDataProvider\PageTsConfigMerged} merges it.
     * The DataHandler stores a read-only column all the same, so the tool
     * refuses what the form would not let an editor set.
     * Radio items are not filtered: FormEngine applies neither rule to them.
     *
     * @param array<string, int|float|string> $values
     */
    private function refuseByPageTsConfig(string $table, int $pid, string $recordType, array $values): ?string
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pid);
        $tceform  = is_array($tsConfig['TCEFORM.'] ?? null) ? $tsConfig['TCEFORM.'] : [];
        $rules    = is_array($tceform[$table . '.'] ?? null) ? $tceform[$table . '.'] : [];

        $typeField = $this->tcaCtrlFor($table)['type'] ?? null;
        if (is_string($typeField) && $typeField !== ''
            && self::toStr(($this->columnConfig($table, $typeField, $recordType) ?? [])['type'] ?? '') === 'select'
        ) {
            $excluding = $this->pageRuleExcludingItem($rules, $table, $typeField, $recordType, $recordType);
            if ($excluding !== null) {
                return sprintf(
                    'Refused: record type "%s" of %s is not offered on page [%d] — page TSconfig %s takes it out of the backend form.',
                    $recordType,
                    $table,
                    $pid,
                    $excluding,
                );
            }
        }

        foreach ($values as $column => $value) {
            // Any truthy value, as FormEngine reads it (SingleFieldContainer):
            // TypoScript carries strings, so `disabled = true` is "true".
            $disabled = $this->pageRule($rules, $column, 'disabled', $recordType);
            if ($disabled !== null && (bool)$disabled[0]) {
                return sprintf(
                    'Refused: "%s" is disabled on page [%d] — page TSconfig TCEFORM.%s.%s%s takes it out of the backend form.',
                    $column,
                    $pid,
                    $table,
                    $column,
                    $disabled[1],
                );
            }

            $config = $this->columnConfig($table, $column, $recordType) ?? [];
            $kind   = self::toStr($config['type'] ?? '');

            // Any truthy value, as the FormEngine elements read it: TypoScript
            // carries strings, so a page rule of "0" is false and lifts the
            // TCA flag.
            $pageReadOnly = in_array($kind, self::PAGE_READ_ONLY_TYPES, true)
                ? $this->pageConfigRule($rules, $column, 'readOnly', $recordType)
                : null;
            if ($pageReadOnly !== null && (bool)$pageReadOnly[0]) {
                return sprintf(
                    'Refused: "%s" is read-only on page [%d] — page TSconfig TCEFORM.%s.%s%s makes it read-only in the backend form.',
                    $column,
                    $pid,
                    $table,
                    $column,
                    $pageReadOnly[1],
                );
            }

            if ($pageReadOnly === null && (bool)($config['readOnly'] ?? false)) {
                return sprintf(
                    'Refused: "%s" is read-only in the TCA of record type "%s" of %s, so the backend form could not set it either.',
                    $column,
                    $recordType,
                    $table,
                );
            }

            if ($kind !== 'select') {
                continue;
            }

            $excluding = $this->pageRuleExcludingItem($rules, $table, $column, $recordType, self::toStr($value));
            if ($excluding !== null) {
                return sprintf(
                    'Refused: the value for "%s" is not offered on page [%d] — page TSconfig %s takes it out of the backend form.',
                    $column,
                    $pid,
                    $excluding,
                );
            }
        }

        return null;
    }

    /**
     * The full name of the `keepItems` or `removeItems` rule that takes
     * `$value` out of a select, or null — the single-value form of
     * `removeItemsByKeepItemsPageTsConfig()` and
     * `removeItemsByRemoveItemsPageTsConfig()`, applied in that order.
     *
     * @param array<array-key, mixed> $rules the page's `TCEFORM.<table>.` block
     */
    private function pageRuleExcludingItem(array $rules, string $table, string $column, string $recordType, string $value): ?string
    {
        $keep = $this->pageRule($rules, $column, 'keepItems', $recordType);
        if ($keep !== null && is_string($keep[0])
            && ($keep[0] === '' || !in_array($value, GeneralUtility::trimExplode(',', $keep[0], true), true))
        ) {
            return sprintf('TCEFORM.%s.%s%s', $table, $column, $keep[1]);
        }

        $remove = $this->pageRule($rules, $column, 'removeItems', $recordType);
        if ($remove !== null && is_string($remove[0])
            && in_array($value, GeneralUtility::trimExplode(',', $remove[0], true), true)
        ) {
            return sprintf('TCEFORM.%s.%s%s', $table, $column, $remove[1]);
        }

        return null;
    }

    /**
     * One page TSconfig property of a column — the record type's
     * `types.<type>.` block first, the column's own next — with the path it
     * was read from (`.types.story.disabled`), or null where neither sets it.
     *
     * @param array<array-key, mixed> $rules the page's `TCEFORM.<table>.` block
     *
     * @return list{mixed, string}|null
     */
    private function pageRule(array $rules, string $column, string $property, string $recordType): ?array
    {
        $field = $rules[$column . '.'] ?? null;
        if (!is_array($field)) {
            return null;
        }

        $types    = $field['types.'] ?? null;
        $specific = is_array($types) ? ($types[$recordType . '.'] ?? null) : null;
        if (is_array($specific) && array_key_exists($property, $specific)) {
            return [$specific[$property], '.types.' . $recordType . '.' . $property];
        }

        return array_key_exists($property, $field) ? [$field[$property], '.' . $property] : null;
    }

    /**
     * One `config.` property page TSconfig overrides for a column in the
     * backend form — the record type's `types.<type>.config.` block first, the
     * column's own `config.` next, as `PageTsConfigMerged` merges them — with
     * the path it was read from (`.types.story.config.readOnly`), or null
     * where neither sets it.
     *
     * @param array<array-key, mixed> $rules the page's `TCEFORM.<table>.` block
     *
     * @return list{mixed, string}|null
     */
    private function pageConfigRule(array $rules, string $column, string $property, string $recordType): ?array
    {
        $field = $rules[$column . '.'] ?? null;
        if (!is_array($field)) {
            return null;
        }

        $types    = $field['types.'] ?? null;
        $specific = is_array($types) ? ($types[$recordType . '.'] ?? null) : null;
        $config   = is_array($specific) ? ($specific['config.'] ?? null) : null;
        if (is_array($config) && array_key_exists($property, $config)) {
            return [$config[$property], '.types.' . $recordType . '.config.' . $property];
        }

        $config = $field['config.'] ?? null;

        return is_array($config) && array_key_exists($property, $config) ? [$config[$property], '.config.' . $property] : null;
    }

    /**
     * The refusal for a table this tool does not serve, or null when it does.
     *
     * Every rule here reads the TCA, the read-side denylist, the extension
     * configuration or the registered writers — nothing about the instance's
     * data — so it may run before the acting user is looked at.
     */
    private function refuseTable(string $table): ?string
    {
        $ctrl = $this->tcaCtrlFor($table);
        if ($ctrl === null || $this->tcaColumnsFor($table) === null) {
            return sprintf('Refused: "%s" is not a table this installation declares in its TCA.', $table);
        }

        if (isset(self::TABLES_WITH_A_WRITER[$table])) {
            return sprintf('Refused: %s has a writer of its own — use %s.', $table, self::TABLES_WITH_A_WRITER[$table]);
        }

        if (str_starts_with($table, self::SYSTEM_TABLE_PREFIX) || $this->tableReadAccess->isSensitiveTable($table)) {
            return sprintf('Refused: %s is a system or sensitive table no tool writes.', $table);
        }

        foreach (['adminOnly', 'hideTable', 'readOnly'] as $flag) {
            if ($this->flag($ctrl[$flag] ?? false)) {
                return sprintf('Refused: %s is declared %s in its TCA, so this tool does not write it.', $table, $flag);
            }
        }

        $denied = $this->deniedTables();
        if ($denied === null) {
            return sprintf(
                "Refused: the installation's deny-list for this tool (%s) could not be read, so no table is written.",
                self::CONFIGURATION_KEY,
            );
        }

        if (in_array($table, $denied, true)) {
            return sprintf(
                'Refused: the installation excludes %s from this tool (extension configuration %s).',
                $table,
                self::CONFIGURATION_KEY,
            );
        }

        $creator = $this->refuseForAnotherCreator($table);
        if ($creator !== null) {
            return $creator;
        }

        if ($this->hiddenFieldOf($table) === null) {
            return sprintf('Refused: %s has no "disabled" enable column, so a record could not be created hidden.', $table);
        }

        $typeField = $ctrl['type'] ?? null;
        if (is_string($typeField) && str_contains($typeField, ':')) {
            return sprintf('Refused: the record type of %s depends on a related record, which this tool does not resolve.', $table);
        }

        return null;
    }

    /**
     * The tables the installation excludes, or null when the configuration
     * could not be read — which refuses every table, the fail-closed direction
     * the rest of the runtime takes. Only an ABSENT setting means "no
     * exclusions"; a setting that is present but not a comma-separated string,
     * or a path to it that is not a list of settings, cannot be read. A tool
     * constructed without the configuration service (tests) has the shipped
     * default: no exclusions.
     *
     * @return list<string>|null
     */
    private function deniedTables(): ?array
    {
        if (!$this->extensionConfiguration instanceof ExtensionConfiguration) {
            return [];
        }

        try {
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return null;
        }

        $node = $config;
        foreach (['tools', 'createRecordDraft', 'deniedTables'] as $key) {
            if (!is_array($node)) {
                return null;
            }

            if (!array_key_exists($key, $node)) {
                return [];
            }

            $node = $node[$key];
        }

        return is_string($node) ? array_values(GeneralUtility::trimExplode(',', $node, true)) : null;
    }

    /**
     * The refusal for a table another registered tool creates records in, or
     * null when none declares it.
     *
     * The declaration is {@see RecordCreatorInterface::getCreatedTables()}: the
     * table a row lands in. Not the editor-action record types, which name the
     * SUBJECT an editor selects (ADR-152) — a content-element creator declares
     * `pages` there, an updater of a table declares that table without creating
     * in it. Read at call time from the tagged tool set, so a creator another
     * extension ships is seen the day it is installed.
     *
     * A declaration that throws refuses the call and names the tool: it may be
     * the one that covers this table, and skipping it would let the fallback
     * write where a narrow creator exists. Nothing a registered tool throws
     * leaves this method — its name falls back to its class.
     */
    private function refuseForAnotherCreator(string $table): ?string
    {
        foreach ($this->tools as $tool) {
            if (!$tool instanceof RecordCreatorInterface) {
                continue;
            }

            try {
                $tables = $tool->getCreatedTables();
            } catch (Throwable) {
                return sprintf(
                    'Refused: the tables the tool %s creates records in could not be read, so this tool does not know '
                    . 'whether %s has a creator of its own and writes nothing.',
                    $this->nameOf($tool),
                    $table,
                );
            }

            if (in_array($table, $tables, true)) {
                return sprintf('Refused: records in %s are created by the tool %s — use that tool.', $table, $this->nameOf($tool));
            }
        }

        return null;
    }

    /**
     * A registered tool's wire name, or its class where the spec cannot be read.
     */
    private function nameOf(ToolInterface $tool): string
    {
        try {
            return $tool->getSpec()->name;
        } catch (Throwable) {
            return $tool::class;
        }
    }

    /**
     * The record type the call addresses, the value the tool writes into the
     * `ctrl.type` column for it, and the columns its showitem lists, palettes
     * expanded — or the refusal.
     *
     * Resolved the way the DataHandler gives a NEW record its type
     * ({@see DataHandler::applyDefaultsForFieldArray()},
     * {@see DataHandler::newFieldArray()}): the value the call gives for the
     * type column; else `TCAdefaults.<table>.<column>` from the page TSconfig
     * of the pid, which the DataHandler merges over the acting user's; else the
     * user TSconfig's; else the column's TCA default. A value that names no
     * declared type falls through to the next source, and in the end to core's
     * own fallback ({@see BackendUtility::getTCAtypeValue()}): "0", or "1"
     * where no "0" is declared. A call value outside the items is left to the
     * value check, which refuses it; a `TCAdefaults` value that names no
     * declared type is refused here, because the DataHandler would store it as
     * it is and the form show core's fallback type.
     *
     * `value` is the call's where it gives one, the resolved type otherwise —
     * what the tool writes where the acting user may write the type column
     * ({@see self::mayWriteTheRecordType()}), so the record carries the type
     * its fields were checked against. `implied` is what the DataHandler
     * stores in that column on its own when the datamap leaves it out: the
     * resolved type where `TCAdefaults` or the TCA default gave it, null where
     * the call gave it or it is core's fallback, which leaves the column at its
     * database default. Both are null where the table has no plain type column.
     *
     * @param array<array-key, mixed> $fields
     *
     * @return array{name:string, value:string|null, implied:string|null, shown:list<string>, labels:array<string, string>}|string
     */
    private function recordType(string $table, array $fields, int $pid, BackendUserAuthentication $user): array|string
    {
        $tca   = $this->tcaFor($table) ?? [];
        $types = is_array($tca['types'] ?? null) ? $tca['types'] : [];

        // Each candidate with whether the DataHandler stores it in the type
        // column of a new record on its own, when the datamap leaves it out.
        $candidates     = [];
        $undeclaredItem = false;
        $typeField      = $this->typeFieldOf($table);
        $given      = $typeField !== null && array_key_exists($typeField, $fields) ? self::toStr($fields[$typeField]) : null;
        if ($typeField !== null) {
            if ($given !== null) {
                $candidates[] = [$given, false];
            } else {
                $default = $this->tcaDefaultOf(BackendUtility::getPagesTSconfig($pid), $table, $typeField);
                $source  = sprintf('page TSconfig of page [%d]', $pid);
                if ($default === null) {
                    $default = $this->tcaDefaultOf($user->getTSConfig(), $table, $typeField);
                    $source  = "the acting backend user's TSconfig";
                }

                if ($default !== null && !is_array($types[$default] ?? null)) {
                    return sprintf(
                        'Refused: %s sets TCAdefaults.%s.%s to "%s", which is no record type of %s; give "%s" in the call.',
                        $source,
                        $table,
                        $typeField,
                        $default,
                        $table,
                        $typeField,
                    );
                }

                if ($default !== null) {
                    $candidates[] = [$default, true];
                }
            }

            // The column default applies where the datamap leaves the type out.
            // A given value that is an item of the type column but names no
            // type is read as core's fallback, the way
            // BackendUtility::getTCAtypeValue() reads it, never as the column
            // default. A value outside the items keeps the default candidate:
            // the value check refuses it with the items it may take.
            $column = $this->tcaColumnsFor($table)[$typeField] ?? null;
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            $config = is_array($config) ? $config : [];
            $undeclaredItem = $given !== null
                && !is_array($types[$given] ?? null)
                && in_array($given, $this->itemValues($config), true);
            if (!$undeclaredItem && array_key_exists('default', $config)) {
                $candidates[] = [self::toStr($config['default']), true];
            }
        }

        // Core's fallback is a reading of the row, not a value: the column
        // keeps its database default.
        $candidates[] = ['0', false];
        $candidates[] = ['1', false];

        foreach ($candidates as [$name, $stored]) {
            $type = $types[$name] ?? null;
            if (is_array($type)) {
                return [
                    'name'    => $name,
                    'value'   => $typeField === null ? null : ($given ?? $name),
                    'implied' => $typeField !== null && $given === null && $stored ? $name : null,
                ] + $this->columnsShown($table, self::toStr($type['showitem'] ?? ''));
            }
        }

        if ($given !== null && $undeclaredItem) {
            return sprintf(
                'Refused: "%s" is no record type of %s, and the table declares no fallback type "0" or "1" '
                . 'the backend form would show it as; give "%s" as one of its record types.',
                $given,
                $table,
                $typeField,
            );
        }

        return sprintf('Refused: %s declares no record type this tool can read.', $table);
    }

    /**
     * The table's `ctrl.type` column where it is a column of the table itself,
     * or null — a table without one, or with a type in a related record, which
     * {@see self::refuseTable()} refuses.
     */
    private function typeFieldOf(string $table): ?string
    {
        $typeField = ($this->tcaCtrlFor($table) ?? [])['type'] ?? null;
        if (!is_string($typeField) || $typeField === '' || str_contains($typeField, ':')) {
            return null;
        }

        return is_array($this->tcaColumnsFor($table)[$typeField] ?? null) ? $typeField : null;
    }

    /**
     * `TCAdefaults.<table>.<column>` of a TSconfig array as a string, or null
     * where it sets none — the field-level default the DataHandler applies
     * ({@see DataHandler::setDefaultsFromUserTS()}).
     *
     * @param array<array-key, mixed> $tsConfig
     */
    private function tcaDefaultOf(array $tsConfig, string $table, string $column): ?string
    {
        $defaults = $tsConfig['TCAdefaults.'] ?? null;
        $forTable = is_array($defaults) ? ($defaults[$table . '.'] ?? null) : null;
        $value    = is_array($forTable) ? ($forTable[$column] ?? null) : null;

        return is_scalar($value) ? self::toStr($value) : null;
    }

    /**
     * The column names a showitem string lists, `--palette--` entries expanded
     * through the table's palettes and `--div--` / `--linebreak--` skipped —
     * with the label a `field;Label` entry gives a column there, where one
     * does. As core builds a sub-schema (TcaSchemaBuilder), a column's last
     * entry decides, and the label of a `--palette--;Label;name` entry is the
     * palette's, not a column's.
     *
     * @return array{shown:list<string>, labels:array<string, string>}
     */
    private function columnsShown(string $table, string $showitem): array
    {
        $tca      = $this->tcaFor($table) ?? [];
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];

        $shown  = [];
        $labels = [];
        foreach (GeneralUtility::trimExplode(',', $showitem, true) as $item) {
            $parts = GeneralUtility::trimExplode(';', $item);
            $name  = $parts[0] ?? '';
            if ($name === '--palette--') {
                $palette = $palettes[$parts[2] ?? ''] ?? null;
                $inner   = is_array($palette) ? self::toStr($palette['showitem'] ?? '') : '';
                foreach (GeneralUtility::trimExplode(',', $inner, true) as $paletteItem) {
                    $paletteParts = GeneralUtility::trimExplode(';', $paletteItem);
                    $field        = $paletteParts[0] ?? '';
                    if ($field !== '' && !str_starts_with($field, '--')) {
                        $shown[]        = $field;
                        $labels[$field] = $paletteParts[1] ?? '';
                    }
                }

                continue;
            }

            if ($name !== '' && !str_starts_with($name, '--')) {
                $shown[]       = $name;
                $labels[$name] = $parts[1] ?? '';
            }
        }

        return [
            'shown'  => array_values(array_unique($shown)),
            'labels' => array_filter($labels, static fn(string $label): bool => $label !== ''),
        ];
    }

    /**
     * Every field of the call, checked by column and by type — or the refusal
     * for the first one that does not hold.
     *
     * `values` is what the DataHandler receives (a datetime as its timestamp),
     * `display` what the approver reads (a datetime as an ISO 8601 date-time),
     * `labels` the column labels in English, `notes` the English label of the
     * item a select or radio value names.
     *
     * @param array{name:string, value:string|null, implied:string|null, shown:list<string>, labels:array<string, string>} $type
     * @param array<array-key, mixed>                                                                                      $fields
     *
     * @return array{values:array<string, int|float|string>, display:array<string, string>, labels:array<string, string>, notes:array<string, string>}|string
     */
    private function collectValues(string $table, array $type, array $fields): array|string
    {
        $denied = $this->deniedColumnsOf($table);

        $values  = [];
        $display = [];
        $labels  = [];
        $notes   = [];
        foreach ($fields as $name => $value) {
            $column = self::toStr($name);
            if (preg_match(self::COLUMN_NAME, $column) !== 1) {
                return 'Refused: a column name in "fields" is not a valid identifier.';
            }

            if ($this->isDeniedColumn($column, $denied)) {
                return sprintf(
                    'Refused: "%s" is not a column this tool sets — identity, visibility, timing, language, ownership '
                    . 'and versioning columns are never arguments, and "hidden" is always 1.',
                    $column,
                );
            }

            $config = $this->columnConfig($table, $column, $type['name']);
            if ($config === null) {
                return sprintf('Refused: "%s" is not a column of %s.', $column, $table);
            }

            $kind = self::toStr($config['type'] ?? '');
            if (!$this->isScalar($kind, $config)) {
                return sprintf(
                    'Refused: "%s" is a %s column this tool cannot set; it sets only scalar columns (%s) — a select '
                    . 'or radio only with static items, a datetime only as a timestamp.',
                    $column,
                    $kind !== '' ? $kind : 'untyped',
                    implode(', ', self::SCALAR_TYPES),
                );
            }

            $evaluation = $this->rewritingEvaluation($kind, $config);
            if ($evaluation !== null) {
                return sprintf(
                    'Refused: "%s" carries eval "%s", which TYPO3 applies to the value on the way in — it rewrites '
                    . 'the value or makes it unique — so the record would not carry the value the approver read.',
                    $column,
                    $evaluation,
                );
            }

            if (!in_array($column, $type['shown'], true)) {
                return sprintf(
                    'Refused: "%s" is not shown for record type "%s" of %s, so the backend form could not set it either.',
                    $column,
                    $type['name'],
                    $table,
                );
            }

            $checked = $this->checkValue($column, $kind, $config, $value);
            if (is_string($checked)) {
                return $checked;
            }

            $values[$column]  = $checked[0];
            $display[$column] = $checked[1];
            $labels[$column]  = $this->labelOf($table, $column, $type);
            if ($kind === 'select' || $kind === 'radio') {
                $notes[$column] = $this->itemLabelOf($config, $checked[1]);
            }
        }

        return ['values' => $values, 'display' => $display, 'labels' => $labels, 'notes' => $notes];
    }

    /**
     * Whether a column of this type takes a plain value the tool can check
     * and read back.
     *
     * @param array<array-key, mixed> $config
     */
    private function isScalar(string $kind, array $config): bool
    {
        if (!in_array($kind, self::SCALAR_TYPES, true)) {
            return false;
        }

        if ($kind === 'datetime') {
            return !isset($config['dbType'])
                && in_array(self::toStr($config['format'] ?? 'datetime'), self::DATETIME_FORMATS, true);
        }

        if ($kind === 'select' || $kind === 'radio') {
            $items = $config['items'] ?? null;

            return is_array($items) && $items !== []
                && !isset($config['foreign_table'])
                && !isset($config['itemsProcFunc'])
                && !isset($config['itemsProcessors'])
                && !isset($config['MM'])
                && ($kind === 'radio' || self::toStr($config['renderType'] ?? 'selectSingle') === 'selectSingle');
        }

        return true;
    }

    /**
     * The first `eval` token the DataHandler would act on for a value of this
     * column, or null where there is none.
     *
     * Exactly the tokens the DataHandler acts on, per column type: an `input`
     * the ones {@see DataHandler::checkValue_input_Eval()} names, which rewrite
     * the value (`upper`, `lower`, `nospace`, `alpha`, `num`, `alphanum`,
     * `alphanum_x`, `is_in`, `domainname`, and on TYPO3 13 `year`) or drop it
     * (`md5`), and the two
     * uniqueness tokens of {@see DataHandler::checkValueForInput()}; a `text`
     * none of its own ({@see DataHandler::checkValue_text_Eval()}); an `email`
     * only the uniqueness tokens. `input` and `text` also hand a token
     * registered in `SC_OPTIONS.tce.formevals` to an extension's class. `trim`
     * is the one evaluation the tool applies itself. Every other token is
     * ignored by the DataHandler and therefore here — a legacy `required` or
     * `null` left in a `columnsOverrides` eval, which TcaMigration moves out
     * of the base column only, included. The read-back would find a
     * normalised value differs and delete a record the DataHandler merely
     * normalised, so the column is refused before the write.
     *
     * @param array<array-key, mixed> $config
     */
    private function rewritingEvaluation(string $kind, array $config): ?string
    {
        $acted = match ($kind) {
            'input' => [...$this->inputEvaluations(), ...self::UNIQUE_EVALUATIONS],
            'email' => self::UNIQUE_EVALUATIONS,
            'text'  => [],
            default => null,
        };
        if ($acted === null) {
            return null;
        }

        foreach (GeneralUtility::trimExplode(',', self::toStr($config['eval'] ?? ''), true) as $token) {
            if (in_array($token, $acted, true) || ($kind !== 'email' && $this->isRegisteredEvaluation($token))) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The `eval` tokens the running core's
     * {@see DataHandler::checkValue_input_Eval()} acts on, `trim` aside:
     * `year` only before TYPO3 14.
     *
     * @return list<string>
     */
    private function inputEvaluations(): array
    {
        $version = $this->typo3Version ?? new Typo3Version();

        return $version->getMajorVersion() < 14
            ? [...self::INPUT_EVALUATIONS, self::INPUT_EVALUATION_BEFORE_14]
            : self::INPUT_EVALUATIONS;
    }

    /**
     * Whether an extension registered `$token` as an evaluation class, where
     * the DataHandler looks it up.
     */
    private function isRegisteredEvaluation(string $token): bool
    {
        $confVars  = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $scOptions = is_array($confVars) ? ($confVars['SC_OPTIONS'] ?? null) : null;
        $tce       = is_array($scOptions) ? ($scOptions['tce'] ?? null) : null;
        $formevals = is_array($tce) ? ($tce['formevals'] ?? null) : null;

        return is_array($formevals) && array_key_exists($token, $formevals);
    }

    /**
     * The value the DataHandler receives and the text the approver reads,
     * WRAPPED in a two-element list — or the refusal message.
     *
     * Wrapped for the same reason {@see CreateContentElementDraftTool::text()}
     * wraps: a valid text and a refusal are both strings.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{int|float|string, string}|string
     */
    private function checkValue(string $column, string $kind, array $config, mixed $value): array|string
    {
        return match ($kind) {
            'check'           => $this->checkFlag($column, $value),
            'number'          => $this->checkNumber($column, $config, $value),
            'datetime'        => $this->checkDatetime($column, $config, $value),
            'select', 'radio' => $this->checkChoice($column, $config, $value),
            'email'           => $this->checkEmail($column, $config, $value),
            'color'           => $this->checkColor($column, $config, $value),
            default           => $this->checkText($column, $kind, $config, $value),
        };
    }

    /**
     * @return list{int, string}|string
     */
    private function checkFlag(string $column, mixed $value): array|string
    {
        $flag = match (true) {
            is_bool($value)                                                    => (int)$value,
            is_int($value)                                                     => $value,
            is_string($value) && preg_match('/^[01]$/', $value) === 1          => (int)$value,
            default                                                            => null,
        };
        if ($flag !== 0 && $flag !== 1) {
            return sprintf('Refused: the value for "%s" must be 0 or 1.', $column);
        }

        return [$flag, (string)$flag];
    }

    /**
     * An integer or a decimal as the TCA `format` says, within `range`. The
     * DataHandler would clamp a value outside the range in silence; the tool
     * refuses it, so the approver never reads a value the record will not
     * carry.
     *
     * A decimal is stored through `number_format($value, 2)` whatever the
     * column (DataHandler::checkValueForNumber()), so one with a third decimal
     * place is refused rather than rounded — 1.234 would be stored as 1.23. A
     * valid one is handed on and shown in that same two-place form, which the
     * DataHandler parses back unchanged.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{int|string, string}|string
     */
    private function checkNumber(string $column, array $config, mixed $value): array|string
    {
        $decimal = self::toStr($config['format'] ?? 'integer') === 'decimal';
        if ($decimal) {
            if (!is_int($value) && !is_float($value) && (!is_string($value) || !is_numeric($value))) {
                return sprintf('Refused: the value for "%s" must be a number.', $column);
            }

            $number = (float)$value;
            // Compared with a tolerance far below a hundredth, so the binary
            // form of a two-place value (1.1 + 2.2) is not mistaken for more;
            // relative to the value, and small enough that it stays below a
            // half hundredth up to 5e9, beyond what a double(11,2) column holds.
            if (abs($number - round($number, 2)) > 1e-12 * max(1.0, abs($number))) {
                return sprintf(
                    'Refused: the value for "%s" has more than two decimal places; TYPO3 stores a decimal rounded to '
                    . 'two, so the record would not carry the value the approver read.',
                    $column,
                );
            }

            $stored  = number_format($number, 2, '.', '');
            $checked = (float)$stored;
        } else {
            $checked = match (true) {
                is_int($value)                                                  => $value,
                is_float($value) && floor($value) === $value                    => (int)$value,
                is_string($value) && preg_match('/^-?\d+$/', $value) === 1     => (int)$value,
                default                                                         => null,
            };
            if ($checked === null) {
                return sprintf('Refused: the value for "%s" must be an integer.', $column);
            }

            $stored = $checked;
        }

        // As the DataHandler compares it (checkValueForNumber()): the value it
        // stores, rounded up against the upper bound and down against the
        // lower one, so a decimal inside the range can still be clamped to a
        // bound. For an integer the rounding changes nothing.
        $range = is_array($config['range'] ?? null) ? $config['range'] : [];
        $lower = is_numeric($range['lower'] ?? null) ? (float)$range['lower'] : null;
        $upper = is_numeric($range['upper'] ?? null) ? (float)$range['upper'] : null;
        if (($lower !== null && floor($checked) < $lower) || ($upper !== null && ceil($checked) > $upper)) {
            return sprintf(
                'Refused: the value for "%s" is outside the range %s..%s%s.',
                $column,
                self::toStr($range['lower'] ?? ''),
                self::toStr($range['upper'] ?? ''),
                $decimal ? ' as TYPO3 checks a decimal — rounded up against the upper bound, down against the lower one' : '',
            );
        }

        return [$stored, self::toStr($stored)];
    }

    /**
     * A UNIX timestamp or an ISO 8601 date-time, handed to the DataHandler as
     * the timestamp so both cores store exactly that, within `range`.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{int, string}|string
     */
    private function checkDatetime(string $column, array $config, mixed $value): array|string
    {
        $refusal = sprintf(
            'Refused: the value for "%s" must be a UNIX timestamp or an ISO 8601 date-time such as 2026-09-21T10:00:00+02:00.',
            $column,
        );

        $text = is_string($value) ? trim($value) : null;
        if (is_int($value) && $value >= 0) {
            $timestamp = $value;
        } elseif ($text !== null && preg_match('/^\d{1,11}$/', $text) === 1) {
            $timestamp = (int)$text;
        } elseif ($text !== null && preg_match(self::ISO_DATETIME, $text) === 1) {
            try {
                $moment = new DateTimeImmutable($text);
            } catch (Exception) {
                return $refusal;
            }

            // PHP rolls a date that does not exist over into the next month
            // (2026-02-30 becomes 2026-03-02) and only records a warning. The
            // DataHandler would store the rolled-over moment and the read-back
            // would find exactly that, so the refusal has to happen here.
            // ISO 8601's end-of-day `24:00` is refused the same way ("The
            // parsed time was invalid"); the refusal says how to write it.
            $problems = DateTimeImmutable::getLastErrors();
            if (is_array($problems) && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) {
                return preg_match('/[T ]24:00/', $text) === 1
                    ? rtrim($refusal, '.') . ' — write midnight at the end of a day as 00:00 of the next day.'
                    : $refusal;
            }

            $timestamp = $moment->getTimestamp();
        } else {
            return $refusal;
        }

        $range = is_array($config['range'] ?? null) ? $config['range'] : [];
        $lower = is_numeric($range['lower'] ?? null) ? (int)$range['lower'] : null;
        $upper = is_numeric($range['upper'] ?? null) ? (int)$range['upper'] : null;
        if (($lower !== null && $timestamp < $lower) || ($upper !== null && $timestamp > $upper)) {
            return sprintf('Refused: the value for "%s" is outside the range the TCA allows.', $column);
        }

        // Shown in UTC, whatever the server's zone: the preview is compared
        // byte for byte on resume.
        return [$timestamp, (new DateTimeImmutable('@' . $timestamp))->format(DATE_ATOM)];
    }

    /**
     * A value among the static items, as ADR-194 checks a select: the empty
     * string only where an item declares it, a divider never.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string, string}|string
     */
    private function checkChoice(string $column, array $config, mixed $value): array|string
    {
        $allowed = $this->itemValues($config);
        $text    = is_scalar($value) ? self::toStr($value) : null;
        if ($text === null || !in_array($text, $allowed, true)) {
            // The refused value is not echoed back; the allowed ones are
            // configuration, not instance data.
            return sprintf(
                'Refused: the value for "%s" must be one of: %s.',
                $column,
                implode(', ', array_map(static fn(string $item): string => '"' . $item . '"', $allowed)),
            );
        }

        return [$text, $text];
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return list{string, string}|string
     */
    private function checkEmail(string $column, array $config, mixed $value): array|string
    {
        $checked = $this->checkText($column, 'input', $config, $value);
        if (is_string($checked)) {
            return $checked;
        }

        $text = self::toStr($checked[0]);
        if ($text !== '' && !GeneralUtility::validEmail($text)) {
            return sprintf('Refused: the value for "%s" is not a valid e-mail address.', $column);
        }

        return [$text, $text];
    }

    /**
     * Six hexadecimal digits, or eight where the column declares `opacity` —
     * the DataHandler cuts any other colour to seven characters.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string, string}|string
     */
    private function checkColor(string $column, array $config, mixed $value): array|string
    {
        $pattern = $this->flag($config['opacity'] ?? false) ? '/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/' : '/^#[0-9A-Fa-f]{6}$/';
        $text    = is_string($value) ? trim($value) : null;
        if ($text === null || ($text !== '' && preg_match($pattern, $text) !== 1)) {
            return sprintf('Refused: the value for "%s" must be a hexadecimal colour such as #2f99a4.', $column);
        }

        return [$text, $text];
    }

    /**
     * A string within the TCA `max`, or within the tool's own bound where the
     * TCA declares none.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list{string, string}|string
     */
    private function checkText(string $column, string $kind, array $config, mixed $value): array|string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return sprintf('Refused: the value for "%s" must be a string.', $column);
        }

        $text = trim(self::toStr($value));
        $max  = is_int($config['max'] ?? null) && $config['max'] > 0
            ? $config['max']
            : ($kind === 'text' ? self::MAX_TEXT_LENGTH : self::MAX_INPUT_LENGTH);
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $column, $max);
        }

        // The DataHandler stores a shorter value as '' in silence
        // (checkValueForInput(), checkValueForText()); rich text is exempt there.
        $min = is_numeric($config['min'] ?? null) ? (int)$config['min'] : 0;
        if ($text !== '' && $min > 0 && mb_strlen($text) < $min && !$this->flag($config['enableRichtext'] ?? false)) {
            return sprintf('Refused: the value for "%s" must be at least %d characters long; TYPO3 would store a shorter one empty.', $column, $min);
        }

        return [$text, $text];
    }

    /**
     * The values the static items of a select or radio declare.
     *
     * @param array<array-key, mixed> $config
     *
     * @return list<string>
     */
    private function itemValues(array $config): array
    {
        $values = [];
        foreach (is_array($config['items'] ?? null) ? $config['items'] : [] as $item) {
            if (!is_array($item) || !array_key_exists('value', $item) || $item['value'] === '--div--') {
                continue;
            }

            $values[] = self::toStr($item['value']);
        }

        return $values;
    }

    /**
     * The shown columns the TCA marks `required` that the call leaves out or
     * leaves empty — the DataHandler drops an empty required value in silence
     * ({@see UpdatePageMetadataTool}), so the refusal names it first.
     *
     * @param array{name:string, value:string|null, implied:string|null, shown:list<string>, labels:array<string, string>} $type
     * @param array<string, int|float|string>                                                                              $values
     *
     * @return list<string>
     */
    private function missingRequiredColumns(string $table, array $type, array $values): array
    {
        $denied = $this->deniedColumnsOf($table);

        $missing = [];
        foreach ($type['shown'] as $column) {
            if ($this->isDeniedColumn($column, $denied)) {
                continue;
            }

            $config = $this->columnConfig($table, $column, $type['name']);
            if ($config === null || !$this->flag($config['required'] ?? false)) {
                continue;
            }

            $value = $values[$column] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /**
     * The columns among `$columns` this user may not write, because the TCA
     * marks them `exclude` and the user holds no `non_exclude_fields` grant.
     *
     * Asked BEFORE the write, through the same
     * `BackendUserAuthentication::check('non_exclude_fields', …)` the
     * DataHandler asks it with, because the DataHandler drops such a column in
     * silence; the read-back stays as the backstop for what this does not model.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function columnsTheUserMayNotWrite(BackendUserAuthentication $user, string $table, array $columns): array
    {
        $definitions = $this->tcaColumnsFor($table) ?? [];

        $ungranted = [];
        foreach (array_unique($columns) as $column) {
            $definition = $definitions[$column] ?? null;
            // Any truthy value, as core reads it (AbstractFieldType::supportsAccessControl()).
            $excluded = is_array($definition) && (bool)($definition['exclude'] ?? false);
            if ($excluded && !$user->check('non_exclude_fields', $table . ':' . $column)) {
                $ungranted[] = $column;
            }
        }

        return $ungranted;
    }

    /**
     * The columns whose stored value is NOT the requested one after the write.
     *
     * Compared by type: integers and timestamps as numbers, a decimal as its
     * two-place string on both sides — exact, since a third decimal place is
     * refused before the write, and independent of whether the database hands
     * back 4.20, 4.2 or a float — everything else as the string the
     * DataHandler stores. A rich-text column is the exception —
     * the RTE rewrites its markup on the way in, so only its presence can be
     * checked there.
     *
     * @param array<string, int|float|string> $values
     * @param array<string, mixed>            $stored
     *
     * @return list<string>
     */
    private function fieldsThatDidNotTake(string $table, string $recordType, array $values, array $stored): array
    {
        $missed = [];
        foreach ($values as $column => $requested) {
            $config      = $this->columnConfig($table, $column, $recordType) ?? [];
            $kind        = self::toStr($config['type'] ?? '');
            $storedValue = $stored[$column] ?? null;

            $took = match (true) {
                $kind === 'check' || $kind === 'datetime'
                    || ($kind === 'number' && self::toStr($config['format'] ?? 'integer') !== 'decimal')
                    => self::toInt($storedValue) === self::toInt($requested),
                $kind === 'number'
                    => number_format(self::toFloat($storedValue), 2, '.', '') === number_format(self::toFloat($requested), 2, '.', ''),
                $kind === 'text' && $this->flag($config['enableRichtext'] ?? false)
                    => trim(self::toStr($requested)) === '' || trim(self::toStr($storedValue)) !== '',
                default
                => self::toStr($storedValue) === self::toStr($requested),
            };
            if (!$took) {
                $missed[] = $column;
            }
        }

        return $missed;
    }

    /**
     * Delete a record this tool created but could not vouch for, reporting
     * whether it is gone.
     *
     * Through the DataHandler under the same acting user, so the row goes to
     * `deleted = 1` and the removal is in `sys_log` next to the creation rather
     * than appearing out of nowhere.
     *
     * @param non-empty-string $table
     */
    private function discard(string $table, int $uid, BackendUserAuthentication $user): bool
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        return $this->fetchRowByUid($table, $uid) === null;
    }

    /**
     * The columns of this table no call may set: the static list plus every
     * column the table's `ctrl` hands to the DataHandler.
     *
     * @return list<string>
     */
    private function deniedColumnsOf(string $table): array
    {
        $ctrl  = $this->tcaCtrlFor($table) ?? [];
        $names = self::DENIED_COLUMNS;
        foreach (self::CTRL_COLUMN_KEYS as $key) {
            $name = $ctrl[$key] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        $enable = $ctrl['enablecolumns'] ?? null;
        foreach (is_array($enable) ? $enable : [] as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $denied
     */
    private function isDeniedColumn(string $column, array $denied): bool
    {
        if (in_array($column, $denied, true)) {
            return true;
        }

        foreach (self::DENIED_COLUMN_PREFIXES as $prefix) {
            if (str_starts_with($column, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column's label for the record type in English, or '' where none is
     * declared: the showitem's `field;Label` first, the label of the column
     * with the type's `columnsOverrides` merged over it next — the record
     * type's TCA label, as core builds it (TcaSchemaBuilder). A page TSconfig
     * label override (`TCEFORM.<table>.<column>.label`), which the backend
     * form shows, is not applied: the preview must not depend on the page or
     * on the viewer's language.
     *
     * @param array{name:string, value:string|null, implied:string|null, shown:list<string>, labels:array<string, string>} $type
     */
    private function labelOf(string $table, string $column, array $type): string
    {
        $label = $type['labels'][$column] ?? '';
        if ($label === '') {
            $definition = $this->columnDefinition($table, $column, $type['name']) ?? [];
            $label      = self::toStr($definition['label'] ?? '');
        }

        return rtrim($this->englishLabel($label), ':');
    }

    private function tableLabelOf(string $table): string
    {
        $ctrl  = $this->tcaCtrlFor($table) ?? [];
        $label = $this->englishLabel(self::toStr($ctrl['title'] ?? ''));

        return $label !== '' ? $label : $table;
    }

    /**
     * The English label of the static item whose value is `$value`, or ''.
     *
     * @param array<array-key, mixed> $config
     */
    private function itemLabelOf(array $config, string $value): string
    {
        foreach (is_array($config['items'] ?? null) ? $config['items'] : [] as $item) {
            if (is_array($item) && array_key_exists('value', $item) && self::toStr($item['value']) === $value) {
                return $this->englishLabel(self::toStr($item['label'] ?? ''));
            }
        }

        return '';
    }

    /**
     * A TCA label in English: a literal as written, an `LLL:` reference
     * resolved through an English language service — never the ambient one
     * (`$GLOBALS['LANG']`), which belongs to whoever happens to run the call.
     * '' where an `LLL:` reference cannot be resolved, so a raw key never
     * reaches the approver.
     */
    private function englishLabel(string $label): string
    {
        $label = trim($label);
        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }

        if (!$this->languageServiceFactory instanceof LanguageServiceFactory) {
            return '';
        }

        return trim($this->languageServiceFactory->create('default')->sL($label));
    }

    /**
     * A column's TCA `config` for the given record type, or null where the
     * table has no such column.
     *
     * The configuration the DataHandler validates a value against
     * (`DataHandler::resolveFieldConfigurationAndRespectColumnsOverrides()`).
     * A `required`, a `max`, the items of a select or `enableRichtext` may
     * exist only for one type.
     *
     * @return array<array-key, mixed>|null
     */
    private function columnConfig(string $table, string $column, string $recordType): ?array
    {
        $config = ($this->columnDefinition($table, $column, $recordType) ?? [])['config'] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * A column's TCA definition for the given record type, or null where the
     * table has no such column: the base definition with the type's
     * `columnsOverrides` merged over it, the way core builds the field of a
     * record type's sub-schema ({@see \TYPO3\CMS\Core\Schema\TcaSchemaBuilder},
     * `array_replace_recursive`) — its `config` and its `label` alike.
     *
     * @return array<array-key, mixed>|null
     */
    private function columnDefinition(string $table, string $column, string $recordType): ?array
    {
        $definition = $this->tcaColumnsFor($table)[$column] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        $types     = $this->tcaFor($table)['types'] ?? null;
        $type      = is_array($types) ? ($types[$recordType] ?? null) : null;
        $overrides = is_array($type) ? ($type['columnsOverrides'] ?? null) : null;
        $override  = is_array($overrides) ? ($overrides[$column] ?? null) : null;

        return is_array($override) ? array_replace_recursive($definition, $override) : $definition;
    }

    /**
     * The name of the table's "disabled" enable column as the installation
     * declares it, or null where there is none.
     */
    private function hiddenFieldOf(string $table): ?string
    {
        $ctrl = $this->tcaCtrlFor($table) ?? [];
        $cols = $ctrl['enablecolumns'] ?? null;
        $name = is_array($cols) ? ($cols['disabled'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function languageFieldOf(string $table): ?string
    {
        $ctrl = $this->tcaCtrlFor($table) ?? [];
        $name = $ctrl['languageField'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * A table's TCA, or null when the loaded TCA declares no such table.
     *
     * @return array<array-key, mixed>|null
     */
    private function tcaFor(string $table): ?array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[$table] ?? null)) {
            return null;
        }

        return $tca[$table];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function tcaCtrlFor(string $table): ?array
    {
        $tca  = $this->tcaFor($table);
        $ctrl = $tca === null ? null : ($tca['ctrl'] ?? null);

        return is_array($ctrl) ? $ctrl : null;
    }

    /**
     * A TCA boolean, which the core writes as `true` and older code as `1`.
     */
    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /**
     * The fields of the success line: `title: "Drafted item", priority: "3"`.
     *
     * @param array<string, string> $display
     */
    private function summarised(array $display): string
    {
        $parts = [];
        foreach ($display as $column => $value) {
            $parts[] = sprintf('%s: %s', $column, $this->quoted($value));
        }

        return implode(', ', $parts);
    }
}
