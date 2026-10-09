<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;

/**
 * Builds TYPO3 FormEngine record URLs for backend list modules.
 *
 * Centralizes the record_edit route construction every backend list
 * controller needs: edit and new-record links that send the user back
 * to the originating module after save/close.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class FormEngineUrlBuilder
{
    public function __construct(
        private BackendUriBuilder $backendUriBuilder,
    ) {}

    /**
     * Build a FormEngine URL editing an existing record.
     *
     * @param string $tableName   record table, e.g. 'tx_nrllm_promptsnippet'
     * @param int    $uid         uid of the record to edit
     * @param string $returnRoute backend module route to return to after save/close
     */
    public function buildEditUrl(string $tableName, int $uid, string $returnRoute): string
    {
        return $this->buildRecordUrl($tableName, [$uid => 'edit'], $returnRoute);
    }

    /**
     * Build a FormEngine URL creating a new record.
     *
     * @param string $tableName   record table, e.g. 'tx_nrllm_promptsnippet'
     * @param string $returnRoute backend module route to return to after save/close
     */
    public function buildNewUrl(string $tableName, string $returnRoute): string
    {
        return $this->buildRecordUrl($tableName, [0 => 'new'], $returnRoute);
    }

    /**
     * Build a FormEngine URL creating a new record with preset field values
     * (FormEngine's ``defVals``), e.g. the source a backend-authored skill
     * belongs to (ADR-214 item 3).
     *
     * @param array<string, int|string> $defaults field name => value
     */
    public function buildNewUrlWithDefaults(string $tableName, array $defaults, string $returnRoute): string
    {
        return $this->buildRecordUrl($tableName, [0 => 'new'], $returnRoute, $defaults);
    }

    /**
     * @param array<int, string>        $commands FormEngine edit commands, uid => 'edit' | 'new'
     * @param array<string, int|string> $defaults
     */
    private function buildRecordUrl(string $tableName, array $commands, string $returnRoute, array $defaults = []): string
    {
        $parameters = [
            'edit' => [$tableName => $commands],
            'returnUrl' => (string)$this->backendUriBuilder->buildUriFromRoute($returnRoute),
        ];
        if ($defaults !== []) {
            $parameters['defVals'] = [$tableName => $defaults];
        }

        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', $parameters);
    }
}
