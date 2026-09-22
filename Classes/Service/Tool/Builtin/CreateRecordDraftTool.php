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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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
 *   type before anything is written.
 * - **Always hidden, always the default language, one record.** `hidden` is
 *   forced to 1 and cannot be an argument; the language columns are refused; a
 *   table without a "disabled" enable column is refused outright.
 * - **The acting user's rights, before and after.** `tables_modify`, the
 *   content-edit permission on the pid, the field-level grant for every column
 *   the call sets (the hidden column included), the default language. What the
 *   DataHandler still drops in silence is read back, the record is deleted
 *   again and the fields are named.
 *
 * It declares no editor action: a declaration names the tables a writer OWNS
 * (ADR-152), and this one owns none. It is reached through the assistant only.
 */
final readonly class CreateRecordDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the ADR-146 writers share.
    use PlansOneEditorialWriteTrait;
    use ResolvesLanguageLabelTrait;

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
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_record_draft',
            'Create ONE new record in a TCA table that has no dedicated writing tool — the fallback for extension '
            . 'tables such as a news record. The record is always created HIDDEN and in the default language, so a '
            . 'human must review and unhide it before it is visible. Writes through the TYPO3 DataHandler as the '
            . 'acting backend user, in the live workspace. Refused: pages and tt_content (use create_page_draft and '
            . 'create_content_element_draft), system and sensitive tables, tables another writing tool owns, and '
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
        // a record nobody approved in that shape; both are taken back.
        $stored = $this->fetchRowByUid($plan['table'], $newUid);
        $wrong  = [];
        if ($stored === null || self::toInt($stored['pid'] ?? 0) !== $plan['pid']) {
            $wrong[] = 'the page differs';
        }

        if ($stored === null || self::toInt($stored[$plan['hiddenField']] ?? 0) !== 1) {
            $wrong[] = 'it is not hidden';
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
     * One line per field, labelled the way the backend form labels the column
     * in the viewer's language, so the approver reads "Published at: …" rather
     * than a column name. Authorised exactly like {@see self::execute()} and
     * against the same EXPLICIT acting user, down to the neutral refusal string.
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
            $lines[] = sprintf('%s: %s', $plan['labels'][$column] ?? $column, $this->quoted($value));
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
     * rules that read only the TCA and the configuration come first, the rights
     * of the acting user next, and the page — the one instance datum — last,
     * so the neutral refusal is the only thing a user without access learns.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:non-empty-string, tableLabel:string, recordType:string, pid:int, pageTitle:string, values:array<string, int|float|string>, display:array<string, string>, labels:array<string, string>, hiddenField:string, languageField:string|null}|string
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

        $type = $this->recordType($table, $fields);
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

        // Always the default language; a user without the right to edit it may
        // not draft in it either.
        if (!$user->checkLanguageAccess(0)) {
            return 'Refused: you may not edit records in the default language.';
        }

        if (!$user->isAdmin() && !$user->check('tables_modify', $table)) {
            return sprintf('Refused: the acting backend user may not modify %s (no tables_modify grant).', $table);
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

        // After every TCA rule, because this one names instance data. The
        // content-edit permission is what the DataHandler asks for every table
        // but `pages` ({@see DataHandler::hasPermissionToInsert()}); asked here
        // so a user without it gets the neutral words rather than the
        // DataHandler's, which name the page.
        $page = $this->fetchRowByUid(self::PAGES_TABLE, $pid);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
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
            'hiddenField'   => $hiddenField,
            'languageField' => $this->languageFieldOf($table),
        ];
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
     * The record type the call addresses and the columns its showitem lists,
     * palettes expanded — or the refusal when the table declares none.
     *
     * The type value comes from the call where it names the `ctrl.type`
     * column, from that column's default otherwise. A value that names no
     * declared type is left to the value check, which refuses it against the
     * items; the showitem then comes from the default type, or from core's own
     * fallback ({@see \TYPO3\CMS\Backend\Utility\BackendUtility::getTCAtypeValue()}):
     * "0", or "1" where no "0" is declared.
     *
     * @param array<array-key, mixed> $fields
     *
     * @return array{name:string, shown:list<string>}|string
     */
    private function recordType(string $table, array $fields): array|string
    {
        $tca   = $this->tcaFor($table) ?? [];
        $types = is_array($tca['types'] ?? null) ? $tca['types'] : [];
        $ctrl  = $this->tcaCtrlFor($table) ?? [];

        $candidates = [];
        $typeField  = $ctrl['type'] ?? null;
        if (is_string($typeField) && $typeField !== '') {
            $column  = $this->tcaColumnsFor($table)[$typeField] ?? null;
            $config  = is_array($column) ? ($column['config'] ?? null) : null;
            $default = is_array($config) ? self::toStr($config['default'] ?? '') : '';
            if (array_key_exists($typeField, $fields)) {
                $candidates[] = self::toStr($fields[$typeField]);
            }

            $candidates[] = $default;
        }

        $candidates[] = '0';
        $candidates[] = '1';

        foreach ($candidates as $name) {
            $type = $types[$name] ?? null;
            if (is_array($type)) {
                return ['name' => $name, 'shown' => $this->columnsShown($table, self::toStr($type['showitem'] ?? ''))];
            }
        }

        return sprintf('Refused: %s declares no record type this tool can read.', $table);
    }

    /**
     * The column names a showitem string lists, `--palette--` entries expanded
     * through the table's palettes and `--div--` / `--linebreak--` skipped.
     *
     * @return list<string>
     */
    private function columnsShown(string $table, string $showitem): array
    {
        $tca      = $this->tcaFor($table) ?? [];
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];

        $shown = [];
        foreach (GeneralUtility::trimExplode(',', $showitem, true) as $item) {
            $parts = GeneralUtility::trimExplode(';', $item);
            $name  = $parts[0] ?? '';
            if ($name === '--palette--') {
                $palette = $palettes[$parts[2] ?? ''] ?? null;
                $inner   = is_array($palette) ? self::toStr($palette['showitem'] ?? '') : '';
                foreach (GeneralUtility::trimExplode(',', $inner, true) as $paletteItem) {
                    $field = GeneralUtility::trimExplode(';', $paletteItem)[0] ?? '';
                    if ($field !== '' && !str_starts_with($field, '--')) {
                        $shown[] = $field;
                    }
                }

                continue;
            }

            if ($name !== '' && !str_starts_with($name, '--')) {
                $shown[] = $name;
            }
        }

        return array_values(array_unique($shown));
    }

    /**
     * Every field of the call, checked by column and by type — or the refusal
     * for the first one that does not hold.
     *
     * `values` is what the DataHandler receives (a datetime as its timestamp),
     * `display` what the approver reads (the value as given), `labels` the
     * column labels in the viewer's language.
     *
     * @param array{name:string, shown:list<string>} $type
     * @param array<array-key, mixed>                $fields
     *
     * @return array{values:array<string, int|float|string>, display:array<string, string>, labels:array<string, string>}|string
     */
    private function collectValues(string $table, array $type, array $fields): array|string
    {
        $denied = $this->deniedColumnsOf($table);

        $values  = [];
        $display = [];
        $labels  = [];
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
            $labels[$column]  = $this->labelOf($table, $column);
        }

        return ['values' => $values, 'display' => $display, 'labels' => $labels];
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
     * The first `eval` token the DataHandler would apply to a value of this
     * column other than `trim`, or null where there is none.
     *
     * `trim` is the one evaluation the tool applies itself. Every other token
     * of an `input` rewrites the value (`upper`, `lower`, `nospace`, `alpha`,
     * `num`, `alphanum`, `alphanum_x`, `is_in`, `domainname`), drops it
     * (`md5`), makes it unique (`unique`, `uniqueInPid`) or hands it to an
     * extension's evaluation class ({@see DataHandler::checkValue_input_Eval()});
     * a `text` knows only `trim` and such classes; an `email` only the two
     * uniqueness tokens. The read-back would find the stored value differs and
     * delete a record the DataHandler merely normalised, so the column is
     * refused before the write.
     *
     * @param array<array-key, mixed> $config
     */
    private function rewritingEvaluation(string $kind, array $config): ?string
    {
        if (!in_array($kind, ['input', 'text', 'email'], true)) {
            return null;
        }

        foreach (GeneralUtility::trimExplode(',', self::toStr($config['eval'] ?? ''), true) as $token) {
            if ($token === 'trim') {
                continue;
            }

            if ($kind !== 'email' || in_array($token, ['unique', 'uniqueInPid'], true)) {
                return $token;
            }
        }

        return null;
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
     * @param array<array-key, mixed> $config
     *
     * @return list{int|float, string}|string
     */
    private function checkNumber(string $column, array $config, mixed $value): array|string
    {
        if (self::toStr($config['format'] ?? 'integer') === 'decimal') {
            if (!is_int($value) && !is_float($value) && (!is_string($value) || !is_numeric($value))) {
                return sprintf('Refused: the value for "%s" must be a number.', $column);
            }

            $number = (float)$value;
        } else {
            $number = match (true) {
                is_int($value)                                                  => $value,
                is_float($value) && floor($value) === $value                    => (int)$value,
                is_string($value) && preg_match('/^-?\d+$/', $value) === 1      => (int)$value,
                default                                                         => null,
            };
            if ($number === null) {
                return sprintf('Refused: the value for "%s" must be an integer.', $column);
            }
        }

        // As the DataHandler compares it (checkValueForNumber()): rounded up
        // against the upper bound and down against the lower one, so a decimal
        // inside the range can still be clamped to a bound. For an integer the
        // rounding changes nothing.
        $range = is_array($config['range'] ?? null) ? $config['range'] : [];
        $lower = is_numeric($range['lower'] ?? null) ? (float)$range['lower'] : null;
        $upper = is_numeric($range['upper'] ?? null) ? (float)$range['upper'] : null;
        if (($lower !== null && floor($number) < $lower) || ($upper !== null && ceil($number) > $upper)) {
            return sprintf(
                'Refused: the value for "%s" is outside the range %s..%s%s.',
                $column,
                self::toStr($range['lower'] ?? ''),
                self::toStr($range['upper'] ?? ''),
                is_float($number) ? ' as TYPO3 checks a decimal — rounded up against the upper bound, down against the lower one' : '',
            );
        }

        return [$number, self::toStr($number)];
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
                $timestamp = (new DateTimeImmutable($text))->getTimestamp();
            } catch (Exception) {
                return $refusal;
            }
        } else {
            return $refusal;
        }

        $range = is_array($config['range'] ?? null) ? $config['range'] : [];
        $lower = is_numeric($range['lower'] ?? null) ? (int)$range['lower'] : null;
        $upper = is_numeric($range['upper'] ?? null) ? (int)$range['upper'] : null;
        if (($lower !== null && $timestamp < $lower) || ($upper !== null && $timestamp > $upper)) {
            return sprintf('Refused: the value for "%s" is outside the range the TCA allows.', $column);
        }

        return [$timestamp, $text ?? (string)$timestamp];
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
     * @param array{name:string, shown:list<string>} $type
     * @param array<string, int|float|string>        $values
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
     * Compared by type: numbers and timestamps as numbers, everything else as
     * the string the DataHandler stores. A rich-text column is the exception —
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
                    => abs(self::toFloat($storedValue) - self::toFloat($requested)) < 0.005,
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
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
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
     * The column's TCA label in the viewer's language, the column name where
     * the TCA declares none.
     */
    private function labelOf(string $table, string $column): string
    {
        $definition = $this->tcaColumnsFor($table)[$column] ?? null;
        $label      = is_array($definition) ? $this->resolveLabel(self::toStr($definition['label'] ?? '')) : '';

        return $label !== '' ? rtrim($label, ':') : $column;
    }

    private function tableLabelOf(string $table): string
    {
        $ctrl  = $this->tcaCtrlFor($table) ?? [];
        $label = $this->resolveLabel(self::toStr($ctrl['title'] ?? ''));

        return $label !== '' ? $label : $table;
    }

    /**
     * A column's TCA `config` for the given record type, or null where the
     * table has no such column.
     *
     * The base configuration with the type's `columnsOverrides` merged over
     * it, the way core builds the field of a record type's sub-schema
     * ({@see \TYPO3\CMS\Core\Schema\TcaSchemaBuilder}, `array_replace_recursive`) —
     * the configuration the DataHandler validates a value against
     * (`DataHandler::resolveFieldConfigurationAndRespectColumnsOverrides()`).
     * A `required`, a `max`, the items of a select or `enableRichtext` may
     * exist only for one type.
     *
     * @return array<array-key, mixed>|null
     */
    private function columnConfig(string $table, string $column, string $recordType): ?array
    {
        $definition = $this->tcaColumnsFor($table)[$column] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        $types     = $this->tcaFor($table)['types'] ?? null;
        $type      = is_array($types) ? ($types[$recordType] ?? null) : null;
        $overrides = is_array($type) ? ($type['columnsOverrides'] ?? null) : null;
        $override  = is_array($overrides) ? ($overrides[$column] ?? null) : null;
        if (is_array($override)) {
            $definition = array_replace_recursive($definition, $override);
        }

        $config = $definition['config'] ?? null;

        return is_array($config) ? $config : null;
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
