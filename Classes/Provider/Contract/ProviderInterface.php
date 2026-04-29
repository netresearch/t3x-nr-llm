<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;

interface ProviderInterface
{
    public function getName(): string;

    public function getIdentifier(): string;

    /**
     * Configure the provider with API key and other settings.
     *
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void;

    public function isAvailable(): bool;

    public function supportsFeature(string|ModelCapability $feature): bool;

    /**
     * `LlmServiceManager` normalises every entry to a `ChatMessage` before
     * forwarding the call. Implementations called directly (e.g. from tests
     * or one-off scripts) may also receive legacy `['role' => ..., 'content'
     * => ...]` array fixtures; each implementation is responsible for
     * normalising mixed input via `ChatMessage::fromArray()`.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse;

    /**
     * @param array<string, mixed> $options
     */
    public function complete(string $prompt, array $options = []): CompletionResponse;

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse;

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array;

    public function getDefaultModel(): string;

    /**
     * Test the connection to the provider.
     *
     * This method should make an actual HTTP request to verify connectivity.
     * Unlike getAvailableModels(), this method MUST throw an exception on failure
     * and should NOT return fallback values.
     *
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array;
}
