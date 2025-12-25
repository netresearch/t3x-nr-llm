<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;

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

    public function supportsFeature(string $feature): bool;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
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
}
