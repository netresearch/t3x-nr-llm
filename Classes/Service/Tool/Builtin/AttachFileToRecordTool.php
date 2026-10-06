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
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * References an EXISTING managed file from a file field of ONE record in a
 * table that has no dedicated attach tool — the news image being the case that
 * motivated it (NEXT-199): `tx_news_domain_model_news.fal_media`.
 *
 * The sibling of {@see AttachFileToContentElementTool} on the same terms
 * (ADR-135/146/180): one `sys_file_reference` row through the DataHandler as
 * the acting backend user, in the live workspace, in one datamap whose parent
 * list carries the placeholder, so the record's counter and the reference's
 * position come out right without a second pass. It never uploads, moves or
 * renames a file; the file must already sit in a storage the
 * {@see FalStorageGate} allows and inside the acting user's file mounts, and the
 * record must be on a page the user may edit content on. A HIDDEN record is a
 * valid target — that is the draft an agent has just created.
 *
 * What it refuses, and why it is not a second `create_record_draft`:
 *
 * - **`pages` and `tt_content`** have tools of their own and are refused by
 *   name, so the narrower guarantees of those tools (CType-aware field choice,
 *   the page social image) are never bypassed.
 * - **System and sensitive tables**, and a table the TCA declares `adminOnly`,
 *   `hideTable` or `readOnly`.
 * - **A field that is not a `type => file` column of the table**, and a file
 *   whose extension the field's `allowed` / `disallowed` lists refuse: the
 *   relation the backend form would reject is not written.
 * - **A record outside the default language**: the reference is written in the
 *   default language, as the content tool does.
 *
 * Copyright is not a column of `sys_file_reference`. It belongs to the file's
 * metadata (`sys_file_metadata.copyright`, EXT:filemetadata) and is written by
 * `update_fal_asset_meta`; a news template reads it through the reference's
 * original file. Title, alternative text and description are the reference's
 * own, and override the file's for this one place.
 *
 * Whether the attached image becomes the record's social image is the
 * extension's rule, not this tool's: EXT:news prints the first reference with
 * `showinpreview`, else the first of `fal_media`, as `og:image`.
 *
 * Effect: {@see ToolEffect::NON_IDEMPOTENT_WRITE} — a second call attaches the
 * same file a second time.
 */
final readonly class AttachFileToRecordTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    use WritesThroughDataHandlerTrait;
    use FetchesSysFileRowTrait;

    /**
     * One string for "no such record", "no such file", "not in a permitted
     * storage", "outside your file mounts" and "you may not edit that page", so
     * a refusal never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Record or file not found, or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const REFERENCE_TABLE = 'sys_file_reference';

    private const SYSTEM_TABLE_PREFIX = 'sys_';

    /** Tables with a narrower tool of their own, refused by name. */
    private const TABLES_WITH_A_TOOL = [
        'pages'      => 'set_page_social_image',
        'tt_content' => 'attach_file_to_content_element',
    ];

    private const TABLE_NAME = '/^[a-z0-9_]{1,64}$/';

    private const COLUMN_NAME = '/^[A-Za-z0-9_]{1,64}$/';

    private const LIVE_WORKSPACE = 0;

    private const DEFAULT_LANGUAGE = 0;

    /** Upper bound for each free-text field on the reference. */
    private const MAX_TEXT_LENGTH = 1000;

    /**
     * The `allowed` aliases TCA accepts for a file field and the system
     * setting each stands for.
     */
    private const EXTENSION_ALIASES = [
        'common-image-types' => ['GFX', 'imagefile_ext'],
        'common-text-types'  => ['SYS', 'textfile_ext'],
        'common-media-types' => ['SYS', 'mediafile_ext'],
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'attach_file_to_record',
            'Reference an EXISTING managed file (sys_file) from a file field of ONE record, appending it to that '
            . 'field — for a record in a table that has no dedicated attach tool, such as the image of a news '
            . 'article (tx_news_domain_model_news, field fal_media). The record may be hidden: this is how a draft '
            . 'just created gets its image. Creates a sys_file_reference through the TYPO3 DataHandler as the acting '
            . 'backend user, in the live workspace. It never uploads, moves or renames a file: the file must already '
            . "exist in a permitted storage inside the acting user's file mounts, and the record must be on a page "
            . "the user may edit. The file's extension must be accepted by the field. pages and tt_content are "
            . 'refused: use set_page_social_image and attach_file_to_content_element. Title, alternative text and '
            . 'description belong to this reference only; the copyright is a property of the file itself and is set '
            . 'with update_fal_asset_meta. Calling twice attaches the same file twice.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The TCA table of the record, e.g. tx_news_domain_model_news.',
                    ],
                    'record' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single record to attach to (default language, hidden is fine).',
                    ],
                    'file' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the single existing file to reference.',
                    ],
                    'field' => [
                        'type'        => 'string',
                        'description' => 'The file field to append to, e.g. fal_media. Omit it only when the table has '
                            . 'exactly one file field.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Optional caption for this reference. Describes the file IN THIS PLACE, '
                            . 'not the file itself.',
                    ],
                    'alternative' => [
                        'type'        => 'string',
                        'description' => "Optional alternative text for this reference, overriding the file's own.",
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Optional longer description for this reference.',
                    ],
                ],
                'required' => ['table', 'record', 'file'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment(self::REFERENCE_TABLE)
            ?? $this->refuseOutsideLiveWorkspace($user);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $table      = $plan['table'];
        $recordUid  = self::toInt($plan['record']['uid'] ?? 0);
        $field      = $plan['field'];
        $texts      = $plan['texts'];
        $existing   = $this->existingReferenceUids($table, $recordUid, $field);
        $placeholder = 'NEW' . uniqid('nrllm', true);

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(
            [
                self::REFERENCE_TABLE => [
                    $placeholder => $texts + [
                        'uid_local'        => self::toInt($plan['file']['uid'] ?? 0),
                        'tablenames'       => $table,
                        'uid_foreign'      => $recordUid,
                        'fieldname'        => $field,
                        'pid'              => self::toInt($plan['record']['pid'] ?? 0),
                        // Stated rather than defaulted, as in the content tool:
                        // the DataHandler checks language access against the
                        // record it is handed.
                        'sys_language_uid' => self::DEFAULT_LANGUAGE,
                    ],
                ],
                $table => [
                    $recordUid => [$field => implode(',', [...$existing, $placeholder])],
                ],
            ],
            [],
            $user,
        );
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $newUid = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        if ($newUid < 1) {
            return ToolResult::error('The reference was not created, and the DataHandler reported no error.');
        }

        $mismatch = $this->readBack($table, $recordUid, $field, $newUid, count($existing) + 1, $texts);
        if ($mismatch !== null) {
            $this->discard($table, $newUid, $recordUid, $field, $existing, $user);

            return ToolResult::error($mismatch);
        }

        return ToolResult::text(sprintf(
            'Attached file [%d] "%s" to %s [%d] as %s reference %d of %d.',
            self::toInt($plan['file']['uid'] ?? 0),
            $this->excerpt(self::toStr($plan['file']['name'] ?? '')),
            $table,
            $recordUid,
            $field,
            count($existing) + 1,
            count($existing) + 1,
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $newUid), WriteKind::CREATED);
    }

    /**
     * The before/after this call would produce (ADR-136), authorised exactly
     * like {@see self::execute()} and against the same explicit acting user,
     * down to the neutral refusal string.
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

        $table     = $plan['table'];
        $recordUid = self::toInt($plan['record']['uid'] ?? 0);
        $existing  = count($this->existingReferenceUids($table, $recordUid, $plan['field']));

        $lines = [
            sprintf('%s [%d] on page [%d]', $table, $recordUid, self::toInt($plan['record']['pid'] ?? 0)),
            sprintf('field %s: %d reference(s) → %d, appended last', $plan['field'], $existing, $existing + 1),
            sprintf(
                'file [%d] "%s" (%s)',
                self::toInt($plan['file']['uid'] ?? 0),
                $this->excerpt(self::toStr($plan['file']['name'] ?? '')),
                $this->excerpt(self::toStr($plan['file']['identifier'] ?? '')),
            ),
        ];

        foreach (['title', 'alternative', 'description'] as $name) {
            if (isset($plan['texts'][$name])) {
                $lines[] = sprintf('%s: %s', $name, $this->quoted($plan['texts'][$name]));
            }
        }

        return $lines;
    }

    /**
     * Whether the person LOOKING at the approval card may see this record and
     * file: the same resolution the write uses, run against the viewer.
     *
     * @param array<string, mixed> $arguments
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return !is_string($this->plan($arguments, $viewer));
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: table grant, field grant, page permission and
        // the file mounts are checked against the acting user.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * Everything the write needs, or the refusal text. One method for the
     * write, the preview and the viewer check, so they cannot drift apart.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:non-empty-string, record:array<string, mixed>, file:array<string, mixed>, field:string, texts:array<string, string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments($arguments);
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

        $recordUid = self::toInt($arguments['record'] ?? 0);
        if ($recordUid < 1) {
            return 'Refused: "record" must be the positive uid of exactly one record.';
        }

        $fileUid = self::toInt($arguments['file'] ?? 0);
        if ($fileUid < 1) {
            return 'Refused: "file" must be the positive sys_file uid of exactly one existing file.';
        }

        $texts = $this->collectTexts($arguments);
        if (is_string($texts)) {
            return $texts;
        }

        if (!$user->isAdmin() && !$user->check('tables_modify', $table)) {
            return sprintf('Refused: the acting backend user may not modify %s (no tables_modify grant).', $table);
        }

        $record = $this->fetchRow($table, $recordUid);
        if ($record === null) {
            return self::NOT_PERMITTED;
        }

        $page = $this->fetchRow(self::PAGES_TABLE, self::toInt($record['pid'] ?? 0));
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
        }

        $languageField = $this->languageFieldOf($table);
        if ($languageField !== null && self::toInt($record[$languageField] ?? 0) !== self::DEFAULT_LANGUAGE) {
            return 'Refused: this tool attaches to records in the default language only.';
        }

        $file = $this->fetchFile($fileUid);
        if ($file === null) {
            return self::NOT_PERMITTED;
        }

        // nr_llm's own barrier: the allow-list is this extension's
        // configuration and the DataHandler cannot consult it. It covers the
        // user's file mounts too.
        if (!$this->storageGate->isFileAccessible($user, self::toInt($file['storage'] ?? 0), self::toStr($file['identifier'] ?? ''))) {
            return self::NOT_PERMITTED;
        }

        $field = $this->chooseField($table, $arguments);
        if (is_array($field)) {
            return $field[0];
        }

        $ungranted = $this->columnsTheUserMayNotSet($user, $table, [$field]);
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s:%s. Nothing '
                . 'was written — the DataHandler would drop the relation in silence.',
                $table,
                $field,
            );
        }

        $extension = strtolower(self::toStr($file['extension'] ?? ''));
        $allowed   = $this->extensionList($table, $field, 'allowed', $record);
        if ($allowed !== null && !in_array($extension, $allowed, true)) {
            return sprintf(
                'Refused: field "%s" does not accept a .%s file. It accepts: %s.',
                $field,
                $extension === '' ? '(none)' : $extension,
                implode(', ', $allowed),
            );
        }

        $disallowed = $this->extensionList($table, $field, 'disallowed', $record);
        if ($disallowed !== null && in_array($extension, $disallowed, true)) {
            return sprintf('Refused: field "%s" does not accept a .%s file.', $field, $extension);
        }

        return ['table' => $table, 'record' => $record, 'file' => $file, 'field' => $field, 'texts' => $texts];
    }

    /**
     * The refusal for a table this tool does not serve, or null when it does.
     */
    private function refuseTable(string $table): ?string
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;
        if (!is_array($ctrl) || $this->tcaColumnsFor($table) === null) {
            return sprintf('Refused: "%s" is not a table this installation declares in its TCA.', $table);
        }

        if (isset(self::TABLES_WITH_A_TOOL[$table])) {
            return sprintf('Refused: %s has a tool of its own — use %s.', $table, self::TABLES_WITH_A_TOOL[$table]);
        }

        if (str_starts_with($table, self::SYSTEM_TABLE_PREFIX) || $this->tableAccess->isSensitiveTable($table)) {
            return sprintf('Refused: %s is a system or sensitive table no tool writes.', $table);
        }

        foreach (['adminOnly', 'hideTable', 'readOnly'] as $flag) {
            if ((bool)($ctrl[$flag] ?? false)) {
                return sprintf('Refused: %s is declared %s in its TCA, so this tool does not write it.', $table, $flag);
            }
        }

        return null;
    }

    /**
     * The file field to write, or a one-element array carrying the refusal.
     *
     * A named field must be a `type => file` column of the table; an omitted
     * one is only inferred when the table has exactly one, because picking
     * among several would be this tool inventing an editorial preference.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string|array{string}
     */
    private function chooseField(string $table, array $arguments): string|array
    {
        $offered = $this->fileFieldsOf($table);
        if ($offered === []) {
            return [sprintf('Refused: %s has no file field.', $table)];
        }

        $named = self::toStr($arguments['field'] ?? '');
        if ($named !== '') {
            if (preg_match(self::COLUMN_NAME, $named) !== 1 || !in_array($named, $offered, true)) {
                return [sprintf('Refused: "%s" is not a file field of %s. It offers: %s.', preg_replace('/[^A-Za-z0-9_]/', '', $named), $table, implode(', ', $offered))];
            }

            return $named;
        }

        if (count($offered) > 1) {
            return [sprintf('Refused: %s offers several file fields (%s). Name the one you mean in "field".', $table, implode(', ', $offered))];
        }

        return $offered[0];
    }

    /**
     * @return list<string>
     */
    private function fileFieldsOf(string $table): array
    {
        $offered = [];
        foreach ($this->tcaColumnsFor($table) ?? [] as $name => $column) {
            $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
            if (self::toStr($config['type'] ?? '') === 'file') {
                $offered[] = self::toStr($name);
            }
        }

        return $offered;
    }

    private function languageFieldOf(string $table): ?string
    {
        $tca   = $GLOBALS['TCA'] ?? null;
        $ctrl  = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;
        $field = is_array($ctrl) ? ($ctrl['languageField'] ?? null) : null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * A field's TCA config for one record: the column's own config with the
     * record type's `columnsOverrides` laid over it. A type field in the
     * `field:subfield` form is not resolved, the base config applies.
     *
     * @param array<string, mixed> $record
     *
     * @return array<mixed>
     */
    private function fieldConfig(string $table, string $field, array $record): array
    {
        $column = ($this->tcaColumnsFor($table) ?? [])[$field] ?? null;
        $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];

        $tca       = $GLOBALS['TCA'] ?? null;
        $definition = is_array($tca) && is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl      = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
        $types     = is_array($definition['types'] ?? null) ? $definition['types'] : [];
        $typeField = $ctrl['type'] ?? null;
        if (is_string($typeField) && str_contains($typeField, ':')) {
            return $config;
        }

        // A table without a type field has the single type "1".
        $typeValue = is_string($typeField) && $typeField !== '' ? self::toStr($record[$typeField] ?? '') : '1';
        $type      = is_array($types[$typeValue] ?? null) ? $types[$typeValue] : (is_array($types['1'] ?? null) ? $types['1'] : []);
        $overrides = is_array($type['columnsOverrides'] ?? null) ? $type['columnsOverrides'] : [];
        $override  = is_array($overrides[$field] ?? null) && is_array($overrides[$field]['config'] ?? null) ? $overrides[$field]['config'] : [];

        return array_replace($config, $override);
    }

    /**
     * The extensions a file field's `allowed` or `disallowed` setting names,
     * lowercased, with the three TCA aliases resolved to the system lists —
     * or null when the field sets none (anything goes / nothing is barred).
     *
     * The record's type may narrow the setting through
     * `types.<type>.columnsOverrides`, as the backend form honours it.
     *
     * @param array<string, mixed> $record
     *
     * @return list<string>|null
     */
    private function extensionList(string $table, string $field, string $setting, array $record): ?array
    {
        $config = $this->fieldConfig($table, $field, $record);
        $raw    = $config[$setting] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $tokens = is_array($raw) ? array_map(self::toStr(...), $raw) : explode(',', self::toStr($raw));
        $list   = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim($token));
            if ($token === '') {
                continue;
            }

            if (isset(self::EXTENSION_ALIASES[$token])) {
                [$section, $key] = self::EXTENSION_ALIASES[$token];
                $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
                $group    = is_array($confVars) && is_array($confVars[$section] ?? null) ? $confVars[$section] : [];
                foreach (explode(',', strtolower(self::toStr($group[$key] ?? ''))) as $extension) {
                    if (trim($extension) !== '') {
                        $list[] = trim($extension);
                    }
                }

                continue;
            }

            $list[] = $token;
        }

        return $list === [] ? null : array_values(array_unique($list));
    }

    /**
     * Refuse an argument the tool does not know: the whole call, because
     * applying the known half of a call the model got wrong attaches a file
     * nobody asked for.
     *
     * @param array<string, mixed> $arguments
     */
    private function refuseUnknownArguments(array $arguments): ?string
    {
        $known = ['table', 'record', 'file', 'field', 'title', 'alternative', 'description'];
        foreach (array_keys($arguments) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            return sprintf(
                'Refused: "%s" is not an argument of this tool. It attaches one existing file to one record; allowed: %s.',
                preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                implode(', ', $known),
            );
        }

        return null;
    }

    /**
     * The optional free-text fields, or the refusal for one that is too long.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>|string
     */
    private function collectTexts(array $arguments): array|string
    {
        $texts = [];
        foreach (['title', 'alternative', 'description'] as $name) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $value = self::toStr($arguments[$name]);
            if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
                return sprintf('Refused: "%s" is longer than %d characters.', $name, self::MAX_TEXT_LENGTH);
            }

            $texts[$name] = $value;
        }

        return $texts;
    }

    /**
     * The live references already on this record's field, in their stored
     * order — the list the parent field has to keep.
     *
     * @return list<string>
     */
    private function existingReferenceUids(string $table, int $recordUid, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(self::LIVE_WORKSPACE, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): string => (string)self::toInt($row['uid'] ?? 0), $rows);
    }

    /**
     * What the write got wrong, or null when it landed as planned. An empty
     * `errorLog` is not proof: `title`, `alternative` and `description` are
     * `exclude` fields on `sys_file_reference` and are dropped in silence for
     * a user without the grant, and the relation itself (row, counter,
     * position) is asserted for the same reason.
     *
     * @param array<string, string> $texts
     */
    private function readBack(string $table, int $recordUid, string $field, int $referenceUid, int $expected, array $texts): ?string
    {
        $row = $this->fetchRow(self::REFERENCE_TABLE, $referenceUid);
        if ($row === null
            || self::toInt($row['uid_foreign'] ?? 0) !== $recordUid
            || self::toStr($row['tablenames'] ?? '') !== $table
            || self::toStr($row['fieldname'] ?? '') !== $field
        ) {
            return 'The reference was not stored against the named record and field.';
        }

        $record  = $this->fetchRow($table, $recordUid);
        $counter = self::toInt($record[$field] ?? 0);
        if ($counter !== $expected) {
            return sprintf(
                'The record now counts %d reference(s) in "%s" where %d were expected, so the relation is inconsistent.',
                $counter,
                $field,
                $expected,
            );
        }

        $live = $this->existingReferenceUids($table, $recordUid, $field);
        if ($live === [] || (int)$live[count($live) - 1] !== $referenceUid) {
            return 'The reference was not appended last.';
        }

        foreach ($texts as $name => $value) {
            if (self::toStr($row[$name] ?? '') === $value) {
                continue;
            }

            return sprintf(
                'The reference was created but "%s" did not take. The acting backend user is most likely '
                . 'missing the field-level ("exclude field") grant for %s:%s.',
                $name,
                self::REFERENCE_TABLE,
                $name,
            );
        }

        return null;
    }

    /**
     * Removes a reference whose read-back failed, and writes the record's
     * counter back to the list it had before the call, so a refused call
     * leaves the record as it found it.
     *
     * @param list<string> $survivors the reference uids the record had before this call
     */
    private function discard(string $table, int $referenceUid, int $recordUid, string $field, array $survivors, BackendUserAuthentication $user): void
    {
        $removal = GeneralUtility::makeInstance(ToolDataHandler::class);
        $removal->start([], [self::REFERENCE_TABLE => [$referenceUid => ['delete' => 1]]], $user);
        $removal->process_cmdmap();

        $restore = GeneralUtility::makeInstance(ToolDataHandler::class);
        $restore->start([$table => [$recordUid => [$field => implode(',', $survivors)]]], [], $user);
        $restore->process_datamap();
    }

    /**
     * A live, undeleted row by uid — a hidden one included.
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $table, int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Live rows only: a workspace version row is another
                // workspace's draft (ADR-198).
                ...$this->liveVersionConstraints($queryBuilder, $table),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
