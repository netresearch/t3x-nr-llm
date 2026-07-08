<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Schema of a FlexForm field's data structure (ADR-045).
 *
 * A TCA `type=flex` column stores its own nested form definition; this tool
 * resolves that data structure (via {@see FlexFormTools}) and renders its
 * sheets → fields (name, type, label) for the model. When the field selects
 * one of several data structures by a pointer (e.g. tt_content plugins keyed
 * by `list_type`/`CType`) and no `ds_pointer` is given, the available keys are
 * listed so the model can re-call with one.
 *
 * Access gated through {@see TableReadAccessService}, same as
 * {@see GetTableSchemaTool}. Schema only, no record data.
 */
final readonly class GetFlexFormSchemaTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use ResolvesLanguageLabelTrait;
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Table not found or not permitted.';

    public function __construct(
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_flexform_schema',
            'Describe the data structure of a TCA FlexForm field (its sheets and fields). If the field selects one '
            . 'of several structures by a pointer, call again with "ds_pointer" set to one of the listed keys.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The table owning the FlexForm field (e.g. "tt_content").',
                    ],
                    'field' => [
                        'type'        => 'string',
                        'description' => 'The TCA column of type "flex" (e.g. "pi_flexform").',
                    ],
                    'ds_pointer' => [
                        'type'        => 'string',
                        'description' => 'Optional data-structure key selecting one structure when the field has several (e.g. a plugin/CType key).',
                    ],
                ],
                'required' => ['table', 'field'],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $table = trim(self::toStr($arguments['table'] ?? ''));
        $field = trim(self::toStr($arguments['field'] ?? ''));
        if ($table === '' || $field === '') {
            return self::NOT_PERMITTED;
        }

        $user = $this->actingBackendUser();
        if (!$this->tableAccess->canReadTable($user, $table)) {
            return self::NOT_PERMITTED;
        }

        $tableTca  = $this->tableTca($table);
        $columns   = is_array($tableTca['columns'] ?? null) ? $tableTca['columns'] : [];
        $columnTca = is_array($columns[$field] ?? null) ? $columns[$field] : [];
        $conf      = is_array($columnTca['config'] ?? null) ? $columnTca['config'] : null;
        if (!is_array($conf)) {
            return sprintf('Field %s.%s not found.', $table, $field);
        }
        if (self::toStr($conf['type'] ?? '') !== 'flex') {
            return sprintf('Field %s.%s is not a FlexForm field (type=%s).', $table, $field, self::toStr($conf['type'] ?? '?'));
        }

        $pointer = trim(self::toStr($arguments['ds_pointer'] ?? ''));

        // Multiple data structures keyed by a pointer field: without a pointer,
        // list the selectable keys so the model can re-call precisely.
        $dsMap = is_array($conf['ds'] ?? null) ? $conf['ds'] : [];
        $variantKeys = array_values(array_filter(
            array_map(static fn(int|string $k): string => (string)$k, array_keys($dsMap)),
            static fn(string $k): bool => $k !== 'default',
        ));
        if ($pointer === '' && $variantKeys !== []) {
            return sprintf(
                "FlexForm field %s.%s has multiple data structures. Re-call with ds_pointer set to one of:\n%s",
                $table,
                $field,
                implode("\n", array_map(static fn(string $k): string => '- ' . $k, $variantKeys)),
            );
        }

        try {
            $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
            $row           = $this->pointerRow($conf, $pointer);
            // Since v13.4 the resolver expects the table TCA as the $schema
            // argument (no longer read from a global); harmless on older cores
            // that ignore the extra argument.
            $identifier = $flexFormTools->getDataStructureIdentifier($columnTca, $table, $field, $row, $tableTca);
            $structure  = $flexFormTools->parseDataStructureByIdentifier($identifier, $tableTca);
        } catch (Throwable) {
            // Neutral by design — resolution internals must not egress.
            return sprintf('Could not resolve the FlexForm data structure for %s.%s%s.', $table, $field, $pointer !== '' ? sprintf(' (ds_pointer "%s")', $pointer) : '');
        }

        return $this->renderStructure($table, $field, $pointer, $structure);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'structure';
    }

    /**
     * The TCA definition of $table, narrowed to an array.
     *
     * @return array<mixed>
     */
    private function tableTca(string $table): array
    {
        $allTca   = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($allTca) ? ($allTca[$table] ?? null) : null;

        return is_array($tableTca) ? $tableTca : [];
    }

    /**
     * Build a minimal record row carrying the pointer value in the field(s)
     * named by `ds_pointerField`, so FlexFormTools selects the right structure.
     *
     * @param array<string, mixed> $conf
     *
     * @return array<string, mixed>
     */
    private function pointerRow(array $conf, string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        $row            = [];
        $pointerFields  = self::toStr($conf['ds_pointerField'] ?? '');
        foreach (GeneralUtility::trimExplode(',', $pointerFields, true) as $pointerField) {
            $row[$pointerField] = $pointer;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $structure
     */
    private function renderStructure(string $table, string $field, string $pointer, array $structure): string
    {
        $sheets = is_array($structure['sheets'] ?? null) ? $structure['sheets'] : [];
        if ($sheets === []) {
            return sprintf('FlexForm %s.%s resolved but declares no sheets.', $table, $field);
        }

        $lines = [sprintf(
            'FlexForm schema for %s.%s%s:',
            $table,
            $field,
            $pointer !== '' ? sprintf(' [%s]', $pointer) : '',
        )];

        foreach ($sheets as $sheetName => $sheet) {
            $lines[] = sprintf('Sheet: %s', (string)$sheetName);
            $root     = is_array($sheet) && is_array($sheet['ROOT'] ?? null) ? $sheet['ROOT'] : [];
            $elements = is_array($root['el'] ?? null) ? $root['el'] : [];
            foreach ($elements as $elName => $elConf) {
                if (!is_array($elConf)) {
                    continue;
                }
                $config = is_array($elConf['config'] ?? null) ? $elConf['config'] : [];
                $type   = self::toStr($config['type'] ?? '') ?: '?';
                $label  = $this->resolveLabel(self::toStr($elConf['label'] ?? ''));
                $lines[] = $label !== ''
                    ? sprintf('  - %s: %s (%s)', (string)$elName, $type, $label)
                    : sprintf('  - %s: %s', (string)$elName, $type);
            }
        }

        return implode("\n", $lines);
    }
}
