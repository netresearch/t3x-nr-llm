<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\Tca;

use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TCA itemsProcFunc listing the SELECTABLE (optional-only) guardrails for the
 * `allowed_guardrails` select on tx_nrllm_configuration (ADR-106).
 *
 * The list is derived from {@see GuardrailRegistry::selectableIdentifiers()}
 * (not hardcoded) so third-party guardrails appear automatically. Mandatory
 * guardrails (secret redaction) are never listed — they always run and cannot
 * be un-selected. Values already stored on the record but no longer known (an
 * uninstalled extension's guardrail) are appended so the stored selection stays
 * visible and editable rather than silently dropped.
 */
final class GuardrailItems
{
    /**
     * @param array{items: array<int, array{label: string, value: string}>, row?: array<string, mixed>} $params
     */
    public function addItems(array &$params): void
    {
        $registry = GeneralUtility::makeInstance(GuardrailRegistry::class);

        $ids = [];
        foreach ($registry->selectableIdentifiers() as $id) {
            $ids[$id] = true;
        }

        // Keep already-stored but currently unknown identifiers selectable.
        $row    = is_array($params['row'] ?? null) ? $params['row'] : [];
        $stored = $row['allowed_guardrails'] ?? '';
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
            $ids[$known] ??= true;
        }

        foreach (array_keys($ids) as $id) {
            $params['items'][] = ['label' => $id, 'value' => $id];
        }
    }
}
