<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Service for managing LLM configurations.
 *
 * Provides access control enforcement, usage limit checking,
 * and configuration resolution for LLM operations.
 */
class LlmConfigurationService implements SingletonInterface
{
    public function __construct(
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly Context $context,
    ) {}

    /**
     * Get configuration by identifier with access check.
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    public function getConfiguration(string $identifier): LlmConfiguration
    {
        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);

        if ($configuration === null) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" not found', $identifier),
                8232736809,
            );
        }

        if (!$configuration->isActive()) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" is not active', $identifier),
                2690936773,
            );
        }

        $this->checkAccess($configuration);

        return $configuration;
    }

    /**
     * Get default configuration.
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    public function getDefaultConfiguration(): LlmConfiguration
    {
        $configuration = $this->configurationRepository->findDefault();

        if ($configuration === null) {
            throw new ConfigurationNotFoundException('No default LLM configuration found', 7230464472);
        }

        $this->checkAccess($configuration);

        return $configuration;
    }

    /**
     * Get all configurations accessible to current user.
     *
     * @return array<LlmConfiguration>
     */
    public function getAccessibleConfigurations(): array
    {
        // Admin users can access all configurations
        if ($this->isCurrentUserAdmin()) {
            return $this->configurationRepository->findActive()->toArray();
        }

        // Get user's group IDs
        $groupUids = $this->getCurrentUserGroupIds();

        return $this->configurationRepository->findAccessibleForGroups($groupUids)->toArray();
    }

    /**
     * Check access to configuration.
     *
     * @throws AccessDeniedException
     */
    public function checkAccess(LlmConfiguration $configuration): void
    {
        if (!$this->hasAccess($configuration)) {
            throw new AccessDeniedException(
                sprintf('Access denied to LLM configuration "%s"', $configuration->getIdentifier()),
                9955441896,
            );
        }
    }

    /**
     * Check if current user has access to configuration.
     */
    public function hasAccess(LlmConfiguration $configuration): bool
    {
        // No backend user context: no access
        if (!$this->isBackendUserLoggedIn()) {
            return false;
        }

        // Admin users can access all configurations
        if ($this->isCurrentUserAdmin()) {
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
     * Set a configuration as default.
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
     * Toggle active status.
     */
    public function toggleActive(LlmConfiguration $configuration): void
    {
        $configuration->setIsActive(!$configuration->isActive());
        $this->configurationRepository->update($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Create new configuration.
     */
    public function create(LlmConfiguration $configuration): void
    {
        $this->configurationRepository->add($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Update configuration.
     */
    public function update(LlmConfiguration $configuration): void
    {
        $this->configurationRepository->update($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Delete configuration.
     */
    public function delete(LlmConfiguration $configuration): void
    {
        $this->configurationRepository->remove($configuration);
        $this->persistenceManager->persistAll();
    }

    /**
     * Validate identifier uniqueness.
     */
    public function isIdentifierAvailable(string $identifier, ?int $excludeUid = null): bool
    {
        return $this->configurationRepository->isIdentifierUnique($identifier, $excludeUid);
    }

    /**
     * Check if a backend user is logged in.
     */
    private function isBackendUserLoggedIn(): bool
    {
        try {
            return (bool)$this->context->getAspect('backend.user')->get('isLoggedIn');
        } catch (AspectNotFoundException) {
            return false;
        }
    }

    /**
     * Check if current backend user is admin.
     */
    private function isCurrentUserAdmin(): bool
    {
        try {
            return (bool)$this->context->getAspect('backend.user')->get('isAdmin');
        } catch (AspectNotFoundException) {
            return false;
        }
    }

    /**
     * Get current user's group IDs via Context API.
     *
     * @return array<int>
     */
    private function getCurrentUserGroupIds(): array
    {
        try {
            /** @var array<int> $groupIds */
            $groupIds = $this->context->getAspect('backend.user')->get('groupIds');
            return $groupIds;
        } catch (AspectNotFoundException) {
            return [];
        }
    }

    /**
     * Get allowed group IDs for a configuration.
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
            $uid = $group->getUid();
            if ($uid !== null) {
                $groupIds[] = $uid;
            }
        }

        return $groupIds;
    }
}
