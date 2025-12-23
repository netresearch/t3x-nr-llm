<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;

/**
 * Interface for LLM service management
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
     * @param ChatOptions|array<string, mixed> $options
     */
    public function chat(array $messages, ChatOptions|array $options = []): CompletionResponse;

    /**
     * @param ChatOptions|array<string, mixed> $options
     */
    public function complete(string $prompt, ChatOptions|array $options = []): CompletionResponse;

    /**
     * @param string|array<int, string> $input
     * @param EmbeddingOptions|array<string, mixed> $options
     */
    public function embed(string|array $input, EmbeddingOptions|array $options = []): EmbeddingResponse;

    /**
     * @param array<int, array{type: string, image_url?: array{url: string}, text?: string}> $content
     * @param VisionOptions|array<string, mixed> $options
     */
    public function vision(array $content, VisionOptions|array $options = []): VisionResponse;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param ChatOptions|array<string, mixed> $options
     * @return \Generator<int, string, mixed, void>
     */
    public function streamChat(array $messages, ChatOptions|array $options = []): \Generator;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     * @param ToolOptions|array<string, mixed> $options
     */
    public function chatWithTools(array $messages, array $tools, ToolOptions|array $options = []): CompletionResponse;

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
