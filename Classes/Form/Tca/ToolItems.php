<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\Tca;

use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TCA itemsProcFunc listing the currently registered tools, grouped by tool
 * group, for the `excluded_tools` select on tx_nrllm_configuration (ADR-261).
 *
 * The list is the registry's, so a third-party or remote tool is selectable as
 * soon as it is registered — and is excluded only once someone selects it.
 * Names already stored on the record but no longer registered (an uninstalled
 * extension's tool, a removed MCP server) are listed in a group of their own so
 * the stored exclusion stays visible and can be removed rather than silently
 * kept.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ToolItems
{
    /** The divider group for stored names no registered tool carries. */
    public const UNKNOWN_GROUP = '__unregistered';

    private const UNKNOWN_LABEL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.excluded_tools.unregistered';

    /**
     * @param array{items: array<int, array<string, mixed>>, row?: array<string, mixed>} $params
     */
    public function addItems(array &$params): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);

        /** @var array<string, list<string>> $byGroup */
        $byGroup = [];
        foreach ($registry->names() as $name) {
            $tool = $registry->get($name);
            if ($tool instanceof ToolInterface) {
                $byGroup[$tool->getGroup()][] = $name;
            }
        }

        ksort($byGroup);

        $registered = array_merge([], ...array_values($byGroup));
        $unknown    = [];
        foreach ($this->storedNames($params['row'] ?? []) as $stored) {
            if (!in_array($stored, $registered, true) && !in_array($stored, $unknown, true)) {
                $unknown[] = $stored;
            }
        }

        if ($unknown !== []) {
            $byGroup[self::UNKNOWN_GROUP] = $unknown;
        }

        foreach ($byGroup as $group => $names) {
            sort($names);
            // A divider per tool group; TYPO3 renders it as the group heading.
            $label             = $group === self::UNKNOWN_GROUP ? self::UNKNOWN_LABEL : (string) $group;
            $params['items'][] = ['label' => $label, 'value' => '--div--', 'group' => (string) $group];
            foreach ($names as $name) {
                $params['items'][] = ['label' => $name, 'value' => $name, 'group' => (string) $group];
            }
        }
    }

    /**
     * The names stored on the record, whatever shape FormEngine hands over.
     *
     * @return list<string>
     */
    private function storedNames(mixed $row): array
    {
        $stored = is_array($row) ? ($row['excluded_tools'] ?? '') : '';
        if (is_array($stored)) {
            $stored = implode(',', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $stored,
            ));
        } elseif (!is_scalar($stored)) {
            $stored = '';
        }

        return GeneralUtility::trimExplode(',', (string) $stored, true);
    }
}
