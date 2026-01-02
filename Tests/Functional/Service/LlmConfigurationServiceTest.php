<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for LlmConfigurationService.
 *
 * Tests access control, configuration resolution, and CRUD operations.
 */
#[CoversClass(LlmConfigurationService::class)]
class LlmConfigurationServiceTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationService $subject;
    private LlmConfigurationRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        /** @var LlmConfigurationService $subject */
        $subject = $this->get(LlmConfigurationService::class);
        $this->subject = $subject;

        /** @var LlmConfigurationRepository $repository */
        $repository = $this->get(LlmConfigurationRepository::class);
        $this->repository = $repository;

        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('BeUsers.csv');
    }

    #[Override]
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

        self::assertInstanceOf(LlmConfiguration::class, $config);
        self::assertEquals('default-config', $config->getIdentifier());
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

        self::assertInstanceOf(LlmConfiguration::class, $config);
        self::assertTrue($config->isDefault());
        self::assertEquals('default-config', $config->getIdentifier());
    }

    #[Test]
    public function getAccessibleConfigurationsReturnsAllForAdmin(): void
    {
        $this->setUpAdminUser();

        $configs = $this->subject->getAccessibleConfigurations();

        // Should return all active configurations for admin
        self::assertCount(6, $configs);
    }

    #[Test]
    public function getAccessibleConfigurationsReturnsUnrestrictedForEditor(): void
    {
        $this->setUpEditorUser();

        $configs = $this->subject->getAccessibleConfigurations();

        // Editor should get only unrestricted configurations
        // (those without allowed_groups set)
        foreach ($configs as $config) {
            self::assertEquals(0, $config->getAllowedGroups());
        }
    }

    #[Test]
    public function hasAccessReturnsTrueForAdmin(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findOneByIdentifier('restricted-config');
        self::assertNotNull($config);

        self::assertTrue($this->subject->hasAccess($config));
    }

    #[Test]
    public function hasAccessReturnsTrueForUnrestrictedConfig(): void
    {
        $this->setUpEditorUser();

        $config = $this->repository->findOneByIdentifier('default-config');
        self::assertNotNull($config);

        // Default config has no restrictions
        self::assertTrue($this->subject->hasAccess($config));
    }

    #[Test]
    public function hasAccessReturnsFalseWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $config = $this->repository->findOneByIdentifier('default-config');
        self::assertNotNull($config);

        self::assertFalse($this->subject->hasAccess($config));
    }

    #[Test]
    public function setAsDefaultClearsOtherDefaults(): void
    {
        $this->setUpAdminUser();

        // Get creative config and make it default
        $config = $this->repository->findOneByIdentifier('creative-config');
        self::assertNotNull($config);
        self::assertFalse($config->isDefault());

        $this->subject->setAsDefault($config);

        // Verify the change
        $newDefault = $this->subject->getDefaultConfiguration();
        self::assertEquals('creative-config', $newDefault->getIdentifier());

        // Verify old default is no longer default
        $oldDefault = $this->repository->findOneByIdentifier('default-config');
        self::assertNotNull($oldDefault);
        self::assertFalse($oldDefault->isDefault());
    }

    #[Test]
    public function toggleActiveChangesStatus(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findOneByIdentifier('creative-config');
        self::assertNotNull($config);
        self::assertTrue($config->isActive());

        $this->subject->toggleActive($config);

        // Reload to verify persistence
        $reloaded = $this->repository->findOneByIdentifier('creative-config');
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function createPersistsNewConfiguration(): void
    {
        $this->setUpAdminUser();

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('new-service-config');
        $config->setName('New Service Configuration');
        $config->setIsActive(true);

        $this->subject->create($config);

        $retrieved = $this->repository->findOneByIdentifier('new-service-config');
        self::assertNotNull($retrieved);
        self::assertEquals('New Service Configuration', $retrieved->getName());
    }

    #[Test]
    public function updatePersistsChanges(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findOneByIdentifier('creative-config');
        self::assertNotNull($config);

        $config->setName('Updated via Service');
        $this->subject->update($config);

        $retrieved = $this->repository->findOneByIdentifier('creative-config');
        self::assertNotNull($retrieved);
        self::assertEquals('Updated via Service', $retrieved->getName());
    }

    #[Test]
    public function deleteRemovesConfiguration(): void
    {
        $this->setUpAdminUser();

        $config = $this->repository->findOneByIdentifier('creative-config');
        self::assertNotNull($config);

        $this->subject->delete($config);

        $retrieved = $this->repository->findOneByIdentifier('creative-config');
        self::assertNull($retrieved);
    }

    #[Test]
    public function isIdentifierAvailableReturnsTrueForNewIdentifier(): void
    {
        $result = $this->subject->isIdentifierAvailable('brand-new-identifier');

        self::assertTrue($result);
    }

    #[Test]
    public function isIdentifierAvailableReturnsFalseForExistingIdentifier(): void
    {
        $result = $this->subject->isIdentifierAvailable('default-config');

        self::assertFalse($result);
    }

    #[Test]
    public function isIdentifierAvailableExcludesOwnRecord(): void
    {
        // When editing record with uid 1, its own identifier should be available
        $result = $this->subject->isIdentifierAvailable('default-config', 1);

        self::assertTrue($result);
    }
}
