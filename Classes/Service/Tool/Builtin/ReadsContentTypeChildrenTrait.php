<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * The column kinds ADR-324 adds to the content-type rule of ADR-196: inline
 * child records a draft may carry (`children`) and a FlexForm written with
 * its data structure's defaults (`defaults`), and the checks and datamap
 * rows for the children of one element.
 *
 * Used by {@see ReadsContentTypeFormsTrait}, so every consumer of that trait
 * applies the same rule. The consuming class must provide what that trait
 * needs, plus `columnsTheUserMayNotSet()` (via
 * {@see WritesThroughDataHandlerTrait}) where it calls
 * {@see self::childrenPlan()}.
 */
trait ReadsContentTypeChildrenTrait
{
    /** Child tables a draft never writes, whatever the relation says. */
    private const DENIED_CHILD_TABLES = ['tt_content', 'pages', 'sys_file_reference'];

    /** Table prefixes a draft never writes as children: core's system and backend tables. */
    private const DENIED_CHILD_TABLE_PREFIXES = ['sys_', 'be_'];

    /** The most child records one inline column takes in one draft, whatever its `maxitems`. */
    private const MAX_CHILDREN = 50;

    /**
     * What one column of a type's form means for the type: the kinds of
     * {@see ReadsContentTypeFormsTrait::columnKind()}, plus `children` for an
     * inline column with a plain one-to-many relation and `defaults` for a
     * FlexForm whose data structure resolves for the type (ADR-324). Every
     * other inline or FlexForm column excludes the type.
     *
     * @param array<array-key, mixed> $config
     *
     * @return 'fillable'|'unfilled'|'children'|'defaults'|'excluding'
     */
    private function columnKindIn(string $type, string $column, array $config): string
    {
        $tcaType = self::toStr($config['type'] ?? '');
        if ($tcaType === 'inline') {
            return $this->childCollectionOf($config) !== null ? 'children' : 'excluding';
        }

        if ($tcaType === 'flex') {
            return $this->flexDefaultsOf($type, $column, $config) !== null ? 'defaults' : 'excluding';
        }

        return $this->columnKind($config);
    }

    /**
     * Whether the TCA requires a value in the column: `required`, or a
     * `minitems` of one or more.
     *
     * @param array<array-key, mixed> $config
     */
    private function isRequiredColumn(array $config): bool
    {
        $minItems = $config['minitems'] ?? 0;

        return (bool) ($config['required'] ?? false) || (is_numeric($minItems) && (int) $minItems > 0);
    }

    /**
     * What an inline column's children are, or null when the column is not a
     * plain one-to-many relation a draft may fill (ADR-324).
     *
     * Plain means: `foreign_table` and `foreign_field`, no MM table, no
     * `foreign_table_field` or `foreign_match_fields`, a child table with
     * TCA and a form that is not on the deny-list. The child's form is read
     * in the type its type field defaults to; its fillable columns are what a
     * child may carry, its system columns never. A required child column that
     * is not fillable, or a child with nothing fillable, makes the relation
     * one a draft cannot fill.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int}|null
     */
    private function childCollectionOf(array $config): ?array
    {
        $table        = self::toStr($config['foreign_table'] ?? '');
        $foreignField = self::toStr($config['foreign_field'] ?? '');
        if (self::toStr($config['type'] ?? '') !== 'inline' || $table === '' || $foreignField === ''
            || self::toStr($config['MM'] ?? '') !== '' || self::toStr($config['foreign_table_field'] ?? '') !== ''
            || ($config['foreign_match_fields'] ?? []) !== [] || $this->isDeniedChildTable($table)
        ) {
            return null;
        }

        $tca      = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) && is_array($tca[$table] ?? null) ? $tca[$table] : null;
        $ctrl     = is_array($tableTca) && is_array($tableTca['ctrl'] ?? null) ? $tableTca['ctrl'] : null;
        if ($ctrl === null) {
            return null;
        }

        $typeField = self::toStr($ctrl['type'] ?? '');
        if (str_contains($typeField, ':')) {
            return null;
        }

        $typeValue = $typeField === '' ? '' : $this->defaultValueOf($table, $typeField);
        $form      = $this->formColumnsOf($table, $this->recordTypeOf($table, $typeField === '' ? [] : [$typeField => $typeValue]));
        $system    = $this->childSystemColumns($ctrl, $foreignField, $typeField);

        $fields = [];
        foreach ($form as $name => $columnConfig) {
            if (in_array($name, $system, true) || str_starts_with($name, self::SYSTEM_COLUMN_PREFIX)) {
                continue;
            }

            if ($this->columnKind($columnConfig) === 'fillable') {
                $fields[$name] = $columnConfig;
            } elseif ($this->isRequiredColumn($columnConfig)) {
                return null;
            }
        }

        if ($fields === []) {
            return null;
        }

        $maxItems = $config['maxitems'] ?? 0;
        $minItems = $config['minitems'] ?? 0;

        return [
            'table'         => $table,
            'foreignField'  => $foreignField,
            'typeField'     => $typeField,
            'typeValue'     => $typeValue,
            'languageField' => self::toStr($ctrl['languageField'] ?? ''),
            'fields'        => $fields,
            'minItems'      => is_numeric($minItems) ? max(0, (int) $minItems) : 0,
            'maxItems'      => min(self::MAX_CHILDREN, is_numeric($maxItems) && (int) $maxItems > 0 ? (int) $maxItems : self::MAX_CHILDREN),
        ];
    }

    private function isDeniedChildTable(string $table): bool
    {
        // ADR-197's bounds for a generic creator, minus `hideTable`: item
        // tables are hidden from the list module because they are edited
        // inside their parent, which is exactly how a draft writes them.
        if (in_array($table, self::DENIED_CHILD_TABLES, true) || (new TableReadAccessService())->isSensitiveTable($table)) {
            return true;
        }

        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['ctrl'] ?? null) ? $tca[$table]['ctrl'] : [];
        if ((bool) ($ctrl['adminOnly'] ?? false) || (bool) ($ctrl['readOnly'] ?? false)) {
            return true;
        }

        foreach (self::DENIED_CHILD_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The columns of a child table a draft never sets: identity, the
     * relation back to the parent, the type field, language and translation,
     * visibility and publication — read from the child table's own `ctrl`,
     * not by the `tt_content` names (ADR-324).
     *
     * @param array<array-key, mixed> $ctrl
     *
     * @return list<string>
     */
    private function childSystemColumns(array $ctrl, string $foreignField, string $typeField): array
    {
        $columns = ['uid', 'pid', $foreignField];
        if ($typeField !== '') {
            $columns[] = $typeField;
        }

        foreach (['languageField', 'transOrigPointerField', 'transOrigDiffSourceField', 'translationSource', 'tstamp', 'crdate', 'delete', 'sortby', 'editlock'] as $key) {
            $name = self::toStr($ctrl[$key] ?? '');
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        $enableColumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        foreach ($enableColumns as $name) {
            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * The value a new row gets in a column when none is given: the TCA
     * `default`, else the first static item of a select — what FormEngine
     * preselects.
     */
    private function defaultValueOf(string $table, string $column): string
    {
        $definition = ($this->tcaColumnsFor($table) ?? [])[$column] ?? null;
        $config     = is_array($definition) && is_array($definition['config'] ?? null) ? $definition['config'] : [];
        if (array_key_exists('default', $config) && (is_string($config['default']) || is_int($config['default']))) {
            return (string) $config['default'];
        }

        return $this->staticItemValues($config)[0] ?? '';
    }

    /**
     * The child collections of one type's form, keyed by inline column: the
     * type's own columns, not those of the form every type shares.
     *
     * @return array<non-empty-string, array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int}>
     */
    private function childCollectionsOfType(string $type): array
    {
        $shared      = $this->sharedFormColumns();
        $collections = [];
        foreach ($this->columnsOfType($type) as $name => $config) {
            if ($this->isSystemColumn($name) || isset($shared[$name])) {
                continue;
            }

            $collection = $this->childCollectionOf($config);
            if ($collection !== null) {
                $collections[$name] = $collection;
            }
        }

        return $collections;
    }

    /**
     * The defaults of a FlexForm column, in the shape the DataHandler takes
     * for a flex value (`data.<sheet>.lDEF.<field>.vDEF`): `[]` when the
     * data structure declares none, null when it does not resolve for the
     * type or a field is required and has no default (ADR-324).
     *
     * The data structure is resolved as {@see GetFlexFormSchemaTool} resolves
     * it, with the row carrying the type: 14.3 takes the table's TCA as an
     * argument, 13.4 reads the global.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    private function flexDefaultsOf(string $type, string $column, array $config): ?array
    {
        $tca      = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? $tca[self::TABLE] : null;
        if ($tableTca === null) {
            return null;
        }

        $columnTca = ['config' => $config];
        $row       = ['uid' => 0, 'pid' => 0, 'CType' => $type, 'list_type' => ''];

        try {
            $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
            if ((new Typo3Version())->getMajorVersion() >= 14) {
                $identifier = $flexFormTools->getDataStructureIdentifier($columnTca, self::TABLE, $column, $row, $tableTca);
                $structure  = $flexFormTools->parseDataStructureByIdentifier($identifier, $tableTca);
            } else {
                $identifier = $flexFormTools->getDataStructureIdentifier($columnTca, self::TABLE, $column, $row);
                $structure  = $flexFormTools->parseDataStructureByIdentifier($identifier);
            }
        } catch (Throwable) {
            return null;
        }

        return $this->defaultsOfDataStructure($structure);
    }

    /**
     * The defaults of a resolved data structure, or null when one of its
     * fields is required and has no default. Sections (repeatable containers)
     * have no default and are left empty.
     *
     * @param array<array-key, mixed> $structure
     *
     * @return array<string, mixed>|null
     */
    private function defaultsOfDataStructure(array $structure): ?array
    {
        $data   = [];
        $sheets = is_array($structure['sheets'] ?? null) ? $structure['sheets'] : [];
        foreach ($sheets as $sheetName => $sheet) {
            $root     = is_array($sheet) && is_array($sheet['ROOT'] ?? null) ? $sheet['ROOT'] : [];
            $elements = is_array($root['el'] ?? null) ? $root['el'] : [];
            foreach ($elements as $fieldName => $element) {
                if (!is_array($element) || self::toStr($element['type'] ?? '') === 'array') {
                    continue;
                }

                $fieldConfig = is_array($element['config'] ?? null) ? $element['config'] : [];
                $default     = $fieldConfig['default'] ?? null;
                if (is_string($default) || is_int($default) || is_float($default)) {
                    $data[(string) $sheetName]['lDEF'][(string) $fieldName]['vDEF'] = (string) $default;
                } elseif ($this->isRequiredColumn($fieldConfig)) {
                    return null;
                }
            }
        }

        return $data === [] ? [] : ['data' => $data];
    }

    /**
     * The defaults of every FlexForm column of the type's own form, keyed by
     * column — what a new element of the type is written with (ADR-324). The
     * form every type shares is left as it was.
     *
     * @return array<non-empty-string, array<string, mixed>>
     */
    private function flexDefaultsOfType(string $type): array
    {
        $shared   = $this->sharedFormColumns();
        $defaults = [];
        foreach ($this->columnsOfType($type) as $name => $config) {
            if (isset($shared[$name]) || self::toStr($config['type'] ?? '') !== 'flex') {
                continue;
            }

            $values = $this->flexDefaultsOf($type, $name, $config);
            if ($values !== null && $values !== []) {
                $defaults[$name] = $values;
            }
        }

        return $defaults;
    }

    /**
     * The refusal for a `link` value outside the schemes a draft may set, or
     * outside the column's `allowedTypes`, or null (ADR-324). The
     * DataHandler empties a link of a type the column does not allow without
     * an error, so the refusal comes first.
     *
     * @param array<array-key, mixed> $config
     */
    private function linkFormatRefusal(string $column, string $text, array $config): ?string
    {
        if ($text === '') {
            return null;
        }

        $linkType = match (true) {
            preg_match('~^https?://~i', $text) === 1 && filter_var($text, FILTER_VALIDATE_URL) !== false => 'url',
            preg_match('~^mailto:[^@\s]+@[^@\s]+\.[^@\s]+$~i', $text) === 1                              => 'email',
            preg_match('~^tel:\+?[0-9][0-9 ()./-]*$~i', $text) === 1                                     => 'telephone',
            preg_match('~^t3://page\?uid=[1-9][0-9]*$~', $text) === 1                                    => 'page',
            default                                                                                      => null,
        };
        if ($linkType === null) {
            return sprintf(
                'Refused: the value for "%s" must be an http or https URL, a mailto: or tel: link, or a page link '
                . 't3://page?uid=<uid>.',
                $column,
            );
        }

        $allowed = $config['allowedTypes'] ?? null;
        if (is_array($allowed) && ($allowed[0] ?? '') !== '*' && !in_array($linkType, $allowed, true)) {
            return sprintf(
                'Refused: "%s" takes only links of the types %s.',
                $column,
                implode(', ', array_map(static fn (mixed $type): string => self::toStr($type), $allowed)),
            );
        }

        return null;
    }

    /**
     * The validated children of a call — per inline column its table and the
     * rows in the order given — or the refusal (ADR-324). Empty when the
     * argument is absent.
     *
     * The whole call is refused on the first wrong key, value, count or
     * missing grant, before anything is written: unknown inline columns and
     * child columns are answered with the ones the type offers.
     *
     * @return array<non-empty-string, array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, rows: list<array<non-empty-string, string|int>>}>|string
     */
    private function childrenPlan(string $type, mixed $raw, int $language, BackendUserAuthentication $user): array|string
    {
        $collections = $this->childCollectionsOfType($type);
        $raw ??= [];

        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return 'Refused: "children" must be an object of child-record columns to lists of records.';
        }

        $plan = [];
        foreach ($raw as $key => $list) {
            $column = preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '';
            if ($column !== $key || $column === '' || !isset($collections[$column])) {
                return sprintf(
                    'Refused: "%s" is not a child-record column of content type "%s". Child-record columns: %s.',
                    $column,
                    $type,
                    $collections === [] ? 'none' : implode(', ', array_keys($collections)),
                );
            }

            $rows = $this->childRows($column, $collections[$column], $list, $user);
            if (is_string($rows)) {
                return $rows;
            }

            $plan[$column] = [
                'table'         => $collections[$column]['table'],
                'foreignField'  => $collections[$column]['foreignField'],
                'typeField'     => $collections[$column]['typeField'],
                'typeValue'     => $collections[$column]['typeValue'],
                'languageField' => $collections[$column]['languageField'],
                'fields'        => $collections[$column]['fields'],
                'rows'          => $rows,
            ];
        }

        foreach ($collections as $column => $collection) {
            if ($collection['minItems'] > count($plan[$column]['rows'] ?? [])) {
                return sprintf(
                    'Refused: content type "%s" needs at least %d record(s) in "children.%s".',
                    $type,
                    $collection['minItems'],
                    $column,
                );
            }
        }

        return $this->childrenGrantRefusal($plan, $language, $user) ?? $plan;
    }

    /**
     * The validated rows of one inline column, or the refusal.
     *
     * @param array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int} $collection
     *
     * @return list<array<non-empty-string, string|int>>|string
     */
    private function childRows(string $column, array $collection, mixed $list, BackendUserAuthentication $user): array|string
    {
        if (!is_array($list) || !array_is_list($list)) {
            return sprintf('Refused: "children.%s" must be a list of records.', $column);
        }

        if (count($list) > $collection['maxItems']) {
            return sprintf('Refused: "children.%s" takes at most %d records.', $column, $collection['maxItems']);
        }

        $fields = $collection['fields'];
        $rows   = [];
        foreach ($list as $index => $child) {
            $where = sprintf('children.%s[%d]', $column, $index);
            if (!is_array($child) || ($child !== [] && array_is_list($child))) {
                return sprintf('Refused: "%s" must be an object of column names to values.', $where);
            }

            $row = [];
            foreach ($child as $key => $value) {
                $name = preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '';
                if ($name !== $key || $name === '' || !isset($fields[$name])) {
                    return sprintf(
                        'Refused: "%s" is not a column of the records in "children.%s". Columns: %s.',
                        $name,
                        $column,
                        implode(', ', array_keys($fields)),
                    );
                }

                $checked = $this->fieldValue($name, $value, $fields[$name]);
                if (is_string($checked)) {
                    return $where . ': ' . $checked;
                }

                if (self::toStr($fields[$name]['type'] ?? '') === 'select' && (bool) ($fields[$name]['authMode'] ?? false)
                    && !$user->checkAuthMode($collection['table'], $name, (string) $checked[0])
                ) {
                    return sprintf(
                        '%s: Refused: the acting backend user is not allowed the value "%s" for "%s" (no explicit allow for %s:%s:%s). Nothing was written.',
                        $where,
                        (string) $checked[0],
                        $name,
                        $collection['table'],
                        $name,
                        (string) $checked[0],
                    );
                }

                $row[$name] = $checked[0];
            }

            foreach ($fields as $name => $config) {
                if ($this->isRequiredColumn($config) && !array_key_exists($name, $row)) {
                    return sprintf('Refused: "%s": "%s" is required.', $where, $name);
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The refusal for a child table or column the user may not write, or
     * null — the questions the DataHandler asks and answers in silence or
     * with a refused row (ADR-324): `tables_modify` on the child table, the
     * exclude-field grant of the inline column and of every child column set
     * (the language column too, where the element is not in the default
     * language), and `authMode` on a child table's type field.
     *
     * @param array<non-empty-string, array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, rows: list<array<non-empty-string, string|int>>}> $plan
     */
    private function childrenGrantRefusal(array $plan, int $language, BackendUserAuthentication $user): ?string
    {
        foreach ($plan as $column => $children) {
            $table = $children['table'];
            if (!$user->isAdmin() && !$user->check('tables_modify', $table)) {
                return sprintf(
                    'Refused: the acting backend user may not create records in %s (no "tables_modify" grant), so the '
                    . 'records of "children.%s" cannot be written. Nothing was written.',
                    $table,
                    $column,
                );
            }

            $columns = [];
            foreach ($children['rows'] as $row) {
                $columns = [...$columns, ...array_keys($row)];
            }

            if ($language !== 0 && $children['languageField'] !== '' && $children['rows'] !== []) {
                $columns[] = $children['languageField'];
            }

            $ungranted = [
                ...$this->columnsTheUserMayNotSet($user, self::TABLE, $children['rows'] === [] ? [] : [$column]),
                ...array_map(
                    static fn (string $name): string => $table . ':' . $name,
                    $this->columnsTheUserMayNotSet($user, $table, array_values(array_unique($columns))),
                ),
            ];
            if ($ungranted !== []) {
                return sprintf(
                    'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was '
                    . 'written.',
                    implode(', ', array_map(static fn (string $name): string => str_contains($name, ':') ? $name : self::TABLE . ':' . $name, $ungranted)),
                );
            }

            if ($children['typeField'] !== '' && $children['rows'] !== [] && $this->columnHasAuthMode($table, $children['typeField'])
                && !$user->checkAuthMode($table, $children['typeField'], $children['typeValue'])
            ) {
                return sprintf(
                    'Refused: the acting backend user is not allowed the record type "%s" of %s (no explicit allow for '
                    . '%s:%s:%s), so the records of "children.%s" cannot be written. Nothing was written.',
                    $children['typeValue'],
                    $table,
                    $table,
                    $children['typeField'],
                    $children['typeValue'],
                    $column,
                );
            }
        }

        return null;
    }

    /**
     * The refusal page TSconfig gives values for columns of `$table`, or
     * null — the column rules of ADR-196 (`disabled`, `config.readOnly`,
     * `keepItems`/`removeItems` on a `select` value), read from
     * `TCEFORM.<table>.` of the page and applied to a child table's columns as
     * to the element's own (ADR-324).
     *
     * @param array<array-key, mixed>                   $tceForm the page's `TCEFORM.`
     * @param array<array-key, string|int>              $values  column to value
     * @param array<array-key, array<array-key, mixed>> $columns the form's column configs
     */
    private function columnsTsConfigRefusal(array $tceForm, string $table, string $type, array $values, array $columns, int $pageUid): ?string
    {
        $rules = is_array($tceForm[$table . '.'] ?? null) ? $tceForm[$table . '.'] : [];
        if ($rules === []) {
            return null;
        }

        foreach ($values as $column => $value) {
            $column  = (string) $column;
            $tcaType = self::toStr($columns[$column]['type'] ?? '');
            $rule    = $this->tceFormDisabling($rules, $column, $type, $table)
                ?? $this->tceFormReadOnly($rules, $column, $type, $tcaType, $table);
            if ($rule !== null) {
                return sprintf(
                    'Refused: "%s:%s" is not editable on page [%d] by its page TSconfig (%s). Nothing was written.',
                    $table,
                    $column,
                    $pageUid,
                    $rule,
                );
            }

            $rule = $tcaType === 'select' ? $this->tceFormRuleRemoving($rules, $column, $type, (string) $value, $table) : null;
            if ($rule !== null) {
                return sprintf(
                    'Refused: the value "%s" for "%s:%s" is not offered on page [%d] by its page TSconfig (%s). Nothing '
                    . 'was written.',
                    (string) $value,
                    $table,
                    $column,
                    $pageUid,
                    $rule,
                );
            }
        }

        return null;
    }

    /**
     * The refusal page TSconfig gives the children of a plan, or null.
     *
     * @param array<array-key, mixed>                                                                                                                                                                                                                                         $tceForm the page's `TCEFORM.`
     * @param array<non-empty-string, array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, rows: list<array<non-empty-string, string|int>>}> $plan
     */
    private function childrenTsConfigRefusal(array $tceForm, array $plan, int $pageUid): ?string
    {
        foreach ($plan as $children) {
            $type = $children['typeValue'] !== '' ? $children['typeValue'] : $this->recordTypeOf($children['table'], []);
            foreach ($children['rows'] as $row) {
                $refusal = $this->columnsTsConfigRefusal($tceForm, $children['table'], $type, $row, $children['fields'], $pageUid);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
        }

        return null;
    }

    private function columnHasAuthMode(string $table, string $column): bool
    {
        $definition = ($this->tcaColumnsFor($table) ?? [])[$column] ?? null;
        $config     = is_array($definition) && is_array($definition['config'] ?? null) ? $definition['config'] : [];

        return self::toStr($config['authMode'] ?? '') !== '';
    }

    /**
     * The datamap rows of a children plan for an element on page `$pid`
     * (a positive uid or a `NEW…` placeholder; never an "after" pid): the
     * value of each inline column, its new rows' placeholders in the given
     * order, and the rows per child table. The DataHandler sets each child's
     * `foreign_field` when it resolves the parent's inline list.
     *
     * @param array<non-empty-string, array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, rows: list<array<non-empty-string, string|int>>}> $plan
     *
     * @return array{inline: array<non-empty-string, string>, rows: array<non-empty-string, array<non-empty-string, array<string, string|int>>>, placeholders: array<non-empty-string, list<non-empty-string>>}
     */
    private function childDataMap(array $plan, int|string $pid, int $language): array
    {
        $inline       = [];
        $rows         = [];
        $placeholders = [];
        foreach ($plan as $column => $children) {
            $ids = [];
            foreach ($children['rows'] as $row) {
                $id  = StringUtility::getUniqueId('NEW');
                $new = ['pid' => $pid, ...$row];
                if ($children['typeField'] !== '') {
                    $new[$children['typeField']] = $children['typeValue'];
                }

                if ($language !== 0 && $children['languageField'] !== '') {
                    $new[$children['languageField']] = $language;
                }

                $rows[$children['table']][$id] = $new;
                $ids[]                         = $id;
            }

            $inline[$column]       = implode(',', $ids);
            $placeholders[$column] = $ids;
        }

        return ['inline' => $inline, 'rows' => $rows, 'placeholders' => $placeholders];
    }

    /**
     * One offered content type as a model reads it (ADR-324): its label and
     * description from the `CType` item, its fillable columns, and per inline
     * column the child table and the columns of its records. Labels are
     * translated with `$translate`.
     *
     * @param Closure(string): string $translate
     *
     * @return array{type: string, label: string, description: string, fields: array<string, array<string, mixed>>, children: array<string, array{table: string, maxItems: int, fields: array<string, array<string, mixed>>}>}
     */
    private function describeContentType(string $type, Closure $translate): array
    {
        $item     = $this->cTypeItemOf($type);
        $columns  = $this->columnsOfType($type);
        $shared   = $this->sharedFormColumns();
        $children = [];
        foreach ($this->childCollectionsOfType($type) as $column => $collection) {
            $children[$column] = [
                'table'    => $collection['table'],
                'maxItems' => $collection['maxItems'],
                'fields'   => $this->describeFields($collection['table'], $collection['fields'], $translate),
            ];
        }

        $fillable = [];
        foreach ($columns as $name => $config) {
            if (!$this->isSystemColumn($name) && !isset($shared[$name]) && $this->columnKind($config) === 'fillable') {
                $fillable[$name] = $config;
            }
        }

        return [
            'type'        => $type,
            'label'       => $translate(self::toStr($item['label'] ?? $type)),
            'description' => $translate(self::toStr($item['description'] ?? '')),
            'fields'      => $this->describeFields(self::TABLE, $fillable, $translate),
            'children'    => $children,
        ];
    }

    /**
     * @param array<non-empty-string, array<array-key, mixed>> $fields
     * @param Closure(string): string                          $translate
     *
     * @return array<string, array<string, mixed>>
     */
    private function describeFields(string $table, array $fields, Closure $translate): array
    {
        $described = [];
        foreach ($fields as $name => $config) {
            $definition = ($this->tcaColumnsFor($table) ?? [])[$name] ?? null;
            $entry      = [
                'type'     => self::toStr($config['type'] ?? ''),
                'label'    => $translate(self::toStr(is_array($definition) ? ($definition['label'] ?? $name) : $name)),
                'required' => $this->isRequiredColumn($config),
            ];
            if (is_numeric($config['max'] ?? null)) {
                $entry['max'] = (int) $config['max'];
            }

            $items = $this->staticItemValues($config);
            if ($items !== []) {
                $entry['items'] = $items;
            }

            if ((bool) ($config['enableRichtext'] ?? false)) {
                $entry['richText'] = true;
            }

            $described[$name] = $entry;
        }

        return $described;
    }

    /**
     * The `CType` item of a type, or an empty array.
     *
     * @return array<array-key, mixed>
     */
    private function cTypeItemOf(string $type): array
    {
        $column = $this->tcaColumnsFor(self::TABLE)['CType'] ?? null;
        $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
        foreach (is_array($config['items'] ?? null) ? $config['items'] : [] as $item) {
            if (is_array($item) && ($item['value'] ?? null) === $type) {
                return $item;
            }
        }

        return [];
    }
}
