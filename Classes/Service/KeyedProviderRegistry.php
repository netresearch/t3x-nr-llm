<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Exception;
use Netresearch\NrLlm\Domain\ValueObject\ProviderAdapterKey;
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

    /**
     * The adapter registered under a key (#893).
     *
     * The parameter is a {@see ProviderAdapterKey} and not a string, because
     * this map is keyed by the ADAPTER's own name and a `tx_nrllm_provider`
     * row's identifier is a different value returned by an identically named
     * method. Handing the row identifier here produced a plausible
     * "Provider … not found" in 0.32.0 (#873); the type is what stops it
     * happening again rather than being fixed again.
     */
    public function getProvider(?ProviderAdapterKey $key = null): ProviderInterface
    {
        if ($key === null) {
            throw new ProviderException(
                'No provider specified and no default provider configured. '
                . 'Set up a default in the LLM backend module: create a Provider, a Model and a '
                . 'Configuration, then mark that Configuration active and default. '
                . '(The plugin.tx_nrllm TypoScript settings are not evaluated — provider configuration is database-backed.)',
                4867297358,
            );
        }

        if (!isset($this->providers[$key->value])) {
            throw new ProviderException(sprintf('Provider "%s" not found', $key->value), 6273324883);
        }

        return $this->providers[$key->value];
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAvailableProviders(): array
    {
        return array_filter(
            $this->providers,
            static fn(ProviderInterface $provider): bool => $provider->isAvailable(),
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
    public function supportsFeature(string $feature, ?ProviderAdapterKey $provider = null): bool
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
    public function getProviderConfiguration(ProviderAdapterKey $key): array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = is_array($this->configuration['providers'] ?? null) ? $this->configuration['providers'] : [];

        return $providers[$key->value] ?? [];
    }

    /**
     * Dynamically configure a provider.
     *
     * @param array<string, mixed> $config
     */
    public function configureProvider(ProviderAdapterKey $key, array $config): void
    {
        if (!isset($this->providers[$key->value])) {
            throw new ProviderException(sprintf('Provider "%s" not found', $key->value), 5332497319);
        }

        $this->providers[$key->value]->configure($config);
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
