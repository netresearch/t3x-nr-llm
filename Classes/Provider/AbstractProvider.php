<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use GuzzleHttp\Client as GuzzleClient;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class AbstractProvider implements ProviderInterface
{
    use ResponseParserTrait;

    protected const FEATURE_CHAT = 'chat';
    protected const FEATURE_COMPLETION = 'completion';
    protected const FEATURE_EMBEDDINGS = 'embeddings';
    protected const FEATURE_VISION = 'vision';
    protected const FEATURE_STREAMING = 'streaming';
    protected const FEATURE_TOOLS = 'tools';

    protected string $apiKey = '';
    protected string $baseUrl = '';
    protected string $defaultModel = '';
    protected int $timeout = 30;
    protected int $maxRetries = 3;

    /** @var array<string> */
    protected array $supportedFeatures = [];

    private ?ClientInterface $configuredHttpClient = null;
    private int $configuredTimeout = 0;

    public function __construct(
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly LoggerInterface $logger,
    ) {}

    abstract public function getName(): string;

    abstract public function getIdentifier(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void
    {
        $this->apiKey = $this->getString($config, 'apiKey');
        $this->baseUrl = $this->getString($config, 'baseUrl', $this->getDefaultBaseUrl());
        $this->defaultModel = $this->getString($config, 'defaultModel', $this->getDefaultModel());
        $this->timeout = $this->getInt($config, 'timeout', 30);
        $this->maxRetries = $this->getInt($config, 'maxRetries', 3);

        // Note: Configuration is validated lazily when sendRequest() is called.
        // This allows providers to be registered without throwing during DI initialization.
    }

    abstract protected function getDefaultBaseUrl(): string;

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
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
        // Mark as configured so getHttpClient() doesn't recreate it
        $this->configuredTimeout = $this->timeout;
    }

    /**
     * Get HTTP client configured with the current timeout.
     * Creates a new client if timeout has changed.
     *
     * @return ClientInterface HTTP client with configured timeout
     */
    protected function getHttpClient(): ClientInterface
    {
        // Create new client if timeout changed or not yet created
        if ($this->configuredHttpClient === null || $this->configuredTimeout !== $this->timeout) {
            $this->configuredHttpClient = new GuzzleClient([
                'timeout' => $this->timeout,
                'connect_timeout' => min($this->timeout, 10), // Connection timeout max 10s
            ]);
            $this->configuredTimeout = $this->timeout;
        }

        return $this->configuredHttpClient;
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
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey);

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
        if ($this->apiKey === '') {
            throw new ProviderConfigurationException(
                sprintf('API key is required for provider %s', $this->getName()),
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
     * @return array{success: bool, message: string, models?: array<string, string>}
     *
     * @throws ProviderConnectionException on connection failure
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
