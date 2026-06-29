<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
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
    use SafeCastTrait;

    /** Upper bound on listed tables / columns to keep the egress bounded. */
    private const MAX_ITEMS = 500;

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

    public function execute(array $arguments): string
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca) || $tca === []) {
            return 'No TCA available.';
        }

        $table = self::toStr($arguments['table'] ?? '');
        if ($table === '') {
            return $this->listTables($tca);
        }

        return $this->describeTable($tca, $table);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    /**
     * @param array<array-key, mixed> $tca
     */
    private function listTables(array $tca): string
    {
        $names = array_map(static fn(int|string $name): string => (string)$name, array_keys($tca));
        sort($names);
        $names = array_slice($names, 0, self::MAX_ITEMS);

        return sprintf("TCA tables (%d):\n", count($names)) . implode("\n", $names);
    }

    /**
     * @param array<array-key, mixed> $tca
     */
    private function describeTable(array $tca, string $table): string
    {
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
}
