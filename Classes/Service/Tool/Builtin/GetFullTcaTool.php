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
 * Navigation index over the whole TCA (ADR-045).
 *
 * The complete TCA is multiple megabytes — far too large to hand an LLM at
 * once. This tool returns only the *names* of the accessible tables (plus a
 * one-line title and a pointer to {@see GetTableSchemaTool}), so the model can
 * traverse the schema and then request field/relation detail for the few
 * tables it cares about. Index → detail, never a full dump.
 *
 * Access is gated identically to {@see GetTableSchemaTool} via
 * {@see TableReadAccessService}: the sensitive-table denylist holds for every
 * user (admins included), and non-admins additionally pass the `tables_select`
 * and TCA `adminOnly` gates.
 */
final readonly class GetFullTcaTool implements ToolInterface
{
    use ResolvesLanguageLabelTrait;
    use SafeCastTrait;

    /** Upper bound on listed tables to keep the egress bounded. */
    private const MAX_TABLES = 400;

    public function __construct(
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_full_tca',
            'List all accessible TYPO3 tables (the TCA index) with their titles. Use this to discover which '
            . 'tables exist, then call get_table_schema(table) for a table\'s fields and relations. Names only, no data.',
            [
                'type'       => 'object',
                'properties' => [
                    'filter' => [
                        'type'        => 'string',
                        'description' => 'Optional case-insensitive substring to match against table names (e.g. "content", "sys_file").',
                    ],
                    'extension' => [
                        'type'        => 'string',
                        'description' => 'Optional extension key to restrict to tables whose name suggests that extension (best-effort, e.g. "news").',
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

        $user = $context->actingBackendUser();
        if ($user === null) {
            return ToolResult::text('Accessible tables (0).');
        }

        $filter    = mb_strtolower(trim(self::toStr($arguments['filter'] ?? '')));
        $extension = mb_strtolower(trim(self::toStr($arguments['extension'] ?? '')));
        // A conventional prefix derived from the extension key: EXT:my_ext
        // typically owns tables named tx_myext_* (underscores stripped).
        $extPrefix = $extension !== '' ? 'tx_' . str_replace('_', '', $extension) : '';

        // Collect all matching, accessible table names first, then sort — so
        // the MAX_TABLES cut is deterministic (alphabetical) rather than
        // dependent on the non-deterministic $GLOBALS['TCA'] load order.
        $names = [];
        foreach (array_keys($allTca) as $name) {
            $table = (string)$name;

            if ($filter !== '' && !str_contains(mb_strtolower($table), $filter)) {
                continue;
            }
            if ($extPrefix !== '' && !str_starts_with($table, $extPrefix)) {
                continue;
            }
            if (!$this->tableAccess->canReadTable($user, $table)) {
                continue;
            }

            $names[] = $table;
        }

        sort($names);

        $skipped = 0;
        if (count($names) > self::MAX_TABLES) {
            $skipped = count($names) - self::MAX_TABLES;
            $names   = array_slice($names, 0, self::MAX_TABLES);
        }

        $lines = [];
        foreach ($names as $table) {
            $title   = $this->tableTitle($allTca[$table] ?? null);
            $lines[] = $title !== ''
                ? sprintf('%s — %s', $table, $title)
                : $table;
        }

        if ($lines === []) {
            return ToolResult::text('Accessible tables (0). Nothing matched the filter, or you may not read any matching table.');
        }

        $header = sprintf(
            'Accessible tables (%d)%s — call get_table_schema(table) for fields and relations:',
            count($lines),
            $skipped > 0 ? sprintf(', %d more not shown', $skipped) : '',
        );

        return ToolResult::text($header . "\n" . implode("\n", $lines));
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

    private function tableTitle(mixed $definition): string
    {
        $ctrl = is_array($definition) ? ($definition['ctrl'] ?? null) : null;

        return is_array($ctrl) ? $this->resolveLabel(self::toStr($ctrl['title'] ?? '')) : '';
    }
}
