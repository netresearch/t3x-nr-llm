<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Functional tests for LlmConfigurationRepository
 *
 * Tests database queries and persistence operations.
 */
#[CoversClass(LlmConfigurationRepository::class)]
class LlmConfigurationRepositoryTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationRepository $subject;
    private PersistenceManager $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = $this->get(LlmConfigurationRepository::class);
        $this->persistenceManager = $this->get(PersistenceManager::class);

        $this->importFixture('LlmConfigurations.csv');
    }

    #[Test]
    public function findAllReturnsAllNonDeletedConfigurations(): void
    {
        $result = $this->subject->findAll();

        $this->assertCount(6, $result);
    }

    #[Test]
    public function findByIdentifierReturnsMatchingConfiguration(): void
    {
        $result = $this->subject->findByIdentifier('default-config');

        $this->assertInstanceOf(LlmConfiguration::class, $result);
        $this->assertEquals('default-config', $result->getIdentifier());
        $this->assertEquals('Default Configuration', $result->getName());
        $this->assertEquals('openai', $result->getProvider());
        $this->assertEquals('gpt-4o', $result->getModel());
    }

    #[Test]
    public function findByIdentifierReturnsNullForNonExistentIdentifier(): void
    {
        $result = $this->subject->findByIdentifier('non-existent');

        $this->assertNull($result);
    }

    #[Test]
    public function findActiveReturnsOnlyActiveConfigurations(): void
    {
        $result = $this->subject->findActive();

        $this->assertCount(5, $result);

        foreach ($result as $config) {
            $this->assertTrue($config->isActive());
        }
    }

    #[Test]
    public function findDefaultReturnsDefaultConfiguration(): void
    {
        $result = $this->subject->findDefault();

        $this->assertInstanceOf(LlmConfiguration::class, $result);
        $this->assertTrue($result->isDefault());
        $this->assertEquals('default-config', $result->getIdentifier());
    }

    #[Test]
    public function findByProviderReturnsConfigurationsForProvider(): void
    {
        $result = $this->subject->findByProvider('openai');

        $this->assertCount(4, $result);

        foreach ($result as $config) {
            $this->assertEquals('openai', $config->getProvider());
            $this->assertTrue($config->isActive());
        }
    }

    #[Test]
    public function findByProviderReturnsEmptyForUnknownProvider(): void
    {
        $result = $this->subject->findByProvider('unknown-provider');

        $this->assertCount(0, $result);
    }

    #[Test]
    public function findAccessibleForGroupsReturnsUnrestrictedConfigurationsForEmptyGroups(): void
    {
        $result = $this->subject->findAccessibleForGroups([]);

        // Should return configurations without access restrictions (allowedGroups = 0)
        foreach ($result as $config) {
            $this->assertEquals(0, $config->getAllowedGroups());
            $this->assertTrue($config->isActive());
        }
    }

    #[Test]
    public function isIdentifierUniqueReturnsTrueForNewIdentifier(): void
    {
        $result = $this->subject->isIdentifierUnique('brand-new-identifier');

        $this->assertTrue($result);
    }

    #[Test]
    public function isIdentifierUniqueReturnsFalseForExistingIdentifier(): void
    {
        $result = $this->subject->isIdentifierUnique('default-config');

        $this->assertFalse($result);
    }

    #[Test]
    public function isIdentifierUniqueReturnsTrueWhenExcludingSameRecord(): void
    {
        $result = $this->subject->isIdentifierUnique('default-config', 1);

        $this->assertTrue($result);
    }

    #[Test]
    public function configurationCanBeCreatedAndPersisted(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setPid(0);
        $configuration->setIdentifier('new-test-config');
        $configuration->setName('New Test Configuration');
        $configuration->setProvider('claude');
        $configuration->setModel('claude-sonnet-4-20250514');
        $configuration->setTemperature(0.8);
        $configuration->setMaxTokens(2000);
        $configuration->setIsActive(true);

        $this->subject->add($configuration);
        $this->persistenceManager->persistAll();

        // Verify it was persisted
        $retrieved = $this->subject->findByIdentifier('new-test-config');

        $this->assertInstanceOf(LlmConfiguration::class, $retrieved);
        $this->assertEquals('New Test Configuration', $retrieved->getName());
        $this->assertEquals('claude', $retrieved->getProvider());
        $this->assertEquals(0.8, $retrieved->getTemperature());
    }

    #[Test]
    public function configurationCanBeUpdated(): void
    {
        $configuration = $this->subject->findByIdentifier('creative-config');
        $this->assertNotNull($configuration);

        $configuration->setName('Updated Creative Config');
        $configuration->setTemperature(0.95);

        $this->subject->update($configuration);
        $this->persistenceManager->persistAll();

        // Clear identity map to force reload
        $this->persistenceManager->clearState();

        $retrieved = $this->subject->findByIdentifier('creative-config');
        $this->assertEquals('Updated Creative Config', $retrieved->getName());
        $this->assertEquals(0.95, $retrieved->getTemperature());
    }

    #[Test]
    public function unsetAllDefaultsClearsDefaultFlag(): void
    {
        // Verify there is a default
        $defaultBefore = $this->subject->findDefault();
        $this->assertNotNull($defaultBefore);

        $this->subject->unsetAllDefaults();
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // After clearing, no default should exist
        $defaultAfter = $this->subject->findDefault();
        $this->assertNull($defaultAfter);
    }

    #[Test]
    public function configurationWithUsageLimitsIsDetected(): void
    {
        $config = $this->subject->findByIdentifier('creative-config');
        $this->assertNotNull($config);

        $this->assertTrue($config->hasUsageLimits());
        $this->assertEquals(100, $config->getMaxRequestsPerDay());
        $this->assertEquals(50000, $config->getMaxTokensPerDay());
        $this->assertEquals(5.00, $config->getMaxCostPerDay());
    }

    #[Test]
    public function configurationWithoutUsageLimitsIsDetected(): void
    {
        $config = $this->subject->findByIdentifier('default-config');
        $this->assertNotNull($config);

        $this->assertFalse($config->hasUsageLimits());
    }

    #[Test]
    public function configurationWithAccessRestrictionsIsDetected(): void
    {
        $config = $this->subject->findByIdentifier('restricted-config');
        $this->assertNotNull($config);

        $this->assertTrue($config->hasAccessRestrictions());
        $this->assertEquals(1, $config->getAllowedGroups());
    }

    #[Test]
    public function configurationToChatOptionsConversion(): void
    {
        $config = $this->subject->findByIdentifier('creative-config');
        $this->assertNotNull($config);

        $chatOptions = $config->toChatOptions();

        $this->assertEquals(0.90, $chatOptions->temperature);
        $this->assertEquals(2000, $chatOptions->maxTokens);
        $this->assertEquals(0.95, $chatOptions->topP);
        $this->assertEquals(0.50, $chatOptions->frequencyPenalty);
        $this->assertEquals(0.50, $chatOptions->presencePenalty);
        $this->assertEquals('claude', $chatOptions->provider);
        $this->assertEquals('claude-sonnet-4-20250514', $chatOptions->model);
    }

    #[Test]
    public function configurationToOptionsArrayConversion(): void
    {
        $config = $this->subject->findByIdentifier('code-review');
        $this->assertNotNull($config);

        $options = $config->toOptionsArray();

        $this->assertArrayHasKey('temperature', $options);
        $this->assertArrayHasKey('max_tokens', $options);
        $this->assertArrayHasKey('provider', $options);
        $this->assertArrayHasKey('model', $options);
        $this->assertArrayHasKey('system_prompt', $options);

        $this->assertEquals(0.30, $options['temperature']);
        $this->assertEquals(4000, $options['max_tokens']);
        $this->assertEquals('openai', $options['provider']);
    }

    #[Test]
    public function translatorConfigurationIsLoaded(): void
    {
        $config = $this->subject->findByIdentifier('translation-de');
        $this->assertNotNull($config);

        $this->assertEquals('deepl', $config->getTranslator());

        $options = $config->toOptionsArray();
        $this->assertEquals('deepl', $options['translator']);
    }

    #[Test]
    public function systemPromptIsLoaded(): void
    {
        $config = $this->subject->findByIdentifier('default-config');
        $this->assertNotNull($config);

        $this->assertEquals('You are a helpful assistant.', $config->getSystemPrompt());
    }

    #[Test]
    public function jsonOptionsAreParsed(): void
    {
        $config = $this->subject->findByIdentifier('code-review');
        $this->assertNotNull($config);

        $optionsArray = $config->getOptionsArray();
        $this->assertIsArray($optionsArray);
        $this->assertArrayHasKey('response_format', $optionsArray);
        $this->assertEquals('json', $optionsArray['response_format']);
    }

    #[Test]
    public function emptyOptionsReturnEmptyArray(): void
    {
        // Create a configuration with empty options
        $configuration = new LlmConfiguration();
        $configuration->setOptions('');

        $this->assertEquals([], $configuration->getOptionsArray());
    }

    #[Test]
    public function temperatureIsClampedToValidRange(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setTemperature(3.0);
        $this->assertEquals(2.0, $configuration->getTemperature());

        $configuration->setTemperature(-1.0);
        $this->assertEquals(0.0, $configuration->getTemperature());
    }

    #[Test]
    public function topPIsClampedToValidRange(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setTopP(1.5);
        $this->assertEquals(1.0, $configuration->getTopP());

        $configuration->setTopP(-0.5);
        $this->assertEquals(0.0, $configuration->getTopP());
    }

    #[Test]
    public function frequencyPenaltyIsClampedToValidRange(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setFrequencyPenalty(3.0);
        $this->assertEquals(2.0, $configuration->getFrequencyPenalty());

        $configuration->setFrequencyPenalty(-3.0);
        $this->assertEquals(-2.0, $configuration->getFrequencyPenalty());
    }

    #[Test]
    public function maxTokensIsAtLeastOne(): void
    {
        $configuration = new LlmConfiguration();

        $configuration->setMaxTokens(0);
        $this->assertEquals(1, $configuration->getMaxTokens());

        $configuration->setMaxTokens(-100);
        $this->assertEquals(1, $configuration->getMaxTokens());
    }
}
