<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;

/**
 * Public surface of the LLM-configuration service.
 *
 * Consumers (controllers, feature services, tests) should depend on this
 * interface rather than the concrete `LlmConfigurationService` so the
 * implementation can be substituted without inheritance.
 */
interface LlmConfigurationServiceInterface
{
    /**
     * Get configuration by identifier with access check.
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    public function getConfiguration(string $identifier): LlmConfiguration;

    /**
     * Get the default configuration with access check.
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    public function getDefaultConfiguration(): LlmConfiguration;

    /**
     * Get all configurations accessible to the current backend user.
     *
     * Admin users see every active configuration; non-admin users see
     * only configurations whose access-restriction groups intersect with
     * their own group memberships.
     *
     * @return array<LlmConfiguration>
     */
    public function getAccessibleConfigurations(): array;

    /**
     * Throw if the current user has no access to the given configuration.
     *
     * @throws AccessDeniedException
     */
    public function checkAccess(LlmConfiguration $configuration): void;

    /**
     * Check whether the current user has access to the given configuration.
     */
    public function hasAccess(LlmConfiguration $configuration): bool;

    /**
     * Mark the given configuration as the default (unsets all others).
     */
    public function setAsDefault(LlmConfiguration $configuration): void;

    /**
     * Toggle the active flag on the given configuration.
     */
    public function toggleActive(LlmConfiguration $configuration): void;

    /**
     * Persist a new configuration.
     */
    public function create(LlmConfiguration $configuration): void;

    /**
     * Persist updates to an existing configuration.
     */
    public function update(LlmConfiguration $configuration): void;

    /**
     * Remove a configuration from storage.
     */
    public function delete(LlmConfiguration $configuration): void;

    /**
     * Check whether the identifier is available (optionally excluding a uid).
     */
    public function isIdentifierAvailable(string $identifier, ?int $excludeUid = null): bool;
}
