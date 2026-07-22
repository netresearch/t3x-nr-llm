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
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Describe the TYPO3 table schema from `$GLOBALS['TCA']`.
 *
 * With no `table` argument it lists the configured TCA table names; with a
 * real table name it returns that table's column names and each column's
 * `config.type`.
 *
 * Security contract (see {@see ToolInterface}): schema only — never any record
 * data. An unknown table name returns a neutral string (the tool does not
 * confirm or deny table existence beyond the TCA registry the admin already
 * controls). Output is bounded by {@see self::MAX_ITEMS}.
 */
final readonly class GetTcaTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    /** Upper bound on listed tables / columns to keep the egress bounded. */
    private const MAX_ITEMS = 500;

    public function __construct(
        private readonly TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_tca',
            'Describe the TYPO3 TCA schema. Without arguments, lists the configured table names; '
            . 'with a "table" argument, returns that table\'s column names and each column type. Schema only, no data.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'Optional TCA table name to describe (e.g. "pages", "tt_content").',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca) || $tca === []) {
            return ToolResult::text('No TCA available.');
        }

        $table = self::toStr($arguments['table'] ?? '');
        if ($table === '') {
            return ToolResult::text($this->listTables($tca));
        }

        return ToolResult::text($this->describeTable($tca, $table));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; execute() self-enforces the acting user's TYPO3 permissions.
        return false;
    }

    /**
     * @param array<array-key, mixed> $tca
     */
    private function listTables(array $tca): string
    {
        // Respect the acting user's table-select rights: a non-admin only sees
        // tables they may read in the backend (admins pass every check). No
        // backend user → no tables (fail-closed); return early so the whole TCA
        // is not mapped/filtered for nothing.
        $user = $this->actingBackendUser();
        if ($user === null) {
            return "TCA tables (0):\n";
        }

        $names = array_map(static fn(int|string $name): string => (string)$name, array_keys($tca));
        // Routed through the shared table policy, not the raw permission check:
        // BackendUserAuthentication::check() returns true for every table for an
        // admin, so the raw check listed the extension's own credential-bearing
        // tables (and nr_vault's) to any admin-run loop. Its sibling
        // GetFullTcaTool has always used this policy — two schema tools must not
        // disagree on what may be read (ADR-093).
        $names = array_values(array_filter(
            $names,
            fn(string $name): bool => $this->tableAccess->canReadTable($user, $name),
        ));
        sort($names);
        $names = array_slice($names, 0, self::MAX_ITEMS);

        return sprintf("TCA tables (%d):\n", count($names)) . implode("\n", $names);
    }

    /**
     * @param array<array-key, mixed> $tca
     */
    private function describeTable(array $tca, string $table): string
    {
        // A non-admin may only describe tables they can read; an unreadable (or
        // unknown) table returns the same neutral string so the tool never
        // confirms a table's existence to someone without access.
        $user = $this->actingBackendUser();
        if ($user === null || !$this->tableAccess->canReadTable($user, $table)) {
            return 'Unknown TCA table.';
        }

        $definition = $tca[$table] ?? null;
        if (!is_array($definition) || !isset($definition['columns']) || !is_array($definition['columns'])) {
            return 'Unknown TCA table.';
        }

        $lines = [sprintf('TCA columns for %s:', $table)];
        $count = 0;
        foreach ($definition['columns'] as $field => $config) {
            if ($count >= self::MAX_ITEMS) {
                break;
            }

            $type = '';
            if (is_array($config) && isset($config['config']) && is_array($config['config'])) {
                $type = self::toStr($config['config']['type'] ?? '');
            }
            $lines[] = sprintf('%s: %s', (string)$field, $type !== '' ? $type : '?');
            ++$count;
        }

        return implode("\n", $lines);
    }

    public function getGroup(): string
    {
        return 'structure';
    }
}
