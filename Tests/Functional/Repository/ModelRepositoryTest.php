<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Functional tests for ModelRepository.
 *
 * Tests data access layer for user pathways:
 * - Pathway 3.1: View Model List
 * - Pathway 3.2: Filter by Provider
 * - Pathway 3.3: Toggle Model Status
 * - Pathway 3.4: Set Default Model
 */
#[CoversClass(ModelRepository::class)]
final class ModelRepositoryTest extends AbstractFunctionalTestCase
{
    private ModelRepository $repository;
    private ProviderRepository $providerRepository;
    private PersistenceManagerInterface $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $repository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $repository);
        $this->repository = $repository;

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $this->providerRepository = $providerRepository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;
    }

    // =========================================================================
    // Pathway 3.1: View Model List
    // =========================================================================

    #[Test]
    public function findAllReturnsAllModels(): void
    {
        $models = $this->repository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $models);

        self::assertGreaterThan(0, $models->count());
    }

    #[Test]
    public function findActiveReturnsOnlyActiveModels(): void
    {
        $models = $this->repository->findActive();

        foreach ($models as $model) {
            self::assertTrue($model->isActive());
        }
    }

    #[Test]
    public function findOneByIdentifierReturnsModel(): void
    {
        $model = $this->repository->findOneByIdentifier('gpt-5');

        self::assertInstanceOf(Model::class, $model);
        self::assertSame('gpt-5', $model->getIdentifier());
    }

    #[Test]
    public function findOneByIdentifierReturnsNullForNonExistent(): void
    {
        $model = $this->repository->findOneByIdentifier('non-existent-model');

        self::assertNull($model);
    }

    #[Test]
    public function findByUidReturnsModel(): void
    {
        $model = $this->repository->findByUid(1);

        self::assertInstanceOf(Model::class, $model);
        self::assertSame(1, $model->getUid());
    }

    // =========================================================================
    // Pathway 3.2: Filter by Provider
    // =========================================================================

    #[Test]
    public function findByProviderReturnsModelsForProvider(): void
    {
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);

        $models = $this->repository->findByProvider($provider);

        self::assertGreaterThan(0, $models->count());
        foreach ($models as $model) {
            self::assertSame($provider->getUid(), $model->getProvider()?->getUid());
        }
    }

    #[Test]
    public function findByProviderUidReturnsModelsForProviderUid(): void
    {
        $models = $this->repository->findByProviderUid(1);

        self::assertGreaterThan(0, $models->count());
        foreach ($models as $model) {
            self::assertTrue($model->isActive());
        }
    }

    #[Test]
    public function findByProviderUidReturnsEmptyForNonExistentProvider(): void
    {
        $models = $this->repository->findByProviderUid(99999);

        self::assertSame(0, $models->count());
    }

    #[Test]
    public function countByProviderReturnsCorrectCounts(): void
    {
        $counts = $this->repository->countByProvider();

        self::assertNotEmpty($counts);

        foreach ($counts as $count) {
            self::assertGreaterThan(0, $count);
        }
    }

    // =========================================================================
    // Pathway 3.3: Toggle Model Status
    // =========================================================================

    #[Test]
    public function updatePersistsChanges(): void
    {
        $model = $this->repository->findByUid(1);
        self::assertNotNull($model);

        $originalActive = $model->isActive();
        $model->setIsActive(!$originalActive);

        $this->repository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reloaded = $this->repository->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertSame(!$originalActive, $reloaded->isActive());
    }

    #[Test]
    public function countActiveReturnsNonDeletedCount(): void
    {
        $count = $this->repository->countActive();

        self::assertGreaterThan(0, $count);
    }

    // =========================================================================
    // Pathway 3.4: Set Default Model
    // =========================================================================

    #[Test]
    public function findDefaultReturnsDefaultModel(): void
    {
        $model = $this->repository->findDefault();

        self::assertInstanceOf(Model::class, $model);
        self::assertTrue($model->isDefault());
        self::assertTrue($model->isActive());
    }

    #[Test]
    public function setAsDefaultChangesDefault(): void
    {
        // Find a non-default model
        $models = $this->repository->findActive()->toArray();
        $nonDefault = null;
        foreach ($models as $model) {
            if (!$model->isDefault()) {
                $nonDefault = $model;
                break;
            }
        }

        if ($nonDefault === null) {
            self::markTestSkipped('No non-default model found in fixtures');
        }

        $this->repository->setAsDefault($nonDefault);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $newDefault = $this->repository->findDefault();
        self::assertSame($nonDefault->getUid(), $newDefault?->getUid());
    }

    #[Test]
    public function unsetAllDefaultsClearsAllDefaults(): void
    {
        $this->repository->unsetAllDefaults();
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $default = $this->repository->findDefault();

        self::assertNull($default);
    }

    // =========================================================================
    // Capability Filtering
    // =========================================================================

    #[Test]
    public function findByCapabilityReturnsMatchingModels(): void
    {
        // Verify data is in the database via direct SQL
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_model');
        $row = $connection->select(['capabilities'], 'tx_nrllm_model', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);
        $capabilities = $row['capabilities'];
        self::assertIsString($capabilities);
        self::assertStringContainsString('chat', $capabilities, 'Database should have capabilities');

        // Repository uses SQL LIKE to query by capability
        $models = $this->repository->findByCapability('chat');

        // Fixtures have 2 active models with chat capability (gpt-5 and llama3)
        self::assertGreaterThan(0, $models->count());

        // Verify we got the expected models by UID
        $uids = array_map(fn($m) => $m->getUid(), $models->toArray());
        self::assertContains(1, $uids, 'Should find gpt-5 (uid=1) with chat capability');
        self::assertContains(3, $uids, 'Should find llama3 (uid=3) with chat capability');

        // All returned models should be active
        foreach ($models as $model) {
            self::assertTrue($model->isActive());
        }
    }

    #[Test]
    public function findChatModelsReturnsModelsWithChatCapability(): void
    {
        $models = $this->repository->findChatModels();

        // Fixtures have 2 active models with chat capability
        self::assertGreaterThan(0, $models->count());

        // Verify correct models returned by UID
        $uids = array_map(fn($m) => $m->getUid(), $models->toArray());
        self::assertContains(1, $uids);
        self::assertContains(3, $uids);
    }

    #[Test]
    public function findEmbeddingModelsReturnsEmptyWhenNoEmbeddingModels(): void
    {
        // Fixtures have no models with embeddings capability
        $models = $this->repository->findEmbeddingModels();

        self::assertSame(0, $models->count());
    }

    #[Test]
    public function findVisionModelsReturnsModelsWithVisionCapability(): void
    {
        $models = $this->repository->findVisionModels();

        // Fixtures have 1 active model with vision capability (gpt-5, uid=1)
        self::assertGreaterThan(0, $models->count());

        // Verify correct model returned by UID
        $uids = array_map(fn($m) => $m->getUid(), $models->toArray());
        self::assertContains(1, $uids, 'Should find gpt-5 with vision capability');
    }

    // =========================================================================
    // Identifier Uniqueness
    // =========================================================================

    #[Test]
    public function isIdentifierUniqueReturnsTrueForNewIdentifier(): void
    {
        $result = $this->repository->isIdentifierUnique('brand-new-model-identifier');

        self::assertTrue($result);
    }

    #[Test]
    public function isIdentifierUniqueReturnsFalseForExistingIdentifier(): void
    {
        $result = $this->repository->isIdentifierUnique('gpt-5');

        self::assertFalse($result);
    }

    #[Test]
    public function isIdentifierUniqueExcludesOwnRecord(): void
    {
        $model = $this->repository->findOneByIdentifier('gpt-5');
        self::assertNotNull($model);

        $result = $this->repository->isIdentifierUnique('gpt-5', $model->getUid());

        self::assertTrue($result);
    }

    // =========================================================================
    // CRUD Operations
    // =========================================================================

    #[Test]
    public function addPersistsNewModel(): void
    {
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('new-test-model');
        $model->setName('New Test Model');
        $model->setModelId('new-test-model-id');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->repository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->repository->findOneByIdentifier('new-test-model');
        self::assertNotNull($retrieved);
        self::assertSame('New Test Model', $retrieved->getName());
    }
}
