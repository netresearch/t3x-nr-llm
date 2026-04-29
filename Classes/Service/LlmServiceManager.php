<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Exception;
use Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

final class LlmServiceManager implements LlmServiceManagerInterface, SingletonInterface
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    private ?string $defaultProvider = null;

    /** @var array<string, mixed> */
    private array $configuration = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
        private readonly ProviderAdapterRegistry $adapterRegistry,
        private readonly MiddlewarePipeline $pipeline,
        private readonly CacheManagerInterface $cacheManager,
    ) {
        $this->loadConfiguration();
    }

    private function loadConfiguration(): void
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
            $this->configuration = $config;
            $defaultProvider = $config['defaultProvider'] ?? null;
            $this->defaultProvider = is_string($defaultProvider) ? $defaultProvider : null;
        } catch (Exception $e) {
            $this->logger->warning('Failed to load extension configuration', ['exception' => $e]);
            $this->configuration = [];
        }
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
        $identifier ??= $this->defaultProvider;

        if ($identifier === null) {
            throw new ProviderException('No provider specified and no default provider configured', 4867297358);
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

    public function setDefaultProvider(string $identifier): void
    {
        if (!isset($this->providers[$identifier])) {
            throw new ProviderException(sprintf('Cannot set default: Provider "%s" not found', $identifier), 7808641575);
        }
        $this->defaultProvider = $identifier;
    }

    public function getDefaultProvider(): ?string
    {
        return $this->defaultProvider;
    }

    /**
     * Send a chat completion request.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function chat(array $messages, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider']) ? $optionsArray['provider'] : null;
        unset($optionsArray['provider']);

        $normalisedMessages = $this->normaliseMessages($messages);

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Chat, $providerKey),
            ProviderOperation::Chat,
            fn(): CompletionResponse => $this->getProvider($providerKey)->chatCompletion($normalisedMessages, $optionsArray),
        );
    }

    /**
     * Send a simple completion request.
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider']) ? $optionsArray['provider'] : null;
        unset($optionsArray['provider']);

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Completion, $providerKey),
            ProviderOperation::Completion,
            fn(): CompletionResponse => $this->getProvider($providerKey)->complete($prompt, $optionsArray),
        );
    }

    /**
     * Generate embeddings for text.
     *
     * @param string|array<int, string> $input
     */
    public function embed(string|array $input, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        $options ??= new EmbeddingOptions();
        $optionsArray = $options->toArray();
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider']) ? $optionsArray['provider'] : null;
        unset($optionsArray['provider']);

        // Cache metadata: CacheMiddleware short-circuits when it sees a key.
        // Callers pass cache_ttl: 0 (via EmbeddingOptions::noCache()) to
        // disable caching for ephemeral content — we honour that by leaving
        // the key out of metadata so the middleware becomes a no-op for
        // this call.
        $cacheTtl = is_int($optionsArray['cache_ttl'] ?? null) ? $optionsArray['cache_ttl'] : 0;
        $metadata = [];
        if ($cacheTtl > 0) {
            $resolvedProvider = $providerKey ?? $this->defaultProvider ?? 'default';
            $metadata = [
                CacheMiddleware::METADATA_CACHE_KEY => $this->cacheManager->generateCacheKey(
                    $resolvedProvider,
                    'embeddings',
                    ['input' => $input, 'options' => $optionsArray],
                ),
                CacheMiddleware::METADATA_CACHE_TTL  => $cacheTtl,
                CacheMiddleware::METADATA_CACHE_TAGS => [
                    'nrllm_embeddings',
                    'nrllm_provider_' . $resolvedProvider,
                ],
            ];
        }

        // Terminal returns an array-shaped payload so CacheMiddleware (which
        // persists `array<string, mixed>`) can round-trip through the TYPO3
        // cache frontend. The typed response is reconstructed at this layer.
        $raw = $this->pipeline->run(
            ProviderCallContext::for(ProviderOperation::Embedding, $metadata),
            $this->synthesizeTransientConfiguration(ProviderOperation::Embedding, $providerKey),
            function () use ($input, $optionsArray, $providerKey): array {
                $provider = $this->getProvider($providerKey);
                if (!$provider->supportsFeature('embeddings')) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support embeddings', $provider->getIdentifier()),
                        8701213030,
                    );
                }

                return $provider->embeddings($input, $optionsArray)->toArray();
            },
        );

        if (!is_array($raw)) {
            throw new ProviderException(
                'Embedding pipeline returned non-array payload — expected array<string, mixed>',
                2746395810,
            );
        }

        return EmbeddingResponse::fromArray($raw);
    }

    /**
     * Analyze an image with vision capabilities.
     *
     * Accepts either typed `VisionContent` instances or legacy array
     * fixtures (`{type: 'text'|'image_url', ...}`) for back-compat —
     * array entries are normalised via `VisionContent::fromArray()` so
     * the downstream provider always receives `list<VisionContent>` and
     * never has to defend against mixed input.
     *
     * @param list<VisionContent|array<string, mixed>> $content
     */
    public function vision(array $content, ?VisionOptions $options = null): VisionResponse
    {
        $options ??= new VisionOptions();
        $optionsArray = $options->toArray();
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider']) ? $optionsArray['provider'] : null;
        unset($optionsArray['provider']);

        $normalisedContent = array_map(
            static fn(VisionContent|array $item): VisionContent
                => $item instanceof VisionContent ? $item : VisionContent::fromArray($item),
            $content,
        );

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Vision, $providerKey),
            ProviderOperation::Vision,
            function () use ($normalisedContent, $optionsArray, $providerKey): VisionResponse {
                $provider = $this->getProvider($providerKey);
                if (!$provider instanceof VisionCapableInterface) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support vision', $provider->getIdentifier()),
                        5549344501,
                    );
                }

                return $provider->analyzeImage($normalisedContent, $optionsArray);
            },
        );
    }

    /**
     * Stream a chat completion response.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChat(array $messages, ?ChatOptions $options = null): Generator
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider']) ? $optionsArray['provider'] : null;
        $provider = $this->getProvider($providerKey);
        unset($optionsArray['provider']);

        if (!$provider instanceof StreamingCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support streaming', $provider->getIdentifier()),
                1581627129,
            );
        }

        return $provider->streamChatCompletion($this->normaliseMessages($messages), $optionsArray);
    }

    /**
     * Chat completion with tool calling.
     *
     * Accepts both typed `ChatMessage` / `ToolSpec` instances and legacy
     * array fixtures for back-compat — each non-typed entry is routed
     * through the matching `fromArray()` factory so the downstream
     * provider always receives `list<ChatMessage>` + `list<ToolSpec>`.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     */
    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        $optionsArray = $options->toArray();
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider']) ? $optionsArray['provider'] : null;
        unset($optionsArray['provider']);

        $normalisedMessages = $this->normaliseMessages($messages);
        $normalisedTools    = array_map(
            static fn(ToolSpec|array $tool): ToolSpec => $tool instanceof ToolSpec ? $tool : ToolSpec::fromArray($tool),
            $tools,
        );

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Tools, $providerKey),
            ProviderOperation::Tools,
            function () use ($normalisedMessages, $normalisedTools, $optionsArray, $providerKey): CompletionResponse {
                $provider = $this->getProvider($providerKey);
                if (!$provider instanceof ToolCapableInterface) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support tool calling', $provider->getIdentifier()),
                        9324699785,
                    );
                }

                return $provider->chatCompletionWithTools($normalisedMessages, $normalisedTools, $optionsArray);
            },
        );
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

    // ========================================
    // Database-Backed Provider Methods
    // ========================================

    /**
     * Get adapter instance from a database Model entity.
     *
     * This creates a configured adapter using the Provider and Model from the database.
     */
    public function getAdapterFromModel(Model $model): ProviderInterface
    {
        return $this->adapterRegistry->createAdapterFromModel($model);
    }

    /**
     * Get adapter instance from an LlmConfiguration entity.
     */
    public function getAdapterFromConfiguration(LlmConfiguration $configuration): ProviderInterface
    {
        $llmModel = $configuration->getLlmModel();
        if ($llmModel === null) {
            throw new ProviderException(
                sprintf('Configuration "%s" has no model assigned', $configuration->getIdentifier()),
                1735300100,
            );
        }

        return $this->adapterRegistry->createAdapterFromModel($llmModel);
    }

    /**
     * Execute chat completion using an LlmConfiguration entity.
     *
     * If the configuration has a fallback chain, retryable provider errors
     * on the primary (network, 5xx, 429) transparently re-run the request
     * against each fallback configuration in order.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function chatWithConfiguration(array $messages, LlmConfiguration $configuration): CompletionResponse
    {
        $normalisedMessages = $this->normaliseMessages($messages);

        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Chat,
            function (LlmConfiguration $config) use ($normalisedMessages): CompletionResponse {
                $adapter = $this->getAdapterFromConfiguration($config);
                $options = $config->toOptionsArray();
                unset($options['provider']);
                return $adapter->chatCompletion($normalisedMessages, $options);
            },
        );
    }

    /**
     * Execute completion using an LlmConfiguration entity.
     *
     * Fallback chain is applied when configured; see chatWithConfiguration().
     */
    public function completeWithConfiguration(string $prompt, LlmConfiguration $configuration): CompletionResponse
    {
        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Completion,
            function (LlmConfiguration $config) use ($prompt): CompletionResponse {
                $adapter = $this->getAdapterFromConfiguration($config);
                $options = $config->toOptionsArray();
                unset($options['provider']);
                return $adapter->complete($prompt, $options);
            },
        );
    }

    /**
     * Invoke the provider middleware pipeline for a per-configuration call.
     *
     * The pipeline composes every service tagged
     * `nr_llm.provider_middleware` (fallback, budget, usage, cache, …) around
     * the given terminal. Callers pass the current configuration, the
     * operation kind (so middleware can filter by operation) and the
     * terminal closure that performs the actual provider invocation.
     *
     * @template T
     *
     * @param callable(LlmConfiguration): T $terminal
     *
     * @return T
     */
    private function runThroughPipeline(
        LlmConfiguration $configuration,
        ProviderOperation $operation,
        callable $terminal,
    ): mixed {
        return $this->pipeline->run(
            ProviderCallContext::for($operation),
            $configuration,
            $terminal,
        );
    }

    /**
     * Normalise a public-API messages list into `list<ChatMessage>`.
     *
     * Legacy array fixtures (`['role' => '...', 'content' => '...']`) are
     * routed through `ChatMessage::fromArray()` so providers downstream
     * always receive typed instances and never have to defend against
     * mixed input. Pre-typed entries pass through verbatim.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage>
     */
    private function normaliseMessages(array $messages): array
    {
        return array_map(
            static fn(ChatMessage|array $message): ChatMessage
                => $message instanceof ChatMessage ? $message : ChatMessage::fromArray($message),
            $messages,
        );
    }

    /**
     * Build a transient LlmConfiguration for direct (ad-hoc) provider calls.
     *
     * Direct API methods — `chat()`, `complete()`, `embed()`, `vision()`,
     * `chatWithTools()` — do not carry an LlmConfiguration entity, but the
     * pipeline's interface requires one. The synthesized instance is
     * unpersisted (no uid, never written), carries an empty fallback chain
     * (so FallbackMiddleware passes through) and has a human-readable
     * identifier so log / trace labels can distinguish ad-hoc traffic from
     * configuration-backed calls.
     *
     * Middleware that needs more context (beUserUid for BudgetMiddleware,
     * cache keys for CacheMiddleware, etc.) reads it from the
     * ProviderCallContext metadata — not from the configuration.
     */
    private function synthesizeTransientConfiguration(
        ProviderOperation $operation,
        ?string $providerKey,
    ): LlmConfiguration {
        $identifier = sprintf(
            'ad-hoc:%s:%s',
            $operation->value,
            $providerKey ?? ($this->defaultProvider ?? 'default'),
        );

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);

        return $configuration;
    }

    /**
     * Stream chat completion using an LlmConfiguration entity.
     *
     * Fallback chain is intentionally NOT applied to streaming: once the first
     * chunk has been yielded to the caller we cannot swap providers mid-stream.
     * Use chatWithConfiguration() if fallback protection is required.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatWithConfiguration(array $messages, LlmConfiguration $configuration): Generator
    {
        $adapter = $this->getAdapterFromConfiguration($configuration);
        $options = $configuration->toOptionsArray();

        // Remove provider key as we already have the adapter
        unset($options['provider']);

        if (!$adapter instanceof StreamingCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support streaming', $adapter->getIdentifier()),
                1735300101,
            );
        }

        return $adapter->streamChatCompletion($this->normaliseMessages($messages), $options);
    }

    /**
     * Get provider adapter registry.
     */
    public function getAdapterRegistry(): ProviderAdapterRegistry
    {
        return $this->adapterRegistry;
    }
}
