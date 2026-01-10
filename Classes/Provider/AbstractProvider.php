<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class AbstractProvider implements ProviderInterface
{
    use ResponseParserTrait;

    /**
     * Feature constants for provider capabilities.
     *
     * @deprecated Use ModelCapability enum instead
     */
    protected const FEATURE_CHAT = 'chat';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_COMPLETION = 'completion';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_EMBEDDINGS = 'embeddings';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_VISION = 'vision';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_STREAMING = 'streaming';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_TOOLS = 'tools';

    protected string $apiKeyIdentifier = '';
    protected string $baseUrl = '';
    protected string $defaultModel = '';
    protected int $timeout = 30;
    protected int $maxRetries = 3;

    /** @var array<string> */
    protected array $supportedFeatures = [];

    private ?ClientInterface $configuredHttpClient = null;

    public function __construct(
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly LoggerInterface $logger,
        protected readonly VaultServiceInterface $vault,
        protected readonly SecureHttpClientFactory $httpClientFactory,
    ) {}

    abstract public function getName(): string;

    abstract public function getIdentifier(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void
    {
        $this->apiKeyIdentifier = $this->getString($config, 'apiKeyIdentifier');
        $this->baseUrl = $this->getString($config, 'baseUrl', $this->getDefaultBaseUrl());
        $this->defaultModel = $this->getString($config, 'defaultModel', $this->getDefaultModel());
        $this->timeout = $this->getInt($config, 'timeout', 30);
        $this->maxRetries = $this->getInt($config, 'maxRetries', 3);

        // Reset HTTP client when configuration changes
        $this->configuredHttpClient = null;
    }

    abstract protected function getDefaultBaseUrl(): string;

    public function isAvailable(): bool
    {
        return $this->apiKeyIdentifier !== '' && $this->vault->exists($this->apiKeyIdentifier);
    }

    public function supportsFeature(string|ModelCapability $feature): bool
    {
        $featureValue = $feature instanceof ModelCapability ? $feature->value : $feature;
        return in_array($featureValue, $this->supportedFeatures, true);
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        return $this->chatCompletion([
            ['role' => 'user', 'content' => $prompt],
        ], $options);
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Set a custom HTTP client (primarily for testing).
     *
     * @param ClientInterface $client The HTTP client to use
     *
     * @internal This method is intended for testing purposes
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->configuredHttpClient = $client;
    }

    /**
     * Get HTTP client configured for authenticated requests.
     *
     * Uses nr-vault's VaultHttpClient for automatic secret injection
     * with audit logging.
     *
     * @return ClientInterface HTTP client with authentication configured
     */
    protected function getHttpClient(): ClientInterface
    {
        if ($this->configuredHttpClient !== null) {
            return $this->configuredHttpClient;
        }

        return $this->vault->http()->withAuthentication(
            $this->apiKeyIdentifier,
            $this->getSecretPlacement(),
            $this->getSecretPlacementOptions(),
        );
    }

    /**
     * Get the secret placement for authentication.
     *
     * Default is Bearer token. Providers can override for different auth methods.
     */
    protected function getSecretPlacement(): SecretPlacement
    {
        return SecretPlacement::Bearer;
    }

    /**
     * Get additional options for secret placement.
     *
     * Providers can override to specify custom header names, query params, etc.
     *
     * @return array{headerName?: string, queryParam?: string, reason?: string}
     */
    protected function getSecretPlacementOptions(): array
    {
        return ['reason' => sprintf('LLM API call to %s', $this->getName())];
    }

    /**
     * Retrieve the API key from vault.
     *
     * Use sparingly - prefer letting VaultHttpClient handle authentication.
     * This is needed for providers that embed the key in URLs (e.g., Gemini).
     */
    protected function retrieveApiKey(): string
    {
        if ($this->apiKeyIdentifier === '') {
            return '';
        }

        return $this->vault->retrieve($this->apiKeyIdentifier) ?? '';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sendRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        // Validate configuration before making API calls
        $this->validateConfiguration();

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json');

        $request = $this->addProviderSpecificHeaders($request);

        if ($method === 'POST' && $payload !== []) {
            $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
            $request = $request->withBody($body);
        }

        $httpClient = $this->getHttpClient();
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = $httpClient->sendRequest($request);
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $body = (string)$response->getBody();

                    return $this->decodeJsonResponse($body);
                }

                if ($statusCode >= 400 && $statusCode < 500) {
                    $body = (string)$response->getBody();
                    $decoded = json_decode($body, true);
                    $error = is_array($decoded) ? $this->asArray($decoded) : ['error' => ['message' => 'Unknown error']];
                    throw new ProviderResponseException(
                        $this->extractErrorMessage($error),
                        $statusCode,
                    );
                }

                throw new ProviderConnectionException(
                    sprintf('Server returned status %d', $statusCode),
                    $statusCode,
                );
            } catch (ProviderResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    usleep((int)(100000 * (2 ** $attempt)));
                }
            }
        }

        throw new ProviderConnectionException(
            'Failed to connect to provider after ' . $this->maxRetries . ' attempts: '
            . ($lastException?->getMessage() ?? 'Unknown error'),
            0,
            $lastException,
        );
    }

    protected function addProviderSpecificHeaders(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    /**
     * @param array<string, mixed> $error
     */
    protected function extractErrorMessage(array $error): string
    {
        // Try nested error.message first (OpenAI format)
        $nestedError = $this->getArray($error, 'error');
        if ($nestedError !== []) {
            $message = $this->getNullableString($nestedError, 'message');
            if ($message !== null) {
                return $message;
            }

            // Sometimes error is just a string
            $errorValue = $error['error'] ?? null;
            if (is_string($errorValue)) {
                return $errorValue;
            }
        }

        // Try direct message (some providers)
        $message = $this->getNullableString($error, 'message');
        if ($message !== null) {
            return $message;
        }

        return 'Unknown provider error';
    }

    protected function validateConfiguration(): void
    {
        if ($this->apiKeyIdentifier === '') {
            throw new ProviderConfigurationException(
                sprintf('API key identifier is required for provider %s', $this->getName()),
                1307337100,
            );
        }
    }

    protected function createUsageStatistics(int $promptTokens, int $completionTokens): UsageStatistics
    {
        return new UsageStatistics(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
        );
    }

    protected function createCompletionResponse(
        string $content,
        string $model,
        UsageStatistics $usage,
        ?string $finishReason = null,
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: $model,
            usage: $usage,
            finishReason: $finishReason ?? 'stop',
            provider: $this->getIdentifier(),
        );
    }

    /**
     * @param array<int, array<int, float>> $embeddings
     */
    protected function createEmbeddingResponse(
        array $embeddings,
        string $model,
        UsageStatistics $usage,
    ): EmbeddingResponse {
        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            usage: $usage,
            provider: $this->getIdentifier(),
        );
    }

    /**
     * Test the connection to the provider.
     *
     * Default implementation makes a simple HTTP request to verify connectivity.
     * Providers can override this for provider-specific connection tests.
     *
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        // Default: use getAvailableModels which makes an API call for most providers
        // Providers that return static lists should override this method
        $models = $this->getAvailableModels();

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($models)),
            'models' => $models,
        ];
    }
}
