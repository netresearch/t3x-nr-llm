<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Functional tests for LlmConfigurationService
 *
 * Tests access control, configuration resolution, and CRUD operations.
 */
#[CoversClass(LlmConfigurationService::class)]
class LlmConfigurationServiceTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationService $subject;
    private LlmConfigurationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = $this->get(LlmConfigurationService::class);
        $this->repository = $this->get(LlmConfigurationRepository::class);

        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('BeUsers.csv');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function setUpAdminUser(): void
    {
        $this->setUpBackendUser(1);
    }

    private function setUpEditorUser(): void
    {
        $this->setUpBackendUser(2);
    }

    #[Test]
    public function getConfigurationReturnsConfigurationForAdmin(): void
    {
        $this->setUpAdminUser();

        $config = $this->subject->getConfiguration('default-config');

        $this->assertInstanceOf(LlmConfiguration::class, $config);
        $this->assertEquals('default-config', $config->getIdentifier());
    }

    #[Test]
    public function getConfigurationThrowsExceptionForNonExistentConfig(): void
    {
        $this->setUpAdminUser();

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionMessage('LLM configuration "non-existent" not found');

        $this->subject->getConfiguration('non-existent');
    }

    #[Test]
    public function getConfigurationThrowsExceptionForInactiveConfig(): void
    {
        $this->setUpAdminUser();

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionMessage('LLM configuration "inactive-config" is not active');

        $this->subject->getConfiguration('inactive-config');
    }

    #[Test]
    public function getConfigurationThrowsAccessDeniedWithoutBackendUser(): void
    {
        // Don't set up any backend user
        unset($GLOBALS['BE_USER']);

        $this->expectException(AccessDeniedException::class);

        $this->subject->getConfiguration('default-config');
    }

    #[Test]
    public function getDefaultConfigurationReturnsDefault(): void
    {
        $this->setUpAdminUser();

        $config = $this->subject->getDefaultConfiguration();

        $this->assertInstanceOf(LlmConfiguration::class, $config);
        $this->assertTrue($config->isDefault());
        $this->assertEquals('default-config', $config->getIdentifier());
    }

    #[Test]
    public function getAccessibleConfigurationsReturnsAllForAdmin(): void
    {
        $this->setUpAdminUser();

        $configs = $this->subject->getAccessibleConfigurations();

        // Should return all active configurations for admin
        $this->assertCount(5, $configs);
    }

    #[Test]
    public function getAccessibleConfigurationsReturnsUnrestrictedForEditor(): void
    {
        $this->setUpEditorUser();

        $configs = $this->subject->getAccessibleConfigurations();

        // Editor should get only unrestricted configurations
        // (those without allowed_groups set)
        foreach ($configs as $config) {
            $this->assertEquals(0, $config->getAllowedGroups());
        }
    }

    #[Test]
    public function getConfigurationsByProviderFiltersCorrectly(): void
    {
        $this->setUpAdminUser();

        $configs = $this->subject->getConfigurationsByProvider('openai');

        $this->assertNotEmpty($configs);
        foreach ($configs as $config) {
            $this->assertEquals('openai', $config->getProvider());
        }
    }

    #[Test]
    public function hasAccessReturnsTrueForAdmin(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findByIdentifier('restricted-config');
        $this->assertNotNull($config);

        $this->assertTrue($this->subject->hasAccess($config));
    }

    #[Test]
    public function hasAccessReturnsTrueForUnrestrictedConfig(): void
    {
        $this->setUpEditorUser();

        $config = $this->repository->findByIdentifier('default-config');
        $this->assertNotNull($config);

        // Default config has no restrictions
        $this->assertTrue($this->subject->hasAccess($config));
    }

    #[Test]
    public function hasAccessReturnsFalseWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $config = $this->repository->findByIdentifier('default-config');
        $this->assertNotNull($config);

        $this->assertFalse($this->subject->hasAccess($config));
    }

    #[Test]
    public function setAsDefaultClearsOtherDefaults(): void
    {
        $this->setUpAdminUser();

        // Get creative config and make it default
        $config = $this->repository->findByIdentifier('creative-config');
        $this->assertNotNull($config);
        $this->assertFalse($config->isDefault());

        $this->subject->setAsDefault($config);

        // Verify the change
        $newDefault = $this->subject->getDefaultConfiguration();
        $this->assertEquals('creative-config', $newDefault->getIdentifier());

        // Verify old default is no longer default
        $oldDefault = $this->repository->findByIdentifier('default-config');
        $this->assertFalse($oldDefault->isDefault());
    }

    #[Test]
    public function toggleActiveChangesStatus(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findByIdentifier('creative-config');
        $this->assertNotNull($config);
        $this->assertTrue($config->isActive());

        $this->subject->toggleActive($config);

        // Reload to verify persistence
        $reloaded = $this->repository->findByIdentifier('creative-config');
        $this->assertFalse($reloaded->isActive());
    }

    #[Test]
    public function createPersistsNewConfiguration(): void
    {
        $this->setUpAdminUser();

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('new-service-config');
        $config->setName('New Service Configuration');
        $config->setProvider('openai');
        $config->setModel('gpt-4o');
        $config->setIsActive(true);

        $this->subject->create($config);

        $retrieved = $this->repository->findByIdentifier('new-service-config');
        $this->assertNotNull($retrieved);
        $this->assertEquals('New Service Configuration', $retrieved->getName());
    }

    #[Test]
    public function updatePersistsChanges(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findByIdentifier('creative-config');
        $this->assertNotNull($config);

        $config->setName('Updated via Service');
        $this->subject->update($config);

        $retrieved = $this->repository->findByIdentifier('creative-config');
        $this->assertEquals('Updated via Service', $retrieved->getName());
    }

    #[Test]
    public function deleteRemovesConfiguration(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findByIdentifier('creative-config');
        $this->assertNotNull($config);

        $this->subject->delete($config);

        $retrieved = $this->repository->findByIdentifier('creative-config');
        $this->assertNull($retrieved);
    }

    #[Test]
    public function isIdentifierAvailableReturnsTrueForNewIdentifier(): void
    {
        $result = $this->subject->isIdentifierAvailable('brand-new-identifier');

        $this->assertTrue($result);
    }

    #[Test]
    public function isIdentifierAvailableReturnsFalseForExistingIdentifier(): void
    {
        $result = $this->subject->isIdentifierAvailable('default-config');

        $this->assertFalse($result);
    }

    #[Test]
    public function isIdentifierAvailableExcludesOwnRecord(): void
    {
        // When editing record with uid 1, its own identifier should be available
        $result = $this->subject->isIdentifierAvailable('default-config', 1);

        $this->assertTrue($result);
    }
}
