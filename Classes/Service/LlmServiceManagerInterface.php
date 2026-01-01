<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
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

    public function getProvider(?string $identifier = null): ProviderInterface;

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAvailableProviders(): array;

    /**
     * @return array<string, string>
     */
    public function getProviderList(): array;

    public function setDefaultProvider(string $identifier): void;

    public function getDefaultProvider(): ?string;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chat(array $messages, ?ChatOptions $options = null): CompletionResponse;

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse;

    /**
     * Complete a prompt using a specific LLM configuration.
     */
    public function completeWithConfiguration(string $prompt, LlmConfiguration $configuration): CompletionResponse;

    /**
     * @param string|array<int, string> $input
     */
    public function embed(string|array $input, ?EmbeddingOptions $options = null): EmbeddingResponse;

    /**
     * @param array<int, array{type: string, image_url?: array{url: string}, text?: string}> $content
     */
    public function vision(array $content, ?VisionOptions $options = null): VisionResponse;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChat(array $messages, ?ChatOptions $options = null): Generator;

    /**
     * @param array<int, array{role: string, content: string}>                                                                      $messages
     * @param array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
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
