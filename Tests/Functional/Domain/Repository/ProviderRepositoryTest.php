<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for ProviderRepository.
 */
final class ProviderRepositoryTest extends AbstractFunctionalTestCase
{
    private ProviderRepository $repository;
    private PersistenceManagerInterface $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');

        $this->repository = $this->get(ProviderRepository::class);
        $this->persistenceManager = $this->get(PersistenceManagerInterface::class);
    }

    #[Test]
    public function findAllReturnsActiveProviders(): void
    {
        $providers = $this->repository->findAll();

        self::assertNotEmpty($providers);
        // Should find active providers (uid 1 and 2), not deleted (uid 3)
        foreach ($providers as $provider) {
            self::assertInstanceOf(Provider::class, $provider);
        }
    }

    #[Test]
    public function findByUidReturnsProvider(): void
    {
        $provider = $this->repository->findByUid(1);

        self::assertNotNull($provider);
        self::assertInstanceOf(Provider::class, $provider);
        self::assertEquals('ollama-local', $provider->getIdentifier());
    }

    #[Test]
    public function findByUidReturnsNullForNonexistent(): void
    {
        $provider = $this->repository->findByUid(99999);

        self::assertNull($provider);
    }

    #[Test]
    public function findByUidReturnsNullForDeletedProvider(): void
    {
        // UID 3 is marked as deleted in fixture
        $provider = $this->repository->findByUid(3);

        self::assertNull($provider);
    }

    #[Test]
    public function findActiveReturnsOnlyActiveProviders(): void
    {
        $providers = $this->repository->findActive();

        self::assertNotEmpty($providers);
        foreach ($providers as $provider) {
            self::assertTrue($provider->isActive());
        }
    }

    #[Test]
    public function toggleActivePersistsChange(): void
    {
        $provider = $this->repository->findByUid(1);
        self::assertNotNull($provider);
        self::assertTrue($provider->isActive());

        // Toggle to inactive
        $provider->setIsActive(false);
        $this->repository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Reload and verify
        $reloaded = $this->repository->findByUid(1);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function findByIdentifierReturnsProvider(): void
    {
        $provider = $this->repository->findOneByIdentifier('ollama-local');

        self::assertNotNull($provider);
        self::assertEquals(1, $provider->getUid());
    }

    #[Test]
    public function findByIdentifierReturnsNullForNonexistent(): void
    {
        $provider = $this->repository->findOneByIdentifier('nonexistent');

        self::assertNull($provider);
    }

    #[Test]
    public function providerPropertiesAreCorrect(): void
    {
        $provider = $this->repository->findByUid(1);

        self::assertEquals('ollama-local', $provider->getIdentifier());
        self::assertEquals('Local Ollama', $provider->getName());
        self::assertEquals('Local Ollama server for testing', $provider->getDescription());
        self::assertEquals('ollama', $provider->getAdapterType());
        self::assertEquals('http://ollama:11434', $provider->getEndpointUrl());
        self::assertEquals(30, $provider->getTimeout());
        self::assertEquals(3, $provider->getMaxRetries());
        self::assertTrue($provider->isActive());
    }
}
