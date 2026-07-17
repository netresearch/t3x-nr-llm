<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Updates;

use Netresearch\NrLlm\Service\EmbeddingModelDimensions;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Seed the ADR-055 `dimensions` column for well-known embedding models.
 *
 * The column shipped with 0 ("unknown") on every existing row and nothing
 * populated it, so consumers validating a persisted vector index against
 * the configured model kept falling back to a paid live calibration probe.
 * This wizard fills `dimensions` from the EmbeddingModelDimensions catalog
 * for rows whose model_id is a known embedding model and whose value is
 * still 0 — an explicitly configured value is never touched.
 */
#[UpgradeWizard('nrLlm_embeddingModelDimensions')]
final readonly class EmbeddingModelDimensionsUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_nrllm_model';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Seed vector dimensions for well-known LLM embedding models';
    }

    public function getDescription(): string
    {
        return 'The tx_nrllm_model.dimensions column (ADR-055) defaults to 0 ("unknown"), which makes '
            . 'embedding consumers fall back to a live calibration call against the provider. This wizard '
            . 'fills the column with the published vector dimensionality for well-known embedding models '
            . '(e.g. text-embedding-3-small, mistral-embed, nomic-embed-text) where it is still 0. '
            . 'Rows with an explicitly configured value are not touched.';
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($this->seedableRows() as $uid => $dimensions) {
            $connection->update(
                self::TABLE,
                ['dimensions' => $dimensions],
                ['uid' => $uid],
                ['dimensions' => Connection::PARAM_INT],
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->seedableRows() !== [];
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * Rows still at dimensions = 0 whose model_id is a known embedding
     * model, as uid => catalog dimensionality.
     *
     * @return array<int, int>
     */
    private function seedableRows(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // Include hidden and deleted rows so a later un-delete stays consistent.
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'model_id')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'dimensions',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $seedable = [];
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
            $modelId = is_string($row['model_id'] ?? null) ? $row['model_id'] : '';
            $dimensions = EmbeddingModelDimensions::forModelId($modelId);
            if ($uid > 0 && $dimensions > 0) {
                $seedable[$uid] = $dimensions;
            }
        }

        return $seedable;
    }
}
