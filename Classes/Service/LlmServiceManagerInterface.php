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
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;

/**
 * Interface for LLM service management.
 *
 * Extracted from LlmServiceManager to enable testing with mocks.
 */
interface LlmServiceManagerInterface
{
    public function registerProvider(ProviderInterface $provider): void;

    public function hasAvailableProvider(): bool;

    public function getProvider(?string $identifier = null): ProviderInterface;

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAvailableProviders(): array;

    /**
     * @return array<string, string>
     */
    public function getProviderList(): array;

    /**
     * Legacy array-shaped message fixtures are accepted for back-compat
     * and normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function chat(array $messages, ?ChatOptions $options = null): CompletionResponse;

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse;

    /**
     * Resolve the effective configuration for a configuration-driven completion.
     *
     * Returns the explicitly passed configuration when set, otherwise the
     * backend-managed active default (resolved with the same guards as a generic
     * complete()/chat() call). Returns null when neither resolves, signalling the
     * caller to fall back to the generic path (which raises the "no provider
     * specified" error).
     */
    public function resolveEffectiveConfiguration(?LlmConfiguration $configuration = null): ?LlmConfiguration;

    /**
     * Complete a prompt using a specific LLM configuration.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $optionOverrides per-call options that take precedence over the configuration's stored defaults
     */
    public function completeWithConfiguration(string $prompt, LlmConfiguration $configuration, array $metadata = [], array $optionOverrides = []): CompletionResponse;

    /**
     * Chat using a specific LLM configuration (database-backed provider resolution).
     *
     * Legacy array-shaped message fixtures are accepted for back-compat
     * and normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $metadata
     * @param array<string, mixed>                   $optionOverrides per-call options that take precedence over the configuration's stored defaults
     */
    public function chatWithConfiguration(array $messages, LlmConfiguration $configuration, array $metadata = [], array $optionOverrides = []): CompletionResponse;

    /**
     * Stream chat completion using a specific LLM configuration.
     *
     * Legacy array-shaped message fixtures are accepted for back-compat
     * and normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $optionOverrides per-call options that take precedence over the configuration's stored defaults
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatWithConfiguration(array $messages, LlmConfiguration $configuration, array $optionOverrides = []): Generator;

    /**
     * @param string|array<int, string> $input
     */
    public function embed(string|array $input, ?EmbeddingOptions $options = null): EmbeddingResponse;

    /**
     * Legacy array-shaped vision-content fixtures are accepted for
     * back-compat and normalised via `VisionContent::fromArray()`
     * before dispatch.
     *
     * @param list<VisionContent|array<string, mixed>> $content
     */
    public function vision(array $content, ?VisionOptions $options = null): VisionResponse;

    /**
     * Legacy array-shaped message fixtures are accepted for back-compat
     * and normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChat(array $messages, ?ChatOptions $options = null): Generator;

    /**
     * Legacy array-shaped message and tool fixtures are accepted for
     * back-compat and normalised via `ChatMessage::fromArray()` /
     * `ToolSpec::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     */
    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse;

    public function supportsFeature(string $feature, ?string $provider = null): bool;

    /**
     * @return array<string, mixed>
     */
    public function getProviderConfiguration(string $identifier): array;

    /**
     * @param array<string, mixed> $config
     */
    public function configureProvider(string $identifier, array $config): void;
}
