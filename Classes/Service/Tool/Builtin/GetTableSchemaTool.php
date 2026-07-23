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

/**
 * LLM-friendly full schema of one TCA table (ADR-045).
 *
 * Richer than {@see GetTcaTool}: besides field name and TCA type it surfaces
 * the ctrl highlights (label, sorting, language, enable/delete/versioning) and
 * — the key value — the FOREIGN TABLE and relation kind for relational fields
 * (group/select/inline/category), so the model can reason about how records
 * connect. Schema only, never record data.
 *
 * Access gated through {@see TableReadAccessService}: sensitive-table denylist
 * for every user (admins included); non-admins additionally pass `tables_select`
 * and the TCA `adminOnly` flag. Credential-ish columns are listed by name/type
 * only (no further config detail). Output is bounded.
 */
final readonly class GetTableSchemaTool implements ToolInterface
{
    use ResolvesLanguageLabelTrait;
    use SafeCastTrait;

    /** Upper bound on described columns to keep the egress bounded. */
    private const MAX_COLUMNS = 300;

    private const NOT_PERMITTED = 'Table not found or not permitted.';

    public function __construct(
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_table_schema',
            'Describe one TYPO3 table\'s schema in a readable form: control settings plus, per field, its type '
            . 'and — for relations — the foreign table and relation kind. Richer than get_tca. Schema only, no data.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The TCA table name to describe (e.g. "tt_content", "pages").',
                    ],
                ],
                'required' => ['table'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $table = trim(self::toStr($arguments['table'] ?? ''));
        if ($table === '') {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $user = $context->actingBackendUser();
        if (!$this->tableAccess->canReadTable($user, $table)) {
            // Same neutral string whether unknown, denylisted or unpermitted —
            // the tool never confirms a table's existence to the unauthorised.
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $allTca = $GLOBALS['TCA'] ?? null;
        $tca    = is_array($allTca) ? ($allTca[$table] ?? null) : null;
        if (!is_array($tca) || !is_array($tca['columns'] ?? null)) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        /** @var array<string, mixed> $ctrl */
        $ctrl  = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $lines = [sprintf('Table: %s', $table)];

        $title = $this->resolveLabel(self::toStr($ctrl['title'] ?? ''));
        if ($title !== '') {
            $lines[] = sprintf('Title: %s', $title);
        }
        $lines[] = 'Control: ' . $this->ctrlSummary($ctrl);
        $lines[] = '';
        $lines[] = 'Fields:';

        $count = 0;
        foreach ($tca['columns'] as $field => $config) {
            if ($count >= self::MAX_COLUMNS) {
                $lines[] = sprintf('… [%d fields not shown]', count($tca['columns']) - self::MAX_COLUMNS);
                break;
            }
            $lines[] = '- ' . $this->describeField((string)$field, $config);
            ++$count;
        }

        return ToolResult::text(implode("\n", $lines));
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
     * @param array<string, mixed> $ctrl
     */
    private function ctrlSummary(array $ctrl): string
    {
        $parts = [];
        foreach (['label', 'label_alt', 'languageField', 'transOrigPointerField', 'sortby', 'default_sortby', 'delete'] as $key) {
            $value = self::toStr($ctrl[$key] ?? '');
            if ($value !== '') {
                $parts[] = sprintf('%s=%s', $key, $value);
            }
        }
        $enablecolumns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        if ($enablecolumns !== []) {
            $parts[] = 'enable=' . implode('/', array_map(
                static fn(mixed $v): string => is_scalar($v) ? (string)$v : '',
                $enablecolumns,
            ));
        }
        // versioningWS may be a bool or an int (legacy 1/2) — !empty() catches
        // both; a strict === true would miss integer-configured tables.
        if (!empty($ctrl['versioningWS'])) {
            $parts[] = 'workspace-capable';
        }

        return $parts === [] ? '(none)' : implode(', ', $parts);
    }

    private function describeField(string $field, mixed $config): string
    {
        $conf = is_array($config) && is_array($config['config'] ?? null) ? $config['config'] : [];
        $type = self::toStr($conf['type'] ?? '') ?: '?';

        $detail = [$type];

        $renderType = self::toStr($conf['renderType'] ?? '');
        if ($renderType !== '') {
            $detail[] = 'renderType=' . $renderType;
        }

        // The key value over get_tca: surface the relation target + kind.
        $foreign = self::toStr($conf['foreign_table'] ?? '');
        if ($foreign !== '') {
            $kind = match ($type) {
                'inline'   => 'inline',
                'select'   => 'select',
                'group'    => 'group',
                'category' => 'category',
                default    => 'relation',
            };
            $detail[] = sprintf('→ %s (%s)', $foreign, $kind);
        } elseif ($type === 'group') {
            $allowed = self::toStr($conf['allowed'] ?? '');
            if ($allowed !== '') {
                $detail[] = sprintf('→ %s (group)', $allowed);
            }
        }

        $mm = self::toStr($conf['MM'] ?? '');
        if ($mm !== '') {
            $detail[] = 'via MM ' . $mm;
        }

        if (($conf['required'] ?? false) === true) {
            $detail[] = 'required';
        }

        // Credential-ish columns: name + type only, never further config.
        if ($this->tableAccess->isSensitiveField($field)) {
            return sprintf('%s: %s [sensitive — detail withheld]', $field, $type);
        }

        return sprintf('%s: %s', $field, implode(', ', $detail));
    }
}
