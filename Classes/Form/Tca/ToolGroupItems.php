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
 * TCA itemsProcFunc listing the tool groups of the currently registered
 * tools for the `allowed_tool_groups` select on tx_nrllm_configuration.
 *
 * The group list is derived from the registry (not hardcoded) so third-party
 * tools' groups appear automatically. Values already stored on the record but
 * no longer known (an uninstalled extension's group) are appended so the
 * stored selection stays visible and editable rather than silently dropped.
 */
final class ToolGroupItems
{
    /**
     * @param array{items: array<int, array{label: string, value: string}>, row?: array<string, mixed>} $params
     */
    public function addItems(array &$params): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);

        $groups = [];
        foreach ($registry->names() as $name) {
            $tool = $registry->get($name);
            if ($tool instanceof ToolInterface) {
                $groups[$tool->getGroup()] = true;
            }
        }
        ksort($groups);

        // Keep already-stored but currently unknown groups selectable.
        $row    = is_array($params['row'] ?? null) ? $params['row'] : [];
        $stored = $row['allowed_tool_groups'] ?? '';
        if (is_array($stored)) {
            $stored = implode(',', array_map(
                static fn(mixed $value): string => is_scalar($value) ? (string)$value : '',
                $stored,
            ));
        } elseif (!is_scalar($stored)) {
            $stored = '';
        } else {
            $stored = (string)$stored;
        }
        foreach (GeneralUtility::trimExplode(',', $stored, true) as $known) {
            $groups[$known] ??= true;
        }

        foreach (array_keys($groups) as $group) {
            $params['items'][] = ['label' => $group, 'value' => $group];
        }
    }
}
