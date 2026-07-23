<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Deterministic structural TCA checks (ADR-046): the "prüfe TCA auf Fehler"
 * answer an LLM cannot compute reliably by reading the schema alone.
 *
 * Checks per table: `ctrl.label`/`ctrl.type` name defined columns; every
 * `foreign_table` is a TCA table; `types`/`palettes` showitem entries
 * reference defined columns and palettes; `ds_pointerField` on flex fields
 * is flagged on TYPO3 v14+ (removed there). Pure array walking over the
 * live `$GLOBALS['TCA']` — the core :php:`TcaMigration` is NOT reusable
 * here because it only reports while migrating the raw, pre-boot TCA.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): only tables
 * passing {@see TableReadAccessService::canReadTable()} are validated (the
 * denylist holds for admins too); single-table mode answers with the same
 * neutral denial as the schema tools. Findings name schema keys, never data.
 */
final readonly class ValidateTcaTool implements ToolInterface
{
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Table not found or not permitted.';

    /** Global cap on emitted findings. */
    private const MAX_FINDINGS = 50;

    /** Row-level fields that are valid targets without being TCA columns. */
    private const IMPLICIT_FIELDS = ['uid', 'pid'];

    public function __construct(
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'validate_tca',
            'Run structural checks over the TCA and report misconfigurations: label/type fields that do '
            . 'not exist, foreign_table references to unknown tables, showitem entries referencing '
            . 'undefined columns or palettes. Omit "table" to scan all accessible tables.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'Optional: validate only this table (e.g. "tx_myext_item").',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $allTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($allTca) || $allTca === []) {
            return ToolResult::text('No TCA available.');
        }

        $user  = $context->actingBackendUser();
        $table = trim(self::toStr($arguments['table'] ?? ''));

        if ($table !== '') {
            if (!$this->tableAccess->canReadTable($user, $table) || !is_array($allTca[$table] ?? null)) {
                return ToolResult::text(self::NOT_PERMITTED);
            }

            $findings = $this->validateTable($table, $allTca[$table], $allTca);
            if ($findings === []) {
                return ToolResult::text(sprintf('No TCA issues found in table "%s".', $table));
            }

            return ToolResult::text($this->render($findings, 1));
        }

        $findings = [];
        $checked  = 0;
        foreach ($allTca as $name => $definition) {
            $tableName = (string)$name;
            if (!is_array($definition) || !$this->tableAccess->canReadTable($user, $tableName)) {
                continue;
            }
            ++$checked;
            foreach ($this->validateTable($tableName, $definition, $allTca) as $finding) {
                $findings[] = $finding;
            }
        }

        if ($findings === []) {
            return ToolResult::text(sprintf('No TCA issues found in %d checked tables.', $checked));
        }

        return ToolResult::text($this->render($findings, $checked));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; execute() self-enforces table read access.
        return false;
    }

    public function getGroup(): string
    {
        return 'structure';
    }

    /**
     * @param array<mixed>            $tca
     * @param array<array-key, mixed> $allTca
     *
     * @return list<string>
     */
    private function validateTable(string $table, array $tca, array $allTca): array
    {
        /** @var array<string, mixed> $ctrl */
        $ctrl     = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $columns  = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];

        // Fields declared via ctrl are core-managed (and since v13 mostly
        // auto-created): enablecolumns, language fields, editlock, … — a
        // showitem reference to them is valid without a columns definition.
        $ctrlFields = $this->ctrlDeclaredFields($ctrl);

        $findings = [];

        // 1. ctrl.label must name a defined column.
        $label = self::toStr($ctrl['label'] ?? '');
        if ($label === '') {
            $findings[] = sprintf('%s: ctrl.label is not set', $table);
        } elseif (!$this->isKnownField($label, $columns)) {
            $findings[] = sprintf('%s: ctrl.label "%s" is not a defined column', $table, $label);
        }

        // 2. ctrl.type (record type field; "field:pointer" takes the first part).
        $typeField = self::toStr($ctrl['type'] ?? '');
        if ($typeField !== '') {
            $first = trim(explode(':', $typeField)[0]);
            if ($first !== '' && !$this->isKnownField($first, $columns)) {
                $findings[] = sprintf('%s: ctrl.type "%s" is not a defined column', $table, $first);
            }
        }

        // 3. Relational configs must point at existing TCA tables; 6. flex
        //    ds_pointerField no longer exists on v14.
        $v14Plus = (new Typo3Version())->getMajorVersion() >= 14;
        foreach ($columns as $field => $column) {
            $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];

            $foreign = self::toStr($config['foreign_table'] ?? '');
            if ($foreign !== '' && !is_array($allTca[$foreign] ?? null)) {
                $findings[] = sprintf(
                    '%s: column "%s" foreign_table "%s" is not a TCA table',
                    $table,
                    (string)$field,
                    $foreign,
                );
            }

            if ($v14Plus
                && self::toStr($config['type'] ?? '') === 'flex'
                && isset($config['ds_pointerField'])
            ) {
                $findings[] = sprintf(
                    '%s: column "%s" uses ds_pointerField — removed in TYPO3 v14 (use record types)',
                    $table,
                    (string)$field,
                );
            }
        }

        // 4. types[*].showitem references.
        $types = is_array($tca['types'] ?? null) ? $tca['types'] : [];
        foreach ($types as $typeName => $typeConf) {
            $showitem = is_array($typeConf) ? self::toStr($typeConf['showitem'] ?? '') : '';
            foreach ($this->showitemFindings($showitem, $columns, $ctrlFields, $palettes) as $message) {
                $findings[] = sprintf('%s: types[%s] %s', $table, (string)$typeName, $message);
            }
        }

        // 5. palettes[*].showitem references (no palette nesting allowed).
        foreach ($palettes as $paletteName => $paletteConf) {
            $showitem = is_array($paletteConf) ? self::toStr($paletteConf['showitem'] ?? '') : '';
            foreach ($this->showitemFindings($showitem, $columns, $ctrlFields, null) as $message) {
                $findings[] = sprintf('%s: palettes[%s] %s', $table, (string)$paletteName, $message);
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Findings for one showitem string. With $palettes === null, palette
     * references themselves are flagged (palettes cannot nest).
     *
     * @param array<array-key, mixed>      $columns
     * @param list<string>                 $ctrlFields
     * @param array<array-key, mixed>|null $palettes
     *
     * @return list<string>
     */
    private function showitemFindings(string $showitem, array $columns, array $ctrlFields, ?array $palettes): array
    {
        $findings = [];
        foreach (explode(',', $showitem) as $item) {
            $parts     = explode(';', trim($item));
            $fieldName = trim($parts[0]);

            if ($fieldName === '' || $fieldName === '--div--' || $fieldName === '--linebreak--') {
                continue;
            }

            if ($fieldName === '--palette--') {
                $paletteName = trim($parts[2] ?? '');
                if ($palettes === null) {
                    $findings[] = 'nests a --palette-- reference (palettes cannot contain palettes)';
                } elseif ($paletteName === '' || !is_array($palettes[$paletteName] ?? null)) {
                    $findings[] = sprintf('references unknown palette "%s"', $paletteName);
                }
                continue;
            }

            if (!$this->isKnownField($fieldName, $columns) && !in_array($fieldName, $ctrlFields, true)) {
                $findings[] = sprintf('showitem references undefined column "%s"', $fieldName);
            }
        }

        return $findings;
    }

    /**
     * @param array<array-key, mixed> $columns
     */
    private function isKnownField(string $field, array $columns): bool
    {
        return isset($columns[$field]) || in_array($field, self::IMPLICIT_FIELDS, true);
    }

    /**
     * Column names declared through ctrl: enablecolumns values plus the
     * single-field ctrl keys the core manages (auto-created since v13).
     *
     * @param array<string, mixed> $ctrl
     *
     * @return list<string>
     */
    private function ctrlDeclaredFields(array $ctrl): array
    {
        $fields = [];

        $enablecolumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        foreach ($enablecolumns as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }

        foreach ([
            'languageField',
            'transOrigPointerField',
            'translationSource',
            'transOrigDiffSourceField',
            'origUid',
            'editlock',
            'descriptionColumn',
            'sortby',
            'tstamp',
            'crdate',
            'delete',
        ] as $key) {
            $field = self::toStr($ctrl[$key] ?? '');
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param list<string> $findings
     */
    private function render(array $findings, int $checked): string
    {
        $total = count($findings);
        if ($total > self::MAX_FINDINGS) {
            $findings   = array_slice($findings, 0, self::MAX_FINDINGS);
            $findings[] = sprintf('… %d more findings not shown', $total - self::MAX_FINDINGS);
        }

        return sprintf("TCA findings (%d):\n", $total)
            . '- ' . implode("\n- ", $findings)
            . sprintf("\nChecked %d %s.", $checked, $checked === 1 ? 'table' : 'tables');
    }
}
