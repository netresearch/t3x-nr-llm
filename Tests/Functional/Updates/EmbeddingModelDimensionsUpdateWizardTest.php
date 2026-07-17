<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Updates;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Updates\EmbeddingModelDimensionsUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

#[CoversClass(EmbeddingModelDimensionsUpdateWizard::class)]
final class EmbeddingModelDimensionsUpdateWizardTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_model';

    #[Test]
    public function executeUpdateSeedsOnlyKnownModelsStillAtZero(): void
    {
        $this->insertModel('openai-small', 'text-embedding-3-small', 0);
        $this->insertModel('mistral', 'mistral-embed', 0);
        $this->insertModel('custom-dims', 'text-embedding-3-small', 256);
        $this->insertModel('chat-model', 'gpt-5.2', 0);

        $wizard = new EmbeddingModelDimensionsUpdateWizard($this->getConnectionPool());

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(1536, $this->dimensionsOf('openai-small'));
        self::assertSame(1024, $this->dimensionsOf('mistral'));
        // An explicitly configured value is never touched.
        self::assertSame(256, $this->dimensionsOf('custom-dims'));
        // Unknown models stay at 0 ("unknown").
        self::assertSame(0, $this->dimensionsOf('chat-model'));
        self::assertFalse($wizard->updateNecessary());
    }

    #[Test]
    public function executeUpdateResolvesOllamaTagSuffixes(): void
    {
        $this->insertModel('ollama-nomic', 'nomic-embed-text:latest', 0);

        $wizard = new EmbeddingModelDimensionsUpdateWizard($this->getConnectionPool());

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(768, $this->dimensionsOf('ollama-nomic'));
    }

    #[Test]
    public function executeUpdateSeedsHiddenAndDeletedRows(): void
    {
        $this->insertModel('hidden-embed', 'text-embedding-3-large', 0, hidden: 1, deleted: 1);

        $wizard = new EmbeddingModelDimensionsUpdateWizard($this->getConnectionPool());

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(3072, $this->dimensionsOf('hidden-embed'));
    }

    #[Test]
    public function updateNotNecessaryWithoutSeedableRows(): void
    {
        $this->insertModel('chat-only', 'gpt-5.2', 0);

        $wizard = new EmbeddingModelDimensionsUpdateWizard($this->getConnectionPool());

        self::assertFalse($wizard->updateNecessary());
    }

    private function insertModel(
        string $identifier,
        string $modelId,
        int $dimensions,
        int $hidden = 0,
        int $deleted = 0,
    ): void {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'identifier' => $identifier,
            'name' => $identifier,
            'model_id' => $modelId,
            'dimensions' => $dimensions,
            'hidden' => $hidden,
            'deleted' => $deleted,
        ], ['dimensions' => Connection::PARAM_INT]);
    }

    private function dimensionsOf(string $identifier): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder
            ->select('dimensions')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'identifier',
                    $queryBuilder->createNamedParameter($identifier),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$value;
    }
}
