<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use Netresearch\NrLlm\Service\SetupWizard\ProviderDetector;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Normalizes ``tx_nrllm_provider.endpoint_url`` to the adapter's canonical base
 * URL when a provider is created or edited through the TCA record editor.
 *
 * The Setup Wizard already does this on save (see
 * {@see \Netresearch\NrLlm\Controller\Backend\SetupWizardController} and
 * ADR referencing #98), but a provider created/edited manually in the backend
 * bypasses that path. Without normalization an admin who types
 * ``https://api.openai.com`` would store a bare host, and every provider request
 * would hit ``/models`` instead of ``/v1/models`` — the provider adapters build
 * request URLs as ``baseUrl + '/' + path`` and do not add the version segment
 * themselves. This DataHandler hook makes the manual write path store the same
 * canonical base URL as the wizard (#300).
 */
final class ProviderEndpointNormalizationHook
{
    /**
     * @param array<string, mixed> $fieldArray incoming field values (mutated in place)
     * @param int|string           $id         record uid, or a "NEW..." placeholder for new records
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler,
    ): void {
        if ($table !== 'tx_nrllm_provider' || !array_key_exists('endpoint_url', $fieldArray)) {
            return;
        }

        $endpoint = is_string($fieldArray['endpoint_url']) ? $fieldArray['endpoint_url'] : '';
        if (trim($endpoint) === '') {
            return;
        }

        $adapterType = $this->resolveAdapterType($fieldArray, $id);
        if ($adapterType === '') {
            return;
        }

        $fieldArray['endpoint_url'] = GeneralUtility::makeInstance(ProviderDetector::class)
            ->normalizeEndpointForAdapter($endpoint, $adapterType);
    }

    /**
     * Determine the adapter type for the record being saved: from the incoming
     * change set when present, otherwise from the stored record (an edit that
     * changes only the endpoint carries no ``adapter_type`` in ``$fieldArray``).
     *
     * @param array<string, mixed> $fieldArray
     */
    private function resolveAdapterType(array $fieldArray, int|string $id): string
    {
        if (isset($fieldArray['adapter_type']) && is_string($fieldArray['adapter_type']) && $fieldArray['adapter_type'] !== '') {
            return $fieldArray['adapter_type'];
        }

        if (MathUtility::canBeInterpretedAsInteger($id)) {
            $record = BackendUtility::getRecord('tx_nrllm_provider', (int)$id, 'adapter_type');
            $stored = is_array($record) ? ($record['adapter_type'] ?? null) : null;
            if (is_string($stored)) {
                return $stored;
            }
        }

        return '';
    }
}
