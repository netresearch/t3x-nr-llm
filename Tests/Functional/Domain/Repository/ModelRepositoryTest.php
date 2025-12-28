<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for ModelRepository.
 */
final class ModelRepositoryTest extends AbstractFunctionalTestCase
{
    private ModelRepository $repository;
    private ProviderRepository $providerRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $this->repository = $this->get(ModelRepository::class);
        $this->providerRepository = $this->get(ProviderRepository::class);
        $this->persistenceManager = $this->get(PersistenceManagerInterface::class);
    }

    #[Test]
    public function findAllReturnsModels(): void
    {
        $models = $this->repository->findAll();

        self::assertNotEmpty($models);
        foreach ($models as $model) {
            self::assertInstanceOf(Model::class, $model);
        }
    }

    #[Test]
    public function findByUidReturnsModel(): void
    {
        $model = $this->repository->findByUid(1);

        self::assertNotNull($model);
        self::assertInstanceOf(Model::class, $model);
        self::assertEquals('gpt-4o', $model->getIdentifier());
    }

    #[Test]
    public function findByUidReturnsNullForNonexistent(): void
    {
        $model = $this->repository->findByUid(99999);

        self::assertNull($model);
    }

    #[Test]
    public function findByUidReturnsNullForDeletedModel(): void
    {
        // UID 4 is marked as deleted in fixture
        $model = $this->repository->findByUid(4);

        self::assertNull($model);
    }

    #[Test]
    public function findActiveReturnsOnlyActiveModels(): void
    {
        $models = $this->repository->findActive();

        self::assertNotEmpty($models);
        foreach ($models as $model) {
            self::assertTrue($model->isActive());
        }
    }

    #[Test]
    public function toggleActivePersistsChange(): void
    {
        $model = $this->repository->findByUid(1);
        self::assertNotNull($model);
        self::assertTrue($model->isActive());

        // Toggle to inactive
        $model->setIsActive(false);
        $this->repository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Reload and verify
        $reloaded = $this->repository->findByUid(1);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function setDefaultClearsOtherDefaults(): void
    {
        // UID 1 is default, UID 3 is not
        $currentDefault = $this->repository->findByUid(1);
        $newDefault = $this->repository->findByUid(3);

        self::assertTrue($currentDefault->isDefault());
        self::assertFalse($newDefault->isDefault());

        // Set UID 3 as default using repository method
        $this->repository->setAsDefault($newDefault);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Reload and verify
        $reloadedOld = $this->repository->findByUid(1);
        $reloadedNew = $this->repository->findByUid(3);

        self::assertFalse($reloadedOld->isDefault());
        self::assertTrue($reloadedNew->isDefault());
    }

    #[Test]
    public function findByProviderUidReturnsModelsForProvider(): void
    {
        $models = $this->repository->findByProviderUid(1);

        self::assertNotEmpty($models);
        // All returned models should be for provider_uid 1
        self::assertGreaterThan(0, $models->count());
    }

    #[Test]
    public function findByIdentifierReturnsModel(): void
    {
        $model = $this->repository->findOneByIdentifier('gpt-4o');

        self::assertNotNull($model);
        self::assertEquals(1, $model->getUid());
    }

    #[Test]
    public function findByIdentifierReturnsNullForNonexistent(): void
    {
        $model = $this->repository->findOneByIdentifier('nonexistent');

        self::assertNull($model);
    }

    #[Test]
    public function modelPropertiesAreCorrect(): void
    {
        $model = $this->repository->findByUid(1);

        self::assertNotNull($model);
        self::assertEquals('gpt-4o', $model->getIdentifier());
        self::assertEquals('GPT-4o', $model->getName());
        self::assertEquals('OpenAI GPT-4o model', $model->getDescription());
        self::assertEquals('gpt-4o', $model->getModelId());
        self::assertEquals(128000, $model->getContextLength());
        self::assertEquals(4096, $model->getMaxOutputTokens());
        self::assertTrue($model->isActive());
        self::assertTrue($model->isDefault());
    }

    #[Test]
    public function findDefaultReturnsDefaultModel(): void
    {
        $default = $this->repository->findDefault();

        self::assertNotNull($default);
        self::assertTrue($default->isDefault());
        self::assertEquals('gpt-4o', $default->getIdentifier());
    }
}
