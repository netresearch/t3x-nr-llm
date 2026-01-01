<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Functional tests for LlmConfigurationRepository.
 *
 * Tests database queries and persistence operations.
 */
#[CoversClass(LlmConfigurationRepository::class)]
class LlmConfigurationRepositoryTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationRepository $subject;
    private PersistenceManager $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        /** @var LlmConfigurationRepository $subject */
        $subject = $this->get(LlmConfigurationRepository::class);
        $this->subject = $subject;

        /** @var PersistenceManager $persistenceManager */
        $persistenceManager = $this->get(PersistenceManager::class);
        $this->persistenceManager = $persistenceManager;

        // Import providers and models first since configurations reference them
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
    }

    #[Test]
    public function findAllReturnsAllNonDeletedConfigurations(): void
    {
        $result = $this->subject->findAll();

        self::assertCount(6, $result);
    }

    #[Test]
    public function findOneByIdentifierReturnsMatchingConfiguration(): void
    {
        $result = $this->subject->findOneByIdentifier('default-config');

        self::assertInstanceOf(LlmConfiguration::class, $result);
        self::assertEquals('default-config', $result->getIdentifier());
        self::assertEquals('Default Configuration', $result->getName());
        self::assertNotNull($result->getLlmModel());
        self::assertEquals('gpt-4o', $result->getModelId());
    }

    #[Test]
    public function findOneByIdentifierReturnsNullForNonExistentIdentifier(): void
    {
        $result = $this->subject->findOneByIdentifier('non-existent');

        self::assertNull($result);
    }

    #[Test]
    public function findActiveReturnsOnlyActiveConfigurations(): void
    {
        $result = $this->subject->findActive();

        self::assertCount(5, $result);

        foreach ($result as $config) {
            self::assertTrue($config->isActive());
        }
    }

    #[Test]
    public function findDefaultReturnsDefaultConfiguration(): void
    {
        $result = $this->subject->findDefault();

        self::assertInstanceOf(LlmConfiguration::class, $result);
        self::assertTrue($result->isDefault());
        self::assertEquals('default-config', $result->getIdentifier());
    }

    #[Test]
    public function findAccessibleForGroupsReturnsUnrestrictedConfigurationsForEmptyGroups(): void
    {
        $result = $this->subject->findAccessibleForGroups([]);

        // Should return configurations without access restrictions (allowedGroups = 0)
        foreach ($result as $config) {
            self::assertEquals(0, $config->getAllowedGroups());
            self::assertTrue($config->isActive());
        }
    }

    #[Test]
    public function isIdentifierUniqueReturnsTrueForNewIdentifier(): void
    {
        $result = $this->subject->isIdentifierUnique('brand-new-identifier');

        self::assertTrue($result);
    }

    #[Test]
    public function isIdentifierUniqueReturnsFalseForExistingIdentifier(): void
    {
        $result = $this->subject->isIdentifierUnique('default-config');

        self::assertFalse($result);
    }

    #[Test]
    public function isIdentifierUniqueReturnsTrueWhenExcludingSameRecord(): void
    {
        $result = $this->subject->isIdentifierUnique('default-config', 1);

        self::assertTrue($result);
    }

    #[Test]
    public function configurationCanBeCreatedAndPersisted(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setPid(0);
        $configuration->setIdentifier('new-test-config');
        $configuration->setName('New Test Configuration');
        $configuration->setTemperature(0.8);
        $configuration->setMaxTokens(2000);
        $configuration->setIsActive(true);

        $this->subject->add($configuration);
        $this->persistenceManager->persistAll();

        // Verify it was persisted
        $retrieved = $this->subject->findOneByIdentifier('new-test-config');

        self::assertInstanceOf(LlmConfiguration::class, $retrieved);
        self::assertEquals('New Test Configuration', $retrieved->getName());
        self::assertEquals(0.8, $retrieved->getTemperature());
    }

    #[Test]
    public function configurationCanBeUpdated(): void
    {
        $configuration = $this->subject->findOneByIdentifier('creative-config');
        self::assertNotNull($configuration);

        $configuration->setName('Updated Creative Config');
        $configuration->setTemperature(0.95);

        $this->subject->update($configuration);
        $this->persistenceManager->persistAll();

        // Clear identity map to force reload
        $this->persistenceManager->clearState();

        $retrieved = $this->subject->findOneByIdentifier('creative-config');
        self::assertNotNull($retrieved);
        self::assertEquals('Updated Creative Config', $retrieved->getName());
        self::assertEquals(0.95, $retrieved->getTemperature());
    }

    #[Test]
    public function unsetAllDefaultsClearsDefaultFlag(): void
    {
        // Verify there is a default
        $defaultBefore = $this->subject->findDefault();
        self::assertNotNull($defaultBefore);

        $this->subject->unsetAllDefaults();
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // After clearing, no default should exist
        $defaultAfter = $this->subject->findDefault();
        self::assertNull($defaultAfter);
    }

    #[Test]
    public function configurationWithUsageLimitsIsDetected(): void
    {
        $config = $this->subject->findOneByIdentifier('creative-config');
        self::assertNotNull($config);

        self::assertTrue($config->hasUsageLimits());
        self::assertEquals(100, $config->getMaxRequestsPerDay());
        self::assertEquals(50000, $config->getMaxTokensPerDay());
        self::assertEquals(5.00, $config->getMaxCostPerDay());
    }

    #[Test]
    public function configurationWithoutUsageLimitsIsDetected(): void
    {
        $config = $this->subject->findOneByIdentifier('default-config');
        self::assertNotNull($config);

        self::assertFalse($config->hasUsageLimits());
    }

    #[Test]
    public function configurationWithAccessRestrictionsIsDetected(): void
    {
        $config = $this->subject->findOneByIdentifier('restricted-config');
        self::assertNotNull($config);

        self::assertTrue($config->hasAccessRestrictions());
        self::assertEquals(1, $config->getAllowedGroups());
    }

    #[Test]
    public function configurationToChatOptionsConversion(): void
    {
        $config = $this->subject->findOneByIdentifier('creative-config');
        self::assertNotNull($config);

        $chatOptions = $config->toChatOptions();

        self::assertEquals(0.90, $chatOptions->getTemperature());
        self::assertEquals(2000, $chatOptions->getMaxTokens());
        self::assertEquals(0.95, $chatOptions->getTopP());
        self::assertEquals(0.50, $chatOptions->getFrequencyPenalty());
        self::assertEquals(0.50, $chatOptions->getPresencePenalty());
        // creative-config uses model_uid=3 which is llama3 from the fixtures
        self::assertEquals('llama3:latest', $chatOptions->getModel());
    }

    #[Test]
    public function configurationToOptionsArrayConversion(): void
    {
        $config = $this->subject->findOneByIdentifier('code-review');
        self::assertNotNull($config);

        $options = $config->toOptionsArray();

        self::assertArrayHasKey('temperature', $options);
        self::assertArrayHasKey('max_tokens', $options);
        self::assertArrayHasKey('model', $options);
        self::assertArrayHasKey('system_prompt', $options);

        self::assertEquals(0.30, $options['temperature']);
        self::assertEquals(4000, $options['max_tokens']);
        // code-review uses model_uid=1 which is gpt-4o from fixtures
        self::assertEquals('gpt-4o', $options['model']);
    }

    #[Test]
    public function translatorConfigurationIsLoaded(): void
    {
        $config = $this->subject->findOneByIdentifier('translation-de');
        self::assertNotNull($config);

        self::assertEquals('deepl', $config->getTranslator());

        $options = $config->toOptionsArray();
        self::assertEquals('deepl', $options['translator']);
    }

    #[Test]
    public function systemPromptIsLoaded(): void
    {
        $config = $this->subject->findOneByIdentifier('default-config');
        self::assertNotNull($config);

        self::assertEquals('You are a helpful assistant.', $config->getSystemPrompt());
    }

    #[Test]
    public function jsonOptionsAreParsed(): void
    {
        $config = $this->subject->findOneByIdentifier('code-review');
        self::assertNotNull($config);

        $optionsArray = $config->getOptionsArray();
        self::assertArrayHasKey('response_format', $optionsArray);
        self::assertEquals('json', $optionsArray['response_format']);
    }

    #[Test]
    public function emptyOptionsReturnEmptyArray(): void
    {
        // Create a configuration with empty options
        $configuration = new LlmConfiguration();
        $configuration->setOptions('');

        self::assertEquals([], $configuration->getOptionsArray());
    }

    #[Test]
    public function temperatureIsClampedToValidRange(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setTemperature(3.0);
        self::assertEquals(2.0, $configuration->getTemperature());

        $configuration->setTemperature(-1.0);
        self::assertEquals(0.0, $configuration->getTemperature());
    }

    #[Test]
    public function topPIsClampedToValidRange(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setTopP(1.5);
        self::assertEquals(1.0, $configuration->getTopP());

        $configuration->setTopP(-0.5);
        self::assertEquals(0.0, $configuration->getTopP());
    }

    #[Test]
    public function frequencyPenaltyIsClampedToValidRange(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setFrequencyPenalty(3.0);
        self::assertEquals(2.0, $configuration->getFrequencyPenalty());

        $configuration->setFrequencyPenalty(-3.0);
        self::assertEquals(-2.0, $configuration->getFrequencyPenalty());
    }

    #[Test]
    public function maxTokensIsAtLeastOne(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setMaxTokens(0);
        self::assertEquals(1, $configuration->getMaxTokens());

        $configuration->setMaxTokens(-100);
        self::assertEquals(1, $configuration->getMaxTokens());
    }
}
