<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Exception;
use Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
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
    ) {
        $this->loadConfiguration();
    }

    private function loadConfiguration(): void
    {
        try {
            $this->configuration = $this->extensionConfiguration->get('nr_llm');
            $this->defaultProvider = $this->configuration['defaultProvider'] ?? null;
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
        $providerConfig = $this->configuration['providers'][$identifier] ?? [];
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
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chat(array $messages, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $provider = $this->getProvider($optionsArray['provider'] ?? null);
        unset($optionsArray['provider']);

        return $provider->chatCompletion($messages, $optionsArray);
    }

    /**
     * Send a simple completion request.
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $provider = $this->getProvider($optionsArray['provider'] ?? null);
        unset($optionsArray['provider']);

        return $provider->complete($prompt, $optionsArray);
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
        $provider = $this->getProvider($optionsArray['provider'] ?? null);
        unset($optionsArray['provider']);

        if (!$provider->supportsFeature('embeddings')) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support embeddings', $provider->getIdentifier()),
                8701213030,
            );
        }

        return $provider->embeddings($input, $optionsArray);
    }

    /**
     * Analyze an image with vision capabilities.
     *
     * @param array<int, array{type: string, image_url?: array{url: string}, text?: string}> $content
     */
    public function vision(array $content, ?VisionOptions $options = null): VisionResponse
    {
        $options ??= new VisionOptions();
        $optionsArray = $options->toArray();
        $provider = $this->getProvider($optionsArray['provider'] ?? null);
        unset($optionsArray['provider']);

        if (!$provider instanceof VisionCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support vision', $provider->getIdentifier()),
                5549344501,
            );
        }

        return $provider->analyzeImage($content, $optionsArray);
    }

    /**
     * Stream a chat completion response.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChat(array $messages, ?ChatOptions $options = null): Generator
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $provider = $this->getProvider($optionsArray['provider'] ?? null);
        unset($optionsArray['provider']);

        if (!$provider instanceof StreamingCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support streaming', $provider->getIdentifier()),
                1581627129,
            );
        }

        return $provider->streamChatCompletion($messages, $optionsArray);
    }

    /**
     * Chat completion with tool calling.
     *
     * @param array<int, array{role: string, content: string}>                                                                      $messages
     * @param array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $tools
     */
    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        $optionsArray = $options->toArray();
        $provider = $this->getProvider($optionsArray['provider'] ?? null);
        unset($optionsArray['provider']);

        if (!$provider instanceof ToolCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support tool calling', $provider->getIdentifier()),
                9324699785,
            );
        }

        return $provider->chatCompletionWithTools($messages, $tools, $optionsArray);
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
        return $this->configuration['providers'][$identifier] ?? [];
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
}
