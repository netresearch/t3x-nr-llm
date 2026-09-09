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
use Netresearch\NrLlm\Domain\ValueObject\ModelIdentifier;
use Netresearch\NrLlm\Domain\ValueObject\ProviderModelName;
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
        $model = $this->repository->findOneByIdentifier(new ModelIdentifier('gpt-5'));

        self::assertInstanceOf(Model::class, $model);
        self::assertSame('gpt-5', $model->getIdentifier());
    }

    /**
     * The two lookups answer for different columns, and neither answers for
     * the other's value.
     *
     * The shared fixtures give most model rows the same string in `identifier`
     * and `model_id`, so a swapped lookup passes there by coincidence -- which
     * is how #932 stayed invisible. This row carries the shape a wizard
     * creates: `gpt-image-2-a3f7c2` in `identifier`, `gpt-image-2` in
     * `model_id`. Each value must find the row through its own lookup and
     * nothing through the other one.
     */
    #[Test]
    public function theTwoLookupsDoNotAnswerForEachOthersColumn(): void
    {
        $this->importFixture('ModelIdentifierSeparation.csv');

        $byRowIdentifier = $this->repository->findOneByIdentifier(new ModelIdentifier('gpt-image-2-a3f7c2'));
        $byModelName     = $this->repository->findOneByModelId(new ProviderModelName('gpt-image-2'));

        self::assertInstanceOf(Model::class, $byRowIdentifier);
        self::assertInstanceOf(Model::class, $byModelName);
        self::assertSame(701, $byRowIdentifier->getUid());
        self::assertSame(701, $byModelName->getUid());

        self::assertNull(
            $this->repository->findOneByIdentifier(new ModelIdentifier('gpt-image-2')),
            'The provider-side model name must not resolve as a row identifier.',
        );
        self::assertNull(
            $this->repository->findOneByModelId(new ProviderModelName('gpt-image-2-a3f7c2')),
            'The row identifier must not resolve as a provider-side model name.',
        );
    }

    /**
     * A provider-side model name that two providers carry identifies no
     * single row, and the lookup says so instead of guessing (#935).
     *
     * `model_id` has no `unique` eval, so the same model offered through two
     * providers is two rows with their own prices. Answering with the first
     * by `sorting, name` charged one provider's rate to a call served by the
     * other; both callers -- usage attribution and cost estimation -- have a
     * correct behaviour for "no row" and none for "some row".
     */
    #[Test]
    public function findOneByModelIdRefusesANameTwoProvidersShare(): void
    {
        $this->importFixture('AmbiguousModelName.csv');

        self::assertNull($this->repository->findOneByModelId(new ProviderModelName('gpt-image-2')));

        $all = $this->repository->findByModelId(new ProviderModelName('gpt-image-2'));

        self::assertCount(2, $all);
        self::assertSame(
            [801, 802],
            array_map(static fn(Model $model): ?int => $model->getUid(), array_values(iterator_to_array($all))),
        );
    }

    #[Test]
    public function findOneByModelIdAnswersWhenTheNameIsUnambiguous(): void
    {
        $this->importFixture('ModelIdentifierSeparation.csv');

        $model = $this->repository->findOneByModelId(new ProviderModelName('gpt-image-2'));

        self::assertInstanceOf(Model::class, $model);
        self::assertSame(701, $model->getUid());
    }

    /**
     * Extbase's own `findByUid()` does not carry this repository's query
     * settings, so it cannot see a hidden row -- this one can.
     *
     * `initializeObject()` sets `ignoreEnableFields` for every query this
     * repository builds, and the name lookups therefore return hidden rows.
     * `Repository::findByUid()` goes through
     * `Backend::getObjectByIdentifier()`, which builds a FRESH query and
     * only turns off the storage-page restriction. A row that usage
     * attribution resolved by name would then have no price when looked up
     * by its uid, and the call would be priced from the static catalog with
     * the curated row sitting right there.
     */
    #[Test]
    public function findOneByUidSeesARowThatExtbasesOwnLookupHides(): void
    {
        $this->importFixture('AmbiguousModelName.csv');

        // Extbase first: once any lookup has loaded the row, its identity map
        // answers findByUid() from the session and the difference disappears.
        // Asking it second would measure the test's own ordering.
        self::assertNull(
            $this->repository->findByUid(803),
            "Extbase's own lookup is what this method exists to replace.",
        );

        $found = $this->repository->findOneByUid(803);

        self::assertInstanceOf(Model::class, $found);
        self::assertSame('gpt-image-2-only-hidden', $found->getModelId());
    }

    #[Test]
    public function findOneByUidReturnsNullForAnUnknownUid(): void
    {
        self::assertNull($this->repository->findOneByUid(999999));
    }

    #[Test]
    public function findOneByIdentifierReturnsNullForNonExistent(): void
    {
        $model = $this->repository->findOneByIdentifier(new ModelIdentifier('non-existent-model'));

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

    #[Test]
    public function countByProviderCountsHiddenActiveModels(): void
    {
        // findActive() ignores enable-fields (initializeObject →
        // setIgnoreEnableFields), so a hidden but active model must still be
        // counted — the grouped query must not apply the HiddenRestriction.
        $before = $this->repository->countByProvider()[1] ?? 0;

        $this->getConnection()->insert('tx_nrllm_model', [
            'pid'          => 0,
            'identifier'   => 'hidden-active',
            'name'         => 'Hidden Active',
            'provider_uid' => 1,
            'model_id'     => 'hidden-active',
            'is_active'    => 1,
            'hidden'       => 1,
            'deleted'      => 0,
        ]);

        $after = $this->repository->countByProvider()[1] ?? 0;

        self::assertSame($before + 1, $after);
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
        $uids = array_map(fn(Model $m): ?int => $m->getUid(), $models->toArray());
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
        $uids = array_map(fn(Model $m): ?int => $m->getUid(), $models->toArray());
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
        $uids = array_map(fn(Model $m): ?int => $m->getUid(), $models->toArray());
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
        $model = $this->repository->findOneByIdentifier(new ModelIdentifier('gpt-5'));
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

        $retrieved = $this->repository->findOneByIdentifier(new ModelIdentifier('new-test-model'));
        self::assertNotNull($retrieved);
        self::assertSame('New Test Model', $retrieved->getName());
    }
}
