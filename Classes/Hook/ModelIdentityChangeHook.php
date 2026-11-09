<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Forgets a model record's discovered reasoning profile when the record
 * stops naming the model that profile was discovered for (ADR-275).
 *
 * The discovered fields describe one model id on one provider. An edit that
 * changes either would otherwise hand the old answer to the new model, and
 * the adapter would strip sampling parameters, send an effort scale or raise
 * the output floor for a model nobody measured. The operator fields stay:
 * they are the operator's statement about the record, not a measurement.
 *
 * DataHandler passes only the fields that actually changed to this hook, so
 * a save that leaves the id and the provider as they were clears nothing.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ModelIdentityChangeHook
{
    private const TABLE = 'tx_nrllm_model';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @param array<string, mixed> $fieldArray the changed fields only
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
    ): void {
        if ($status !== 'update' || $table !== self::TABLE || !MathUtility::canBeInterpretedAsInteger($id)) {
            return;
        }

        if (!array_key_exists('model_id', $fieldArray) && !array_key_exists('provider_uid', $fieldArray)) {
            return;
        }

        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                [
                    'reasoning_discovered'         => '',
                    'reasoning_efforts_discovered' => '',
                    'reasoning_source'             => '',
                    'reasoning_confirmed_at'       => 0,
                ],
                ['uid' => (int) $id],
            );
    }
}
