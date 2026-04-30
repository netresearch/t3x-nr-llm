<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Registry for mapping adapter types to provider adapter classes.
 *
 * This registry bridges database Provider entities with PHP adapter implementations.
 * It creates and configures adapter instances on demand based on provider settings.
 *
 * The registry is `final` and exposes no public mutator: the adapter map
 * is the union of {@see self::ADAPTER_CLASS_MAP} (built-ins) and the
 * `$adapterOverrides` argument passed at construction time. Production
 * code uses the empty default; tests and edge-case extension scenarios
 * pass an override map. See audit 2026-04-23 REC #3.
 */
final class ProviderAdapterRegistry implements ProviderAdapterRegistryInterface, SingletonInterface
{
    /**
     * Mapping of adapter types to provider class names.
     *
     * Single source of truth for the built-in adapters that ship with
     * this extension. New built-in adapter types are added here together
     * with a {@see AdapterType} enum case. Third parties that need to
     * substitute a built-in adapter pass an override via the constructor
     * `$adapterOverrides` argument; runtime mutation is intentionally
     * not supported.
     *
     * @var array<string, class-string<AbstractProvider>>
     */
    private const ADAPTER_CLASS_MAP = [
        AdapterType::OpenAI->value => OpenAiProvider::class,
        AdapterType::Anthropic->value => ClaudeProvider::class,
        AdapterType::Gemini->value => GeminiProvider::class,
        AdapterType::OpenRouter->value => OpenRouterProvider::class,
        AdapterType::Mistral->value => MistralProvider::class,
        AdapterType::Groq->value => GroqProvider::class,
        AdapterType::Ollama->value => OllamaProvider::class,
        AdapterType::AzureOpenAI->value => OpenAiProvider::class, // Azure uses OpenAI-compatible API
        AdapterType::Custom->value => OpenAiProvider::class, // Custom assumes OpenAI-compatible API
    ];

    /**
     * Cache of created adapter instances by provider UID.
     *
     * @var array<int, ProviderInterface>
     */
    private array $adapterCache = [];

    /**
     * Effective adapter map (built-ins merged with constructor overrides).
     *
     * Keys are adapter-type identifiers (matching {@see AdapterType}
     * values for built-ins, or arbitrary strings for custom types).
     * Values are FQCNs of `AbstractProvider` subclasses.
     *
     * @var array<string, class-string<AbstractProvider>>
     */
    private readonly array $adapterMap;

    /**
     * @param array<string, class-string<AbstractProvider>> $adapterOverrides
     *                                                                        Optional adapter-type → class map. Entries override built-ins
     *                                                                        on a per-type basis; new keys add custom adapter types. The
     *                                                                        production container passes an empty array (the default);
     *                                                                        tests use this seam to exercise override / custom-type /
     *                                                                        invalid-class handling without runtime mutation.
     *
     * @throws ProviderConfigurationException when an override class does not extend AbstractProvider
     */
    public function __construct(
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly VaultServiceInterface $vault,
        private readonly SecureHttpClientFactory $httpClientFactory,
        array $adapterOverrides = [],
    ) {
        foreach ($adapterOverrides as $adapterClass) {
            // @phpstan-ignore function.alreadyNarrowedType (runtime validation for callers passing untyped arrays)
            if (!is_subclass_of($adapterClass, AbstractProvider::class)) {
                throw new ProviderConfigurationException(
                    sprintf('Adapter class %s must extend %s', $adapterClass, AbstractProvider::class),
                    1735300001,
                );
            }
        }

        // Overrides win over built-ins (array_merge: later keys overwrite).
        $this->adapterMap = array_merge(self::ADAPTER_CLASS_MAP, $adapterOverrides);
    }

    /**
     * Get an adapter class for the given adapter type.
     *
     * @return class-string<AbstractProvider>
     */
    public function getAdapterClass(string $adapterType): string
    {
        if (isset($this->adapterMap[$adapterType])) {
            return $this->adapterMap[$adapterType];
        }

        // Default to OpenAI-compatible for unknown types
        $this->logger->warning('Unknown adapter type, falling back to OpenAI-compatible', [
            'adapterType' => $adapterType,
        ]);
        return OpenAiProvider::class;
    }

    /**
     * Check if an adapter type is supported.
     */
    public function hasAdapter(string $adapterType): bool
    {
        return isset($this->adapterMap[$adapterType]);
    }

    /**
     * Get all registered adapter types.
     *
     * @return array<string, string> Adapter type to human-readable name
     */
    public function getRegisteredAdapters(): array
    {
        $adapters = Provider::getAdapterTypes();

        // Surface override-only / custom types that are not part of the
        // built-in TCA select list, so backend diagnostics can see them.
        foreach (array_keys($this->adapterMap) as $type) {
            if (!isset($adapters[$type])) {
                $adapters[$type] = $type;
            }
        }

        return $adapters;
    }

    /**
     * Create a configured adapter instance from a Provider entity.
     *
     * @param Provider $provider The provider entity from database
     * @param bool     $useCache Whether to use cached instances
     *
     * @return ProviderInterface Configured adapter instance
     */
    public function createAdapterFromProvider(Provider $provider, bool $useCache = true): ProviderInterface
    {
        $providerUid = $provider->getUid();

        // Return cached instance if available (only for persisted providers with valid UID)
        if ($useCache && $providerUid !== null && isset($this->adapterCache[$providerUid])) {
            return $this->adapterCache[$providerUid];
        }

        $adapterClass = $this->getAdapterClass($provider->getAdapterType());
        $adapter = $this->instantiateAdapter($adapterClass);

        // Configure adapter with provider settings
        $config = $this->buildAdapterConfig($provider);
        $adapter->configure($config);

        // Cache the instance (only for persisted providers with valid UID)
        if ($useCache && $providerUid !== null) {
            $this->adapterCache[$providerUid] = $adapter;
        }

        $this->logger->debug('Created adapter from provider', [
            'providerUid' => $providerUid,
            'providerIdentifier' => $provider->getIdentifier(),
            'adapterType' => $provider->getAdapterType(),
            'adapterClass' => $adapterClass,
        ]);

        return $adapter;
    }

    /**
     * Create a configured adapter instance from a Model entity.
     *
     * This is a convenience method that extracts the provider and model settings.
     *
     * @param Model $model    The model entity from database
     * @param bool  $useCache Whether to use cached instances
     *
     * @return ProviderInterface Configured adapter instance
     */
    public function createAdapterFromModel(Model $model, bool $useCache = true): ProviderInterface
    {
        $provider = $model->getProvider();

        if ($provider === null) {
            throw new ProviderConfigurationException(
                sprintf('Model "%s" has no associated provider', $model->getIdentifier()),
                1735300002,
            );
        }

        $adapter = $this->createAdapterFromProvider($provider, $useCache);

        // Override default model with the specific model ID
        $adapter->configure([
            'apiKeyIdentifier' => $provider->getApiKey(),
            'baseUrl' => $provider->getEffectiveEndpointUrl(),
            'defaultModel' => $model->getModelId(),
            'timeout' => $provider->getApiTimeout(),
            'maxRetries' => $provider->getMaxRetries(),
        ]);

        return $adapter;
    }

    /**
     * Clear the adapter cache.
     *
     * @param int|null $providerUid Optional specific provider UID to clear
     */
    public function clearCache(?int $providerUid = null): void
    {
        if ($providerUid !== null) {
            unset($this->adapterCache[$providerUid]);
        } else {
            $this->adapterCache = [];
        }
    }

    /**
     * Instantiate an adapter class with dependencies.
     *
     * @param class-string<AbstractProvider> $adapterClass
     */
    private function instantiateAdapter(string $adapterClass): AbstractProvider
    {
        return new $adapterClass(
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $this->vault,
            $this->httpClientFactory,
        );
    }

    /**
     * Build adapter configuration array from Provider entity.
     *
     * Maps Provider domain model fields to the config array expected by AbstractProvider::configure().
     *
     * @return array<string, mixed>
     */
    private function buildAdapterConfig(Provider $provider): array
    {
        $config = [
            'apiKeyIdentifier' => $provider->getApiKey(),
            'baseUrl' => $provider->getEffectiveEndpointUrl(),
            'timeout' => $provider->getApiTimeout(),
            'maxRetries' => $provider->getMaxRetries(),
        ];

        // Add organization ID if set (for OpenAI, Azure)
        if ($provider->getOrganizationId() !== '') {
            $config['organizationId'] = $provider->getOrganizationId();
        }

        // Merge additional options from JSON field
        $additionalOptions = $provider->getOptionsArray();
        if (!empty($additionalOptions)) {
            $config = array_merge($config, $additionalOptions);
        }

        return $config;
    }

    /**
     * Test a provider connection.
     *
     * Uses the provider's testConnection() method which makes an actual HTTP request
     * and throws exceptions on failure (unlike getAvailableModels() which may return
     * fallback values).
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testProviderConnection(Provider $provider): array
    {
        try {
            $adapter = $this->createAdapterFromProvider($provider, false);

            if (!$adapter->isAvailable()) {
                return [
                    'success' => false,
                    'message' => 'Provider is not available (API key may be missing)',
                ];
            }

            // Use testConnection() which makes actual HTTP request and throws on failure
            return $adapter->testConnection();
        } catch (Throwable $e) {
            // Sanitize error message to prevent leaking secrets (API keys in URLs, etc.)
            $sanitized = (string)preg_replace(
                '/([?&])(key|api_key|apikey|token|secret|access_token)=[^&\s]+/i',
                '$1$2=***',
                $e->getMessage(),
            );
            return [
                'success' => false,
                'message' => sprintf('Connection failed: %s', $sanitized),
            ];
        }
    }
}
