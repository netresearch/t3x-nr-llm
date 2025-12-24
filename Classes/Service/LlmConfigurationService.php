<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Service for managing LLM configurations
 *
 * Provides access control enforcement, usage limit checking,
 * and configuration resolution for LLM operations.
 */
class LlmConfigurationService implements SingletonInterface
{
    public function __construct(
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {}

    /**
     * Get configuration by identifier with access check
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    public function getConfiguration(string $identifier): LlmConfiguration
    {
        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);

        if ($configuration === null) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" not found', $identifier)
            );
        }

        if (!$configuration->isActive()) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" is not active', $identifier)
            );
        }

        $this->checkAccess($configuration);

        return $configuration;
    }

    /**
     * Get default configuration
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    public function getDefaultConfiguration(): LlmConfiguration
    {
        $configuration = $this->configurationRepository->findDefault();

        if ($configuration === null) {
            throw new ConfigurationNotFoundException('No default LLM configuration found');
        }

        $this->checkAccess($configuration);

        return $configuration;
    }

    /**
     * Get all configurations accessible to current user
     *
     * @return array<LlmConfiguration>
     */
    public function getAccessibleConfigurations(): array
    {
        $backendUser = $this->getBackendUser();

        // Admin users can access all configurations
        if ($backendUser !== null && $backendUser->isAdmin()) {
            return $this->configurationRepository->findActive()->toArray();
        }

        // Get user's group IDs
        $groupUids = $this->getCurrentUserGroupIds();

        return $this->configurationRepository->findAccessibleForGroups($groupUids)->toArray();
    }

    /**
     * Get configurations by provider
     *
     * @return array<LlmConfiguration>
     */
    public function getConfigurationsByProvider(string $provider): array
    {
        $configurations = $this->configurationRepository->findByProvider($provider)->toArray();

        // Filter by access
        return array_filter($configurations, fn(LlmConfiguration $config) => $this->hasAccess($config));
    }

    /**
     * Check access to configuration
     *
     * @throws AccessDeniedException
     */
    public function checkAccess(LlmConfiguration $configuration): void
    {
        if (!$this->hasAccess($configuration)) {
            throw new AccessDeniedException(
                sprintf('Access denied to LLM configuration "%s"', $configuration->getIdentifier())
            );
        }
    }

    /**
     * Check if current user has access to configuration
     */
    public function hasAccess(LlmConfiguration $configuration): bool
    {
        $backendUser = $this->getBackendUser();

        // No backend user context: no access
        if ($backendUser === null) {
            return false;
        }

        // Admin users can access all configurations
        if ($backendUser->isAdmin()) {
            return true;
        }

        // No restrictions: accessible to all
        if (!$configuration->hasAccessRestrictions()) {
            return true;
        }

        // Check group membership
        $userGroupIds = $this->getCurrentUserGroupIds();
        $allowedGroupIds = $this->getConfigurationGroupIds($configuration);

        // Check if user is in any allowed group
        return !empty(array_intersect($userGroupIds, $allowedGroupIds));
    }

    /**
     * Set a configuration as default
     */
    public function setAsDefault(LlmConfiguration $configuration): void
    {
        // Unset all other defaults
        $this->configurationRepository->unsetAllDefaults();

        // Set this one as default
        $configuration->setIsDefault(true);
        $this->configurationRepository->update($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Toggle active status
     */
    public function toggleActive(LlmConfiguration $configuration): void
    {
        $configuration->setIsActive(!$configuration->isActive());
        $this->configurationRepository->update($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Create new configuration
     */
    public function create(LlmConfiguration $configuration): void
    {
        $this->configurationRepository->add($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Update configuration
     */
    public function update(LlmConfiguration $configuration): void
    {
        $this->configurationRepository->update($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Delete configuration
     */
    public function delete(LlmConfiguration $configuration): void
    {
        $this->configurationRepository->remove($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Validate identifier uniqueness
     */
    public function isIdentifierAvailable(string $identifier, ?int $excludeUid = null): bool
    {
        return $this->configurationRepository->isIdentifierUnique($identifier, $excludeUid);
    }

    /**
     * Get current backend user
     */
    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }

    /**
     * Get current user's group IDs
     *
     * @return array<int>
     */
    private function getCurrentUserGroupIds(): array
    {
        $backendUser = $this->getBackendUser();

        if ($backendUser === null) {
            return [];
        }

        $groupList = $backendUser->groupList ?? '';
        if ($groupList === '') {
            return [];
        }

        return array_map('intval', explode(',', $groupList));
    }

    /**
     * Get allowed group IDs for a configuration
     *
     * @return array<int>
     */
    private function getConfigurationGroupIds(LlmConfiguration $configuration): array
    {
        $beGroups = $configuration->getBeGroups();
        if ($beGroups === null) {
            return [];
        }

        $groupIds = [];
        foreach ($beGroups as $group) {
            $groupIds[] = $group->getUid();
        }

        return $groupIds;
    }
}
