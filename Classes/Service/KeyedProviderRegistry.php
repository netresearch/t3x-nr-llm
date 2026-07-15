<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Exception;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Holds the keyed, ExtensionConfiguration-backed provider registry.
 *
 * Extracted from {@see LlmServiceManager} (which now delegates its
 * provider-management methods here). Keeps the mutable map of registered
 * providers plus the loaded extension configuration, so — like the manager
 * before it — this is a {@see SingletonInterface}: providers are registered
 * once at container build time (via `ProviderCompilerPass`, which adds
 * `registerProvider` method calls on the manager, which forward here) and read
 * back for the lifetime of the request.
 *
 * This is the legacy keyed path: providers are looked up by their string
 * identifier and configured from the `nr_llm` extension configuration. The
 * database-backed adapter path (Provider/Model entities) lives elsewhere on
 * the manager.
 */
final class KeyedProviderRegistry implements SingletonInterface
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /** @var array<string, mixed> */
    private array $configuration = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadConfiguration();
    }

    public function registerProvider(ProviderInterface $provider): void
    {
        $identifier = $provider->getIdentifier();
        $this->providers[$identifier] = $provider;

        // Configure provider if configuration exists
        /** @var array<string, array<string, mixed>> $providers */
        $providers = is_array($this->configuration['providers'] ?? null) ? $this->configuration['providers'] : [];
        $providerConfig = $providers[$identifier] ?? [];
        if ($providerConfig !== []) {
            $provider->configure($providerConfig);
        }

        $this->logger->debug('Registered LLM provider', ['provider' => $identifier]);
    }

    public function getProvider(?string $identifier = null): ProviderInterface
    {
        if ($identifier === null) {
            throw new ProviderException(
                'No provider specified and no default provider configured. '
                . 'Set up a default in the LLM backend module: create a Provider, a Model and a '
                . 'Configuration, then mark that Configuration active and default. '
                . '(The plugin.tx_nrllm TypoScript settings are not evaluated — provider configuration is database-backed.)',
                4867297358,
            );
        }

        if (!isset($this->providers[$identifier])) {
            throw new ProviderException(sprintf('Provider "%s" not found', $identifier), 6273324883);
        }

        return $this->providers[$identifier];
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAvailableProviders(): array
    {
        return array_filter(
            $this->providers,
            static fn(ProviderInterface $provider) => $provider->isAvailable(),
        );
    }

    /**
     * Check if at least one provider is available.
     */
    public function hasAvailableProvider(): bool
    {
        return $this->getAvailableProviders() !== [];
    }

    /**
     * @return array<string, string>
     */
    public function getProviderList(): array
    {
        $list = [];
        foreach ($this->providers as $identifier => $provider) {
            $list[$identifier] = $provider->getName();
        }
        return $list;
    }

    /**
     * Check if a specific feature is supported by a provider.
     */
    public function supportsFeature(string $feature, ?string $provider = null): bool
    {
        try {
            $providerInstance = $this->getProvider($provider);
            return $providerInstance->supportsFeature($feature);
        } catch (ProviderException) {
            return false;
        }
    }

    /**
     * Get configuration for a provider.
     *
     * @return array<string, mixed>
     */
    public function getProviderConfiguration(string $identifier): array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = is_array($this->configuration['providers'] ?? null) ? $this->configuration['providers'] : [];
        return $providers[$identifier] ?? [];
    }

    /**
     * Dynamically configure a provider.
     *
     * @param array<string, mixed> $config
     */
    public function configureProvider(string $identifier, array $config): void
    {
        if (!isset($this->providers[$identifier])) {
            throw new ProviderException(sprintf('Provider "%s" not found', $identifier), 5332497319);
        }

        $this->providers[$identifier]->configure($config);
    }

    private function loadConfiguration(): void
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
            $this->configuration = $config;
        } catch (Exception $e) {
            $this->logger->warning('Failed to load extension configuration', ['exception' => $e]);
            $this->configuration = [];
        }
    }
}
