<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Netresearch\NrLlm\Service\Tool\ContentType\ProcessedSelectItems;
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
 * applies the same rule. ADR-326 adds the values of a FlexForm's simple fields
 * and the items an `itemsProcFunc` computes for a child record's select. The
 * consuming class must provide what that trait
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

    /** The TCA types of a FlexForm field a model may set (ADR-326). */
    private const FLEX_FIELD_TYPES = ['input', 'text', 'select', 'check', 'number', 'radio', 'color', 'email', 'link'];

    /** The most names a refusal offers in place of a processed item that does not exist. */
    private const MAX_OFFERED_NAMES = 40;

    /** The most choices of one select for which a later processed select's items are described apart. */
    private const MAX_ITEM_SETS = 50;

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
     * `foreign_table_field`, `foreign_match_fields`, `foreign_selector` or
     * `foreign_unique` (an m:n relation through an intermediate table, whose
     * selector a draft cannot fill), no `symmetric_field`, a `minitems` no
     * larger than the records a draft may write, not `readOnly`, and children deleted
     * with their element (no `behaviour.enableCascadingDelete = false`: a
     * take-back deletes the element only), a child table with
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
            || ($config['foreign_match_fields'] ?? []) !== [] || self::toStr($config['foreign_selector'] ?? '') !== ''
            || self::toStr($config['symmetric_field'] ?? '') !== ''
            || self::toStr($config['foreign_unique'] ?? '') !== '' || (bool) ($config['readOnly'] ?? false)
            || (is_array($config['behaviour'] ?? null) && ($config['behaviour']['enableCascadingDelete'] ?? true) === false)
            || $this->isDeniedChildTable($table)
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
        $system    = $this->childSystemColumns($ctrl, $foreignField, $typeField, self::toStr($config['foreign_sortby'] ?? ''));

        $fields = [];
        foreach ($form as $name => $columnConfig) {
            if (in_array($name, $system, true) || str_starts_with($name, self::SYSTEM_COLUMN_PREFIX)) {
                continue;
            }

            if ($this->columnKind($columnConfig) === 'fillable' || $this->isProcessedSelect($columnConfig)) {
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

        $min = is_numeric($minItems) ? max(0, (int) $minItems) : 0;
        $max = min(self::MAX_CHILDREN, is_numeric($maxItems) && (int) $maxItems > 0 ? (int) $maxItems : self::MAX_CHILDREN);
        if ($min > $max) {
            // No number of records a draft may write satisfies the relation.
            return null;
        }

        return [
            'table'         => $table,
            'foreignField'  => $foreignField,
            'typeField'     => $typeField,
            'typeValue'     => $typeValue,
            'languageField' => self::toStr($ctrl['languageField'] ?? ''),
            'fields'        => $fields,
            'minItems'      => $min,
            'maxItems'      => $max,
        ];
    }

    private function isDeniedChildTable(string $table): bool
    {
        // ADR-197's bounds for a generic creator, with `hideTable` turned
        // round: only a table whose records are reached through their
        // element alone. A draft element is created hidden, and its children
        // are not; a table listed and rendered on its own would show them
        // before anyone approved the element.
        if (in_array($table, self::DENIED_CHILD_TABLES, true) || (new TableReadAccessService())->isSensitiveTable($table)) {
            return true;
        }

        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) && is_array($tca[$table]['ctrl'] ?? null) ? $tca[$table]['ctrl'] : [];
        if ((bool) ($ctrl['adminOnly'] ?? false) || (bool) ($ctrl['readOnly'] ?? false) || !(bool) ($ctrl['hideTable'] ?? false)) {
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
    private function childSystemColumns(array $ctrl, string $foreignField, string $typeField, string $foreignSortby = ''): array
    {
        // The relation's own sort column is the DataHandler's, like ctrl.sortby.
        $columns = array_values(array_filter(['uid', 'pid', $foreignField, $foreignSortby], static fn (string $name): bool => $name !== ''));
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
        $structure = $this->dataStructureOf($type, $column, $config);

        return $structure === null ? null : $this->defaultsOfDataStructure($structure);
    }

    /**
     * The data structure of a FlexForm column for a type, or null when it does
     * not resolve.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>|null
     */
    private function dataStructureOf(string $type, string $column, array $config): ?array
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

        return $structure;
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
    private function flexDefaultsOfType(string $type, ?BackendUserAuthentication $user = null): array
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

        // A FlexForm the user may not set is left to the DataHandler, as the
        // backend form leaves it: it would drop the value without a word, and
        // the read-back would take the element back for it.
        if ($user instanceof BackendUserAuthentication && $defaults !== []) {
            foreach ($this->columnsTheUserMayNotSet($user, self::TABLE, array_keys($defaults)) as $column) {
                unset($defaults[$column]);
            }
        }

        return $defaults;
    }

    /**
     * The FlexForm values a new element of the type is written with: the
     * defaults, and over them the values of the plan (ADR-326).
     *
     * @param array<non-empty-string, array<string, array{sheet: string, label: string, config: array<array-key, mixed>, value: string|int}>> $flexPlan
     *
     * @return array<non-empty-string, array<string, mixed>>
     */
    private function flexValuesOfType(string $type, ?BackendUserAuthentication $user, array $flexPlan): array
    {
        $values = $this->flexDefaultsOfType($type, $user);
        foreach ($flexPlan as $column => $fields) {
            $sheets = $values[$column]['data'] ?? [];
            $sheets = is_array($sheets) ? $sheets : [];
            foreach ($fields as $name => $field) {
                $sheets = array_replace_recursive($sheets, [$field['sheet'] => ['lDEF' => [$name => ['vDEF' => (string) $field['value']]]]]);
            }

            $values[$column] = ['data' => $sheets];
        }

        return $values;
    }

    /**
     * The FlexForm fields a model may set for the type, per FlexForm column of
     * its own form (ADR-326): single scalar fields whose name is unique in the
     * data structure. A field that declares `exclude` is for administrators:
     * core's form asks a grant per field, and the DataHandler asks none.
     *
     * @return array<non-empty-string, array<string, array{sheet: string, label: string, config: array<array-key, mixed>}>>
     */
    private function flexFieldsOfType(string $type, ?BackendUserAuthentication $user = null): array
    {
        $shared = $this->sharedFormColumns();
        $found  = [];
        foreach ($this->columnsOfType($type) as $name => $config) {
            if ($this->isSystemColumn($name) || isset($shared[$name]) || self::toStr($config['type'] ?? '') !== 'flex'
                || (bool) ($config['readOnly'] ?? false)
            ) {
                continue;
            }

            $structure = $this->dataStructureOf($type, $name, $config);
            $fields    = $structure === null ? [] : $this->simpleFieldsOfDataStructure($structure, $user);
            if ($fields !== []) {
                $found[$name] = $fields;
            }
        }

        return $found;
    }

    /**
     * @param array<array-key, mixed> $structure
     *
     * @return array<string, array{sheet: string, label: string, config: array<array-key, mixed>}>
     */
    private function simpleFieldsOfDataStructure(array $structure, ?BackendUserAuthentication $user): array
    {
        $fields = [];
        $seen   = [];
        foreach (is_array($structure['sheets'] ?? null) ? $structure['sheets'] : [] as $sheetName => $sheet) {
            $root = is_array($sheet) && is_array($sheet['ROOT'] ?? null) ? $sheet['ROOT'] : [];
            foreach (is_array($root['el'] ?? null) ? $root['el'] : [] as $name => $element) {
                $name        = (string) $name;
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                if (!is_array($element) || !$this->isSimpleFlexField($element, $user)) {
                    continue;
                }

                $fields[$name] = [
                    'sheet'  => (string) $sheetName,
                    'label'  => trim(self::toStr($element['label'] ?? $name)),
                    'config' => is_array($element['config'] ?? null) ? $element['config'] : [],
                ];
            }
        }

        return array_filter($fields, static fn (string|int $name): bool => $seen[$name] === 1, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<array-key, mixed> $element
     */
    private function isSimpleFlexField(array $element, ?BackendUserAuthentication $user): bool
    {
        $config = is_array($element['config'] ?? null) ? $element['config'] : [];
        $type   = self::toStr($config['type'] ?? '');
        if (self::toStr($element['type'] ?? '') === 'array' || !in_array($type, self::FLEX_FIELD_TYPES, true)
            || (bool) ($config['enableRichtext'] ?? false) || $this->columnKind($config) !== 'fillable'
        ) {
            return false;
        }

        // The items a processor adds are not known here, and a value list is not one value.
        if ($type === 'select' && (self::toStr($config['itemsProcFunc'] ?? '') !== '' || ($config['itemsProcessors'] ?? []) !== []
            || !in_array(self::toStr($config['renderType'] ?? ''), ['', 'selectSingle'], true))
        ) {
            return false;
        }

        return !(bool) ($element['exclude'] ?? false) || ($user instanceof BackendUserAuthentication && $user->isAdmin());
    }

    /**
     * `fields` without the keys of the type's FlexForm columns, and those keys
     * apart: a FlexForm's values come as `{<column>: {<field>: value}}`.
     *
     * @return array{mixed, array<array-key, mixed>}
     */
    private function splitFlexFields(mixed $fields, string $type): array
    {
        if (!is_array($fields)) {
            return [$fields, []];
        }

        $flex   = [];
        $shared = $this->sharedFormColumns();
        foreach ($this->columnsOfType($type) as $name => $config) {
            if (array_key_exists($name, $fields) && self::toStr($config['type'] ?? '') === 'flex' && !isset($shared[$name]) && !$this->isSystemColumn($name)) {
                $flex[$name] = $fields[$name];
                unset($fields[$name]);
            }
        }

        return [$fields, $flex];
    }

    /**
     * The validated FlexForm values of a call, or the refusal (ADR-326).
     *
     * @param array<array-key, mixed> $raw FlexForm column to its fields
     *
     * @return array<non-empty-string, array<string, array{sheet: string, label: string, config: array<array-key, mixed>, value: string|int}>>|string
     */
    private function flexPlan(string $type, array $raw, BackendUserAuthentication $user): array|string
    {
        if ($raw === []) {
            return [];
        }

        $offered = $this->flexFieldsOfType($type, $user);
        $plan    = [];
        foreach ($raw as $key => $values) {
            $column = preg_replace('/[^A-Za-z0-9_]/', '', (string) $key) ?? '';
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                return sprintf('Refused: the value for "%s" must be an object of FlexForm fields to values.', $column);
            }

            foreach ($values as $fieldKey => $value) {
                $name  = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $fieldKey) ?? '';
                $field = $offered[$column][$name] ?? null;
                if ($column === '' || $name !== (string) $fieldKey || $field === null) {
                    return sprintf(
                        'Refused: "%s" is not a field of "%s" this tool sets for content type "%s". Fields: %s.',
                        $name,
                        $column,
                        $type,
                        implode(', ', array_keys($offered[$column] ?? [])) ?: 'none',
                    );
                }

                $checked = $this->fieldValue($column . '.' . $name, $value, $field['config']);
                if (is_string($checked)) {
                    return $checked;
                }

                $plan[$column][$name] = [...$field, 'value' => $checked[0]];
            }
        }

        $ungranted = $this->columnsTheUserMayNotSet($user, self::TABLE, array_keys($plan));
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was written.',
                implode(', ', array_map(static fn (string $column): string => self::TABLE . ':' . $column, $ungranted)),
            );
        }

        return $plan;
    }

    /**
     * The refusal page TSconfig gives the FlexForm values of a plan, or null:
     * the rules of the column, and those under
     * `TCEFORM.tt_content.<column>.<data structure>.<sheet>.<field>.`. The
     * data structure's key is not compared, so a rule for any of them counts.
     *
     * @param array<array-key, mixed>                                                                                                         $tceForm  the page's `TCEFORM.`
     * @param array<non-empty-string, array<string, array{sheet: string, label: string, config: array<array-key, mixed>, value: string|int}>> $flexPlan
     */
    private function flexTsConfigRefusal(array $tceForm, array $flexPlan, string $type, int $pageUid): ?string
    {
        $rules = is_array($tceForm[self::TABLE . '.'] ?? null) ? $tceForm[self::TABLE . '.'] : [];
        foreach ($flexPlan as $column => $fields) {
            $refusal = $this->columnsTsConfigRefusal($tceForm, self::TABLE, $type, [$column => ''], $this->columnsOfType($type), $pageUid);
            if ($refusal !== null) {
                return $refusal;
            }

            foreach (is_array($rules[$column . '.'] ?? null) ? $rules[$column . '.'] : [] as $structure => $sheets) {
                $sheets = is_array($sheets) ? $sheets : [];
                foreach ($fields as $name => $field) {
                    if ($this->flexFieldRuleRefuses($sheets, $name, $field, $type)) {
                        return sprintf(
                            'Refused: "%s" of "%s" does not take the value "%s" on page [%d] by its page TSconfig (TCEFORM.%s.%s.%s%s). Nothing was written.',
                            $name,
                            $column,
                            (string) $field['value'],
                            $pageUid,
                            self::TABLE,
                            $column,
                            $structure,
                            $field['sheet'],
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether the rules of one data structure take the field's value away:
     * its sheet is disabled, or a rule of the field refuses it. A dotted
     * field's rules are read under its name, as core reads the documented
     * `settings\.field`, and nested, as an unescaped dot is parsed.
     *
     * @param array<array-key, mixed>                                                                 $sheets
     * @param array{sheet: string, label: string, config: array<array-key, mixed>, value: string|int} $field
     */
    private function flexFieldRuleRefuses(array $sheets, string $name, array $field, string $type): bool
    {
        $sheetRules = is_array($sheets[$field['sheet'] . '.'] ?? null) ? $sheets[$field['sheet'] . '.'] : [];
        if ((bool) ($sheetRules['disabled'] ?? false)) {
            return true;
        }

        $path   = explode('.', $name);
        $last   = array_pop($path);
        $nested = $sheetRules;
        foreach ($path as $part) {
            $nested = is_array($nested[$part . '.'] ?? null) ? $nested[$part . '.'] : [];
        }

        $tcaType = self::toStr($field['config']['type'] ?? '');
        foreach ([[$sheetRules, $name], [$nested, $last]] as [$rules, $key]) {
            $rule = $this->tceFormDisabling($rules, $key, $type)
                ?? $this->tceFormReadOnly($rules, $key, $type, $tcaType)
                ?? ($tcaType === 'select' ? $this->tceFormRuleRemoving($rules, $key, $type, (string) $field['value']) : null);
            if ($rule !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The FlexForm fields of a plan whose stored value is not the one asked
     * for, as `<column> <field>`.
     *
     * @param array<string, mixed>                                                                                                            $stored
     * @param array<non-empty-string, array<string, array{sheet: string, label: string, config: array<array-key, mixed>, value: string|int}>> $flexPlan
     *
     * @return list<string>
     */
    private function flexValuesThatDidNotTake(array $stored, array $flexPlan): array
    {
        $notTaken = [];
        foreach ($flexPlan as $column => $fields) {
            $parsed = GeneralUtility::xml2array(self::toStr($stored[$column] ?? ''));
            foreach ($fields as $name => $field) {
                $node = $parsed;
                foreach (['data', $field['sheet'], 'lDEF', $name, 'vDEF'] as $step) {
                    $node = is_array($node) ? ($node[$step] ?? null) : null;
                }

                $held  = self::toStr($node);
                $asked = (string) $field['value'];
                $same  = $this->isRewrittenOnPurpose(self::toStr($field['config']['type'] ?? ''), $field['config'])
                    ? ($held === '') === ($asked === '')
                    : $held === $asked;
                if (!$same) {
                    $notTaken[] = $column . ' ' . $name;
                }
            }
        }

        return $notTaken;
    }

    /**
     * Whether a column is a single-value `select` whose items a processor
     * computes (ADR-326). TYPO3 14's `itemsProcessors` are not run; such a
     * column stays empty.
     *
     * @param array<array-key, mixed> $config
     */
    private function isProcessedSelect(array $config): bool
    {
        return self::toStr($config['type'] ?? '') === 'select'
            && self::toStr($config['itemsProcFunc'] ?? '') !== ''
            && ($config['itemsProcessors'] ?? []) === []
            && self::toStr($config['foreign_table'] ?? '') === ''
            && self::toStr($config['renderType'] ?? '') === 'selectSingle'
            && $this->keyRefusal($config) === null;
    }

    /**
     * The items of a child record's processed select, for a record holding
     * `$values`.
     *
     * @param array{table: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>} $collection
     * @param array<string, string|int>                                                                                                                             $values
     * @param array<array-key, mixed>                                                                                                                               $tceForm    the page's `TCEFORM.`
     */
    private function processedItems(array $collection, string $column, array $values, int $pageUid, int $language, array $tceForm, ?BackendUserAuthentication $user): ProcessedSelectItems
    {
        // As the backend form hands the row over: a select holds a list.
        $row = ['uid' => 'NEW', 'pid' => $pageUid];
        foreach ($collection['fields'] as $name => $config) {
            $value      = (string) ($values[$name] ?? $this->defaultValueOf($collection['table'], $name));
            $row[$name] = self::toStr($config['type'] ?? '') === 'select' ? ($value === '' ? [] : [$value]) : $value;
        }

        if ($collection['typeField'] !== '') {
            $row[$collection['typeField']] = [$collection['typeValue']];
        }

        if ($collection['languageField'] !== '') {
            $row[$collection['languageField']] = [$language];
        }

        $rules       = is_array($tceForm[$collection['table'] . '.'] ?? null) ? $tceForm[$collection['table'] . '.'] : [];
        $columnRules = is_array($rules[$column . '.'] ?? null) ? $rules[$column . '.'] : [];

        $items = ProcessedSelectItems::of(
            $collection['table'],
            $column,
            $collection['fields'][$column] ?? [],
            $row,
            $pageUid,
            is_array($columnRules['itemsProcFunc.'] ?? null) ? $columnRules['itemsProcFunc.'] : [],
            $user,
        );

        // What the form would not show the user is no choice and is not named.
        $type     = $collection['typeValue'] !== '' ? $collection['typeValue'] : $this->recordTypeOf($collection['table'], []);
        $authMode = $user instanceof BackendUserAuthentication && $this->columnHasAuthMode($collection['table'], $column);

        return $items->only(
            fn (string $value): bool => $this->tceFormRuleRemoving($rules, $column, $type, $value, $collection['table']) === null
                && (!$authMode || $value === '' || $user->checkAuthMode($collection['table'], $column, $value)),
        );
    }

    /**
     * The value of a processed select in a child record, wrapped, or the
     * refusal. `$values` are the record's other values, the processed ones
     * before this one resolved.
     *
     * @param array{table: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>} $collection
     * @param array<string, string|int>                                                                                                                             $values
     * @param array<array-key, mixed>                                                                                                                               $tceForm
     *
     * @return list{string}|string
     */
    private function processedFieldValue(array $collection, string $column, mixed $value, array $values, int $pageUid, int $language, array $tceForm, BackendUserAuthentication $user): array|string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return sprintf('Refused: the value for "%s" must be a string.', $column);
        }

        $text = trim(self::toStr($value));
        if ($text === '') {
            return $this->isRequiredColumn($collection['fields'][$column] ?? [])
                ? sprintf('Refused: "%s" is required and must not be empty.', $column)
                : [''];
        }

        $items = $this->processedItems($collection, $column, $values, $pageUid, $language, $tceForm, $user);
        if ($items->failed !== '') {
            return sprintf('Refused: the choices for "%s" could not be computed (%s); leave it out.', $column, $items->failed);
        }

        $stored = $items->valueOf($text);
        if ($stored !== null) {
            return [$stored];
        }

        $others = array_keys(array_filter(
            $collection['fields'],
            fn (array $config, string $name): bool => $name !== $column && $this->isProcessedSelect($config),
            ARRAY_FILTER_USE_BOTH,
        ));

        return sprintf(
            'Refused: the value for "%s" must be one of %d choices, for example: %s.%s',
            $column,
            count($items->names()),
            implode(', ', array_map(static fn (string $name): string => '"' . $name . '"', $items->namesNear($text, self::MAX_OFFERED_NAMES))) ?: 'none',
            $others === [] ? '' : sprintf(' The choices can depend on %s of the same record.', implode(', ', $others)),
        );
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
            preg_match('~^tel:\+?[0-9][0-9()./-]*$~i', $text) === 1                                      => 'telephone',
            preg_match('~^t3://page\?uid=[1-9][0-9]*$~', $text) === 1                                    => 'page',
            default                                                                                      => null,
        };
        if ($linkType === null) {
            return sprintf(
                'Refused: the value for "%s" must be an http or https URL, a mailto: or tel: link (no spaces), or a page link '
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
     * @param array<array-key, mixed> $tceForm the `TCEFORM.` of page `$pageUid`, for the processors of child selects
     *
     * @return array<non-empty-string, array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, rows: list<array<non-empty-string, string|int>>}>|string
     */
    private function childrenPlan(string $type, mixed $raw, int $language, BackendUserAuthentication $user, int $pageUid = 0, array $tceForm = []): array|string
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

            // A processor's choices are no answer to a user who may not set the column.
            $refusal = $this->processedGrantRefusal($column, $collections[$column], $list, $language, $user);
            if ($refusal !== null) {
                return $refusal;
            }

            $rows = $this->childRows($column, $collections[$column], $list, $language, $user, $pageUid, $tceForm);
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
     * The grant refusal for records that set a processed select, asked from
     * the columns they name before any items are computed; null otherwise.
     *
     * @param non-empty-string                                                                                                                                                                                                    $column
     * @param array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int} $collection
     */
    private function processedGrantRefusal(string $column, array $collection, mixed $list, int $language, BackendUserAuthentication $user): ?string
    {
        $named = [];
        foreach (is_array($list) ? $list : [] as $child) {
            $named += is_array($child) ? array_intersect_key($collection['fields'], $child) : [];
        }

        if (array_filter($named, $this->isProcessedSelect(...)) === []) {
            return null;
        }

        return $this->childrenGrantRefusal(
            [$column => [
                'table'         => $collection['table'],
                'foreignField'  => $collection['foreignField'],
                'typeField'     => $collection['typeField'],
                'typeValue'     => $collection['typeValue'],
                'languageField' => $collection['languageField'],
                'fields'        => $collection['fields'],
                'rows'          => [array_fill_keys(array_keys($named), '')],
            ]],
            $language,
            $user,
        );
    }

    /**
     * The validated rows of one inline column, or the refusal.
     *
     * @param array{table: non-empty-string, foreignField: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>, minItems: int, maxItems: int} $collection
     * @param array<array-key, mixed>                                                                                                                                                                                             $tceForm
     *
     * @return list<array<non-empty-string, string|int>>|string
     */
    private function childRows(string $column, array $collection, mixed $list, int $language, BackendUserAuthentication $user, int $pageUid, array $tceForm): array|string
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
            // Processed selects last and in the form's order: the items of
            // one can depend on the record's other values.
            $processed = array_filter($fields, $this->isProcessedSelect(...));
            $ordered   = [
                ...array_diff_key($child, $processed),
                ...array_intersect_key(array_replace(array_intersect_key($processed, $child), $child), array_intersect_key($processed, $child)),
            ];
            foreach ($ordered as $key => $value) {
                $name = preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '';
                if ($name !== $key || $name === '' || !isset($fields[$name])) {
                    return sprintf(
                        'Refused: "%s" is not a column of the records in "children.%s". Columns: %s.',
                        $name,
                        $column,
                        implode(', ', array_keys($fields)),
                    );
                }

                $checked = isset($processed[$name])
                    ? $this->processedFieldValue($collection, $name, $value, $row, $pageUid, $language, $tceForm, $user)
                    : $this->fieldValue($name, $value, $fields[$name]);
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

            // The record type the draft writes: without the grant the
            // DataHandler drops it and the user's TCAdefaults pick the type.
            if ($children['typeField'] !== '' && $children['rows'] !== []) {
                $columns[] = $children['typeField'];
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
    private function childrenTsConfigRefusal(array $tceForm, array $plan, int $pageUid, string $elementType = ''): ?string
    {
        // The element's inline columns themselves: a column the page disables
        // or makes read-only takes no children.
        $columns = array_fill_keys(array_keys(array_filter($plan, static fn (array $children): bool => $children['rows'] !== [])), '');
        $refusal = $columns === [] ? null : $this->columnsTsConfigRefusal($tceForm, self::TABLE, $elementType, $columns, $this->columnsOfType($elementType), $pageUid);
        if ($refusal !== null) {
            return $refusal;
        }

        $rules = $tceForm;
        foreach ($plan as $children) {
            $type = $children['typeValue'] !== '' ? $children['typeValue'] : $this->recordTypeOf($children['table'], []);

            // The record type the draft writes, a system column the caller
            // does not pass, under the page's keepItems/removeItems.
            $childRules = is_array($rules[$children['table'] . '.'] ?? null) ? $rules[$children['table'] . '.'] : [];
            $typeRule   = $children['typeField'] !== '' && $children['rows'] !== [] && $childRules !== []
                ? $this->tceFormRuleRemoving($childRules, $children['typeField'], $type, $children['typeValue'], $children['table'])
                : null;
            if ($typeRule !== null) {
                return sprintf(
                    'Refused: the record type "%s" of %s is not offered on page [%d] by its page TSconfig (%s). Nothing was written.',
                    $children['typeValue'],
                    $children['table'],
                    $pageUid,
                    $typeRule,
                );
            }

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

                // Always written, 0 included: TYPO3 13.4 refuses an insert by a
                // non-admin whose row does not name its language
                // (`BackendUserAuthentication::recordEditAccessInternals()`),
                // as the backend form always does.
                if ($children['languageField'] !== '') {
                    $new[$children['languageField']] = $language;
                }

                $rows[$children['table']][$id] = $new;
                $ids[]                         = $id;
            }

            // An empty list writes nothing: the inline column is left as it is.
            if ($ids !== []) {
                $inline[$column] = implode(',', $ids);
            }

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
     * @return array{type: string, label: string, description: string, fields: array<string, array<string, mixed>>, children: array<string, array{table: string, maxItems: int, fields: array<string, array<string, mixed>>}>, flexForms: array<string, array<string, array<string, mixed>>>}
     */
    private function describeContentType(string $type, Closure $translate, ?BackendUserAuthentication $user = null): array
    {
        $item     = $this->cTypeItemOf($type);
        $columns  = $this->columnsOfType($type);
        $shared   = $this->sharedFormColumns();
        $children = [];
        foreach ($this->childCollectionsOfType($type) as $column => $collection) {
            $children[$column] = [
                'table'    => $collection['table'],
                'maxItems' => $collection['maxItems'],
                'fields'   => $this->describeProcessedItems($collection, $this->describeFields($collection['table'], $collection['fields'], $translate), $user),
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
            'flexForms'   => array_map(
                fn (array $fields): array => array_map(fn (array $field): array => $this->describeField($field['config'], $translate($field['label'])), $fields),
                $this->flexFieldsOfType($type, $user),
            ),
        ];
    }

    /**
     * The described fields of a child table with the names of their processed
     * items (ADR-326): `items`, or — where the items differ with an earlier
     * select of the form — `itemsBy: {column, default, items: {<name>: [...]}}`.
     * `itemsPartial` marks a list that may leave choices out. A column whose
     * processor fails is left out: nothing it takes is known.
     *
     * @param array{table: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>} $collection
     * @param array<string, array<string, mixed>>                                                                                                                   $described
     *
     * @return array<string, array<string, mixed>>
     */
    private function describeProcessedItems(array $collection, array $described, ?BackendUserAuthentication $user): array
    {
        $earlier = [];
        foreach ($collection['fields'] as $name => $config) {
            if ($this->isProcessedSelect($config)) {
                $items = $this->processedItems($collection, $name, [], 0, 0, [], $user);
                if ($items->failed !== '') {
                    unset($described[$name]);

                    continue;
                }

                $described[$name] = [...$described[$name], ...$this->describeItemsOf($collection, $name, $items, $earlier, $user)];
                $earlier[$name]   = $items->items;
            } elseif (self::toStr($config['type'] ?? '') === 'select') {
                $earlier[$name] = array_map(static fn (string $value): array => ['value' => $value, 'name' => $value], $this->staticItemValues($config));
            }
        }

        return $described;
    }

    /**
     * @param array{table: non-empty-string, typeField: string, typeValue: string, languageField: string, fields: array<non-empty-string, array<array-key, mixed>>} $collection
     * @param array<string, list<array{value: string, name: string}>>                                                                                               $earlier    the choices of the selects before this one
     *
     * @return array<string, mixed>
     */
    private function describeItemsOf(array $collection, string $column, ProcessedSelectItems $items, array $earlier, ?BackendUserAuthentication $user): array
    {
        $entry = ['items' => $items->names()];
        foreach ($earlier as $before => $choices) {
            if (count($choices) > self::MAX_ITEM_SETS) {
                $entry['itemsPartial'] = true;

                continue;
            }

            $sets = [];
            foreach ($choices as $choice) {
                $set = $this->processedItems($collection, $column, [$before => $choice['value']], 0, 0, [], $user);
                if ($choice['value'] !== '' && $set->failed === '') {
                    $sets[$choice['name']] = $set->names();
                }
            }

            if (array_filter($sets, static fn (array $names): bool => $names !== $items->names()) === []) {
                continue;
            }

            if (isset($entry['itemsBy'])) {
                // Two columns decide; one table cannot list that.
                $entry['itemsPartial'] = true;

                continue;
            }

            unset($entry['items']);
            $default          = $this->defaultValueOf($collection['table'], $before);
            $names            = array_column($choices, 'name', 'value');
            $entry['itemsBy'] = ['column' => $before, 'default' => $default === '' ? '' : ($names[$default] ?? ''), 'items' => $sets];
        }

        return $entry;
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

            $described[$name] = $this->describeField(
                $config,
                $translate(self::toStr(is_array($definition) ? ($definition['label'] ?? $name) : $name)),
            );
        }

        return $described;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function describeField(array $config, string $label): array
    {
        $entry = [
            'type'     => self::toStr($config['type'] ?? ''),
            'label'    => $label,
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

        return $entry;
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
