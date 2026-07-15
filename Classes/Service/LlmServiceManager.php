<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

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
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use TYPO3\CMS\Core\SingletonInterface;

final readonly class LlmServiceManager implements LlmServiceManagerInterface, SingletonInterface
{
    public function __construct(
        private ProviderAdapterRegistryInterface $adapterRegistry,
        private MiddlewarePipeline $pipeline,
        private KeyedProviderRegistry $providerRegistry,
        private ConfigurationResolver $configurationResolver,
        private MessageShaper $messageShaper,
        private EmbedCacheKeyBuilder $embedCacheKeyBuilder,
        private ?SkillInjectionService $skillInjection = null,
        private ?ModelSelectionServiceInterface $modelSelectionService = null,
        private ?StreamingDispatcher $streaming = null,
    ) {}

    /**
     * Prepend the resolved configuration's attached skills to a plain prompt.
     *
     * Used only by the configuration-driven entry points (complete()/chat()/
     * streamChat()) once a backend-managed default configuration has been
     * resolved — never by embed()/vision()/speech, keeping skill injection
     * scoped to text generation. A no-op when no SkillInjectionService is
     * wired (unit-test constructions that omit it).
     */
    private function injectConfigSkillsIntoPrompt(string $prompt, LlmConfiguration $configuration): string
    {
        if ($this->skillInjection === null) {
            return $prompt;
        }

        return $this->skillInjection->augmentPrompt(
            $prompt,
            SkillInjectionService::toList($configuration->getSkills()),
        );
    }

    /**
     * Prepend the resolved configuration's attached skills to the first
     * user-role message (system role untouched). See
     * {@see self::injectConfigSkillsIntoPrompt()} for the scope rationale.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function injectConfigSkillsIntoMessages(array $messages, LlmConfiguration $configuration): array
    {
        if ($this->skillInjection === null) {
            return $messages;
        }

        return $this->skillInjection->augmentMessages(
            $messages,
            SkillInjectionService::toList($configuration->getSkills()),
        );
    }

    /**
     * Resolve the effective configuration for a configuration-driven completion.
     *
     * Delegates to {@see ConfigurationResolver}; retained on the manager
     * because it is part of {@see LlmServiceManagerInterface}.
     */
    public function resolveEffectiveConfiguration(?LlmConfiguration $configuration = null): ?LlmConfiguration
    {
        return $this->configurationResolver->resolveEffectiveConfiguration($configuration);
    }

    public function registerProvider(ProviderInterface $provider): void
    {
        $this->providerRegistry->registerProvider($provider);
    }

    public function getProvider(?string $identifier = null): ProviderInterface
    {
        return $this->providerRegistry->getProvider($identifier);
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAvailableProviders(): array
    {
        return $this->providerRegistry->getAvailableProviders();
    }

    /**
     * Check if at least one provider is available.
     */
    public function hasAvailableProvider(): bool
    {
        return $this->providerRegistry->hasAvailableProvider();
    }

    /**
     * @return array<string, string>
     */
    public function getProviderList(): array
    {
        return $this->providerRegistry->getProviderList();
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
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Single source of truth: with no explicit provider pinned, prefer the
        // backend-module-managed default DB configuration so it drives generation.
        // The per-call options override the configuration's stored defaults. When
        // no default configuration resolves and no provider is pinned, the call
        // throws (no extension-config fallback; see ADR-034).
        $defaultConfiguration = $this->configurationResolver->resolveDefaultConfiguration($providerKey);
        if ($defaultConfiguration !== null) {
            return $this->chatWithConfiguration(
                $this->injectConfigSkillsIntoMessages($messages, $defaultConfiguration),
                $defaultConfiguration,
                $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
                $optionsArray,
            );
        }

        $normalisedMessages = $this->messageShaper->normalise($messages);

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Chat, $providerKey),
            ProviderOperation::Chat,
            fn(): CompletionResponse => $this->getProvider($providerKey)->chatCompletion($this->messageShaper->applySystemPrompt($normalisedMessages, $optionsArray), $optionsArray),
            $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
        );
    }

    /**
     * Send a simple completion request.
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Single source of truth: prefer the default DB configuration (see chat()).
        $defaultConfiguration = $this->configurationResolver->resolveDefaultConfiguration($providerKey);
        if ($defaultConfiguration !== null) {
            return $this->completeWithConfiguration(
                $this->injectConfigSkillsIntoPrompt($prompt, $defaultConfiguration),
                $defaultConfiguration,
                $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
                $optionsArray,
            );
        }

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Completion, $providerKey),
            ProviderOperation::Completion,
            fn(): CompletionResponse => $this->getProvider($providerKey)->complete($prompt, $optionsArray),
            $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
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
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Cache metadata: EmbedCacheKeyBuilder returns an empty array when
        // cache_ttl <= 0 (the EmbeddingOptions::noCache() contract), so the key
        // is left out and CacheMiddleware becomes a no-op for this call. The
        // ad-hoc path keys by provider identifier.
        $cacheTtl = is_int($optionsArray['cache_ttl'] ?? null) ? $optionsArray['cache_ttl'] : 0;
        $metadata = $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey());
        $resolvedProvider = $providerKey ?? 'default';
        $metadata += $this->embedCacheKeyBuilder->build(
            $cacheTtl,
            $resolvedProvider,
            ['input' => $input, 'options' => $optionsArray],
            'nrllm_provider_' . $resolvedProvider,
        );

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
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        $normalisedContent = array_values(array_map(
            static fn(VisionContent|array $item): VisionContent
                => $item instanceof VisionContent ? $item : VisionContent::fromArray($item),
            $content,
        ));

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
            $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
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
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Single source of truth: prefer the default DB configuration (see chat()),
        // so streaming and non-streaming calls resolve the same provider/model.
        // Budget attribution is forwarded so the streaming lifecycle can gate the
        // same over-budget users the non-streaming path rejects.
        $defaultConfiguration = $this->configurationResolver->resolveDefaultConfiguration($providerKey);
        if ($defaultConfiguration !== null) {
            return $this->streamChatWithConfiguration(
                $this->injectConfigSkillsIntoMessages($messages, $defaultConfiguration),
                $defaultConfiguration,
                $optionsArray,
                $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()),
            );
        }

        // Ad-hoc: a pinned provider with no configuration entity — no fallback
        // chain, provider resolved by key.
        $open = function () use ($messages, $optionsArray, $providerKey): Generator {
            $provider = $this->getProvider($providerKey);
            $this->assertStreamingCapable($provider, 1581627129);

            return $provider->streamChatCompletion(
                $this->messageShaper->applySystemPrompt($this->messageShaper->normalise($messages), $optionsArray),
                $optionsArray,
            );
        };

        $configuration = $this->synthesizeTransientConfiguration(ProviderOperation::Stream, $providerKey);

        if ($this->streaming === null) {
            return $open();
        }

        // Check capability eagerly so an unsupported provider throws at call time
        // (as the legacy path and non-streaming calls do), not lazily on the
        // first iteration inside the dispatcher.
        $this->assertStreamingCapable($this->getProvider($providerKey), 1581627129);

        $metadata = $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost());
        $metadata[StreamingDispatcher::METADATA_PROVIDER]     = $providerKey ?? 'default';
        $metadata[StreamingDispatcher::METADATA_PROMPT_CHARS] = $this->estimatePromptChars($messages);

        return $this->streaming->stream(
            ProviderCallContext::for(ProviderOperation::Stream, $metadata),
            $configuration,
            $open,
        );
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
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        $normalisedMessages = $this->messageShaper->normalise($messages);
        $normalisedTools    = array_values(array_map(
            static fn(ToolSpec|array $tool): ToolSpec => $tool instanceof ToolSpec ? $tool : ToolSpec::fromArray($tool),
            $tools,
        ));

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

                return $provider->chatCompletionWithTools($this->messageShaper->applySystemPrompt($normalisedMessages, $optionsArray), $normalisedTools, $optionsArray);
            },
            $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
        );
    }

    /**
     * Chat completion with tool calling against a specific LLM configuration.
     *
     * Mirrors {@see self::chatWithConfiguration()} — resolves the adapter via
     * {@see self::getAdapterFromConfiguration()} (so the configuration's vault
     * key + model + params drive the call) and runs through the middleware
     * pipeline (so Budget/Usage see the real Model and record real cost) — but
     * guards `ToolCapableInterface` and dispatches `chatCompletionWithTools()`.
     *
     * This is the keystone for the tool runtime: the keyed
     * {@see self::chatWithTools()} path cannot reach a DB-backed configuration's
     * vault key/model/pricing (it resolves a provider from ExtensionConfiguration
     * against a model-less transient configuration), so a tool loop that must
     * run on a selected configuration uses this entry point instead.
     *
     * The per-call {@see ToolOptions} take precedence over the configuration's
     * stored defaults, matching `chatWithConfiguration()`'s override semantics.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     */
    public function chatWithToolsForConfiguration(array $messages, array $tools, LlmConfiguration $configuration, ?ToolOptions $options = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        $optionOverrides = $options->toArray();
        unset($optionOverrides['provider']);

        $normalisedMessages = $this->messageShaper->normalise($messages);
        $normalisedTools    = array_values(array_map(
            static fn(ToolSpec|array $tool): ToolSpec => $tool instanceof ToolSpec ? $tool : ToolSpec::fromArray($tool),
            $tools,
        ));

        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Tools,
            function (LlmConfiguration $config) use ($normalisedMessages, $normalisedTools, $optionOverrides): CompletionResponse {
                $adapter = $this->getAdapterFromConfiguration($config);
                if (!$adapter instanceof ToolCapableInterface) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support tool calling', $adapter->getIdentifier()),
                        1782748801,
                    );
                }

                $callOptions = array_merge($config->toOptionsArray(), $optionOverrides);
                unset($callOptions['provider']);

                return $adapter->chatCompletionWithTools(
                    $this->messageShaper->applySystemPrompt($normalisedMessages, $callOptions),
                    $normalisedTools,
                    $callOptions,
                );
            },
            $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey()),
        );
    }

    /**
     * Generate embeddings against a specific LLM configuration.
     *
     * Mirrors {@see self::chatWithToolsForConfiguration()} — resolves the
     * adapter via {@see self::getAdapterFromConfiguration()} (so the
     * configuration's vault key + model + params drive the call) and runs
     * through the middleware pipeline (so Budget/Usage see the real Model and
     * record real cost) — but guards the `embeddings` feature the same way
     * {@see self::embed()} does and dispatches `embeddings()`.
     *
     * This closes the gap where embedding consumers that persist vectors had
     * to duplicate provider/model settings into their own extension
     * configuration: the DB-backed configuration now carries them, and the
     * per-call {@see EmbeddingOptions} take precedence over the
     * configuration's stored defaults (an options `model` overrides the
     * configuration's model id), matching `chatWithConfiguration()`'s
     * override semantics.
     *
     * Caching mirrors {@see self::embed()}: a positive `cache_ttl` puts a
     * cache key on the call context so `CacheMiddleware` can short-circuit.
     * The key is derived from the configuration identifier plus the
     * *effective* model (options override or the configuration's model id),
     * so two configurations pointing at different models never share entries.
     *
     * @param string|array<int, string> $input
     */
    public function embedForConfiguration(string|array $input, LlmConfiguration $configuration, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        $options ??= new EmbeddingOptions();
        $optionOverrides = $options->toArray();
        unset($optionOverrides['provider']);

        $metadata = $this->buildBudgetMetadata($options->getBeUserUid(), $options->getPlannedCost()) + $this->idempotencyMetadata($options->getIdempotencyKey());

        // Cache metadata mirrors embed(), but the configuration path keys by
        // configuration identifier plus the effective model (options override
        // or the configuration's model id) so two configurations pointing at
        // different models never share entries. EmbedCacheKeyBuilder returns an
        // empty array for cache_ttl <= 0 (EmbeddingOptions::noCache()).
        $cacheTtl = is_int($optionOverrides['cache_ttl'] ?? null) ? $optionOverrides['cache_ttl'] : 0;
        $effectiveModel = is_string($optionOverrides['model'] ?? null)
            ? $optionOverrides['model']
            : $configuration->getModelId();
        // EmbedCacheKeyBuilder sanitizes the scope tag: configuration
        // identifiers use the dotted preset scheme (nr_ai_search.embeddings),
        // and the cache frontend rejects a tag containing a dot with an
        // InvalidArgumentException on set().
        $metadata += $this->embedCacheKeyBuilder->build(
            $cacheTtl,
            $configuration->getIdentifier(),
            ['input' => $input, 'options' => $optionOverrides, 'model' => $effectiveModel],
            'nrllm_configuration_' . $configuration->getIdentifier(),
        );

        // Terminal returns an array-shaped payload so CacheMiddleware (which
        // persists `array<string, mixed>`) can round-trip through the TYPO3
        // cache frontend. The typed response is reconstructed at this layer.
        $raw = $this->pipeline->run(
            ProviderCallContext::for(ProviderOperation::Embedding, $metadata),
            $configuration,
            function (LlmConfiguration $config) use ($input, $optionOverrides): array {
                $adapter = $this->getAdapterFromConfiguration($config);
                if (!$adapter->supportsFeature('embeddings')) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support embeddings', $adapter->getIdentifier()),
                        7093846251,
                    );
                }

                $callOptions = array_merge($config->toOptionsArray(), $optionOverrides);
                unset($callOptions['provider']);

                return $adapter->embeddings($input, $callOptions)->toArray();
            },
        );

        if (!is_array($raw)) {
            throw new ProviderException(
                'Embedding pipeline returned non-array payload — expected array<string, mixed>',
                6482915370,
            );
        }

        return EmbeddingResponse::fromArray($raw);
    }

    /**
     * Check if a specific feature is supported by a provider.
     */
    public function supportsFeature(string $feature, ?string $provider = null): bool
    {
        return $this->providerRegistry->supportsFeature($feature, $provider);
    }

    /**
     * Get configuration for a provider.
     *
     * @return array<string, mixed>
     */
    public function getProviderConfiguration(string $identifier): array
    {
        return $this->providerRegistry->getProviderConfiguration($identifier);
    }

    /**
     * Dynamically configure a provider.
     *
     * @param array<string, mixed> $config
     */
    public function configureProvider(string $identifier, array $config): void
    {
        $this->providerRegistry->configureProvider($identifier, $config);
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
        // Criteria-mode configurations carry no direct model relation (model_uid = 0);
        // their model is selected at call time from the stored criteria. Resolve
        // through ModelSelectionService — which returns the directly configured model
        // unchanged for fixed-mode configs — so both selection modes reach a concrete
        // model here. Without this, every *ForConfiguration() call on a criteria-mode
        // configuration threw "has no model assigned".
        $llmModel = $this->modelSelectionService !== null
            ? $this->modelSelectionService->resolveModel($configuration)
            : $configuration->getLlmModel();
        if ($llmModel === null) {
            throw new ProviderException(
                sprintf('Configuration "%s" has no model assigned', $configuration->getIdentifier()),
                1735300100,
            );
        }

        // Intentionally NOT calling $configuration->setLlmModel($llmModel): the
        // configuration is a repository-managed Extbase entity, so mutating it
        // would mark it dirty and Extbase would persist model_uid at end of
        // request — silently converting a criteria-mode record into a fixed-mode
        // one. Per-model cost analytics for criteria configs (UsageMiddleware
        // reads getLlmModel() directly) remain a separate, non-destructive
        // follow-up.
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
     * @param array<string, mixed>                   $metadata
     * @param array<string, mixed>                   $optionOverrides per-call options that take precedence over the configuration's stored defaults
     */
    public function chatWithConfiguration(array $messages, LlmConfiguration $configuration, array $metadata = [], array $optionOverrides = []): CompletionResponse
    {
        $normalisedMessages = $this->messageShaper->normalise($messages);

        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Chat,
            function (LlmConfiguration $config) use ($normalisedMessages, $optionOverrides): CompletionResponse {
                $adapter = $this->getAdapterFromConfiguration($config);
                $options = array_merge($config->toOptionsArray(), $optionOverrides);
                unset($options['provider']);
                return $adapter->chatCompletion($this->messageShaper->applySystemPrompt($normalisedMessages, $options), $options);
            },
            $metadata,
        );
    }

    /**
     * Execute completion using an LlmConfiguration entity.
     *
     * Fallback chain is applied when configured; see chatWithConfiguration().
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $optionOverrides per-call options that take precedence over the configuration's stored defaults
     */
    public function completeWithConfiguration(string $prompt, LlmConfiguration $configuration, array $metadata = [], array $optionOverrides = []): CompletionResponse
    {
        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Completion,
            function (LlmConfiguration $config) use ($prompt, $optionOverrides): CompletionResponse {
                $adapter = $this->getAdapterFromConfiguration($config);
                $options = array_merge($config->toOptionsArray(), $optionOverrides);
                unset($options['provider']);
                return $adapter->complete($prompt, $options);
            },
            $metadata,
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
     * Optional `$metadata` is forwarded onto the `ProviderCallContext` so
     * cross-cutting middleware (BudgetMiddleware, CacheMiddleware, …) can
     * read what each entry-point knows. Entry points that have no extra
     * context (legacy callers, fixed-shape calls) pass an empty array.
     *
     * @template T
     *
     * @param callable(LlmConfiguration): T $terminal
     * @param array<string, mixed>          $metadata
     *
     * @return T
     */
    private function runThroughPipeline(
        LlmConfiguration $configuration,
        ProviderOperation $operation,
        callable $terminal,
        array $metadata = [],
    ): mixed {
        return $this->pipeline->run(
            ProviderCallContext::for($operation, $metadata),
            $configuration,
            $terminal,
        );
    }

    /**
     * Translate the budget-relevant fields into the metadata keys the
     * BudgetMiddleware reads. Only non-null values become metadata —
     * the middleware's "skip the check" branch naturally fires for
     * absent keys, matching its documented contract (see
     * `BudgetMiddleware::handle()`).
     *
     * Takes raw nullable values rather than a typed option object so
     * every entry point can reuse it: `chat()` reads from `ChatOptions`,
     * `embed()` from `EmbeddingOptions`, `vision()` from `VisionOptions`,
     * `chatWithTools()` from `ToolOptions` — none of which share a
     * common base interface for these two fields. A small option-type-
     * agnostic helper is simpler than introducing a marker interface
     * just to thread two fields.
     *
     * Lives on the manager rather than on the option objects so the
     * options layer does not need to know which middleware exists.
     *
     * @return array<string, mixed>
     */
    private function buildBudgetMetadata(?int $beUserUid, ?float $plannedCost): array
    {
        $metadata = [];

        if ($beUserUid !== null) {
            $metadata[BudgetMiddleware::METADATA_BE_USER_UID] = $beUserUid;
        }

        if ($plannedCost !== null) {
            $metadata[BudgetMiddleware::METADATA_PLANNED_COST] = $plannedCost;
        }

        return $metadata;
    }

    /**
     * Assert a resolved adapter can stream, else throw the typed
     * UnsupportedFeatureException. Shared by both streaming entry points so the
     * eager (call-time) check and the per-fallback opener check raise the same
     * error; the `@phpstan-assert` narrows the adapter for the caller.
     *
     * @phpstan-assert StreamingCapableInterface $adapter
     */
    private function assertStreamingCapable(ProviderInterface $adapter, int $code): void
    {
        if (!$adapter instanceof StreamingCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support streaming', $adapter->getIdentifier()),
                $code,
            );
        }
    }

    /**
     * Sum the character length of a message list's textual content, for the
     * streaming lifecycle's prompt-token estimate (ADR-062). Computed here
     * because the manager holds the messages; the dispatcher only sees the
     * count, never the payload. Non-string (multimodal) content contributes
     * nothing — the estimate is deliberately rough, matching the ≈4 chars/token
     * heuristic the dispatcher applies to it.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function estimatePromptChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            if ($message instanceof ChatMessage) {
                $chars += strlen($message->content);

                continue;
            }

            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $chars += strlen($content);
            }
        }

        return $chars;
    }

    /**
     * Translate an optional idempotency key (ADR-063) into the metadata key the
     * IdempotencyMiddleware reads. Empty / absent keys produce no entry, so the
     * middleware's pass-through branch fires and non-idempotent calls are
     * untouched. Disjoint from {@see self::buildBudgetMetadata()} keys, so the
     * two merge with `+` at every call site.
     *
     * @return array<string, mixed>
     */
    private function idempotencyMetadata(?string $idempotencyKey): array
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return [];
        }

        return [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => $idempotencyKey];
    }

    /**
     * Split the pinned provider key out of a call's options array.
     *
     * Every generic (provider-agnostic) entry point — chat(), complete(),
     * embed(), vision(), streamChat(), chatWithTools() — reads the pinned
     * provider from the options, then strips it so the remaining options can be
     * forwarded to the adapter. Returns the nullable provider key and the
     * options array with `provider` removed.
     *
     * @param array<string, mixed> $optionsArray
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function splitProviderKey(array $optionsArray): array
    {
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider'])
            ? $optionsArray['provider']
            : null;
        unset($optionsArray['provider']);

        return [$providerKey, $optionsArray];
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
            $providerKey ?? 'default',
        );

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);

        return $configuration;
    }

    /**
     * Stream chat completion using an LlmConfiguration entity.
     *
     * Routed through the streaming lifecycle (ADR-062): budget pre-flight before
     * the first chunk, usage + telemetry settlement at stream end. Fallback IS
     * applied, but only in the pre-first-chunk window — once a chunk has been
     * yielded a provider swap is impossible, so a mid-stream failure surfaces to
     * the caller rather than re-routing. Use chatWithConfiguration() for full
     * mid-call fallback protection.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * `$metadata` is threaded onto the streaming ProviderCallContext (budget
     * attribution, task uid); it is the trailing parameter so the pre-existing
     * three-argument callers stay source-compatible. Direct callers that omit it
     * get an empty map, which the budget gate reads as "no budget owner —
     * skip the check", matching chatWithConfiguration()'s contract.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $optionOverrides per-call options that take precedence over the configuration's stored defaults
     * @param array<string, mixed>                   $metadata        cross-cutting streaming context (budget attribution, task uid)
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatWithConfiguration(array $messages, LlmConfiguration $configuration, array $optionOverrides = [], array $metadata = []): Generator
    {
        $open = function (LlmConfiguration $config) use ($messages, $optionOverrides): Generator {
            $adapter = $this->getAdapterFromConfiguration($config);
            $options = array_merge($config->toOptionsArray(), $optionOverrides);

            // Remove provider key as we already have the adapter
            unset($options['provider']);

            $this->assertStreamingCapable($adapter, 1735300101);

            return $adapter->streamChatCompletion(
                $this->messageShaper->applySystemPrompt($this->messageShaper->normalise($messages), $options),
                $options,
            );
        };

        if ($this->streaming === null) {
            return $open($configuration);
        }

        // Check the PRIMARY provider's capability eagerly so an unsupported
        // provider throws at call time, not lazily on the first iteration inside
        // the dispatcher; fallback candidates are still checked per-attempt in
        // the opener above.
        $this->assertStreamingCapable($this->getAdapterFromConfiguration($configuration), 1735300101);

        $metadata[StreamingDispatcher::METADATA_PROMPT_CHARS] = $this->estimatePromptChars($messages);

        return $this->streaming->stream(
            ProviderCallContext::for(ProviderOperation::Stream, $metadata),
            $configuration,
            $open,
        );
    }

    /**
     * Get provider adapter registry.
     */
    public function getAdapterRegistry(): ProviderAdapterRegistryInterface
    {
        return $this->adapterRegistry;
    }
}
