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
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;

/**
 * Resolves the backend-module-managed default configuration for generic
 * (provider-agnostic) completion / chat / stream calls, and named
 * configurations by identifier for user-less contexts (ADR-070).
 *
 * Extracted from {@see LlmServiceManager} so the "which configuration drives
 * this call" decision — and the guards around an access-restricted or
 * model-less default — live in one collaborator that the generic dispatch
 * methods share. The repository is optional so unit constructions that do not
 * exercise the default-configuration path can omit it (matching the manager's
 * former nullable dependency).
 */
final readonly class ConfigurationResolver
{
    public function __construct(
        private ?LlmConfigurationRepository $configurationRepository = null,
    ) {}

    /**
     * Resolve the effective configuration for a configuration-driven completion.
     *
     * Returns the explicitly passed configuration when set, otherwise the
     * backend-module-managed active default — resolved through the very same
     * guards the generic complete()/chat() path uses (see
     * {@see self::resolveDefaultConfiguration()}), so callers never duplicate
     * that logic. Returns null when neither resolves; the caller must then
     * dispatch through the generic path so the existing "no provider specified"
     * error is raised.
     */
    public function resolveEffectiveConfiguration(?LlmConfiguration $configuration = null): ?LlmConfiguration
    {
        return $configuration ?? $this->resolveDefaultConfiguration(null);
    }

    /**
     * Resolve the backend-module-managed default configuration for a generic
     * (provider-agnostic) completion/chat call. Returns null when the caller
     * pinned an explicit provider, when no repository is wired (unit tests), or
     * when no active default configuration exists — in which case the generic
     * path requires a per-call pinned provider and otherwise throws (there is
     * no extension-config default-provider fallback; see ADR-034).
     *
     * Uses the repository directly rather than LlmConfigurationService, whose
     * getDefaultConfiguration() enforces a backend-user access check that the
     * CLI worker (Symfony Messenger consumer) has no user for.
     */
    public function resolveDefaultConfiguration(?string $providerKey): ?LlmConfiguration
    {
        if ($providerKey !== null) {
            return null;
        }

        $configuration = $this->configurationRepository?->findDefault();

        // Treat as "no default" when the default configuration is missing, has no model
        // (getAdapterFromConfiguration() would throw), or is access-restricted to specific BE
        // groups. Returning null here means the generic call needs a per-call pinned provider —
        // otherwise getProvider(null) throws. The generic chat()/complete()/streamChat()
        // path has no backend-user context to enforce group membership against (notably the CLI
        // worker), so an access-restricted default must not be auto-applied to arbitrary callers.
        if ($configuration === null
            || $configuration->getLlmModel() === null
            || $configuration->hasAccessRestrictions()
        ) {
            return null;
        }

        return $configuration;
    }

    /**
     * Resolve an active configuration by its identifier for user-less contexts
     * (CLI, Symfony Messenger consumers, anonymous frontend requests).
     *
     * Counterpart of LlmConfigurationServiceInterface::getConfiguration(),
     * which enforces a backend-user access check that user-less callers cannot
     * satisfy. Applies the same refusal policy as
     * {@see self::resolveDefaultConfiguration()}: an access-restricted
     * configuration is not resolvable without a user to check the BE group
     * membership against (ADR-070). Unlike the default path this does not
     * require a directly assigned model — criteria-mode configurations resolve
     * their model at call time via the *ForConfiguration() entry points
     * (ADR-066).
     *
     * @throws ConfigurationNotFoundException when no configuration with the identifier exists (or no repository is wired)
     * @throws ConfigurationInactiveException when the configuration exists but is deactivated
     * @throws AccessDeniedException          when the configuration is restricted to BE groups (ADR-070)
     */
    public function getActiveByIdentifier(string $identifier): LlmConfiguration
    {
        $configuration = $this->configurationRepository?->findOneByIdentifier($identifier);

        if ($configuration === null) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" not found', $identifier),
                1784211001,
            );
        }

        if (!$configuration->isActive()) {
            throw new ConfigurationInactiveException(
                sprintf('LLM configuration "%s" is not active', $identifier),
                1784211002,
            );
        }

        if ($configuration->hasAccessRestrictions()) {
            throw new AccessDeniedException(
                sprintf(
                    'LLM configuration "%s" is restricted to backend groups'
                    . ' and cannot be resolved without a user context',
                    $identifier,
                ),
                1784211003,
            );
        }

        return $configuration;
    }
}
