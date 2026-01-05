<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * E2E tests for complete embedding workflows.
 *
 * Tests the full path from EmbeddingService through to provider
 * with caching integration.
 */
#[CoversClass(EmbeddingService::class)]
class EmbeddingWorkflowTest extends AbstractE2ETestCase
{
    #[Test]
    public function completeEmbeddingWorkflow(): void
    {
        // Arrange
        $responseData = $this->createOpenAiEmbeddingResponse(dimensions: 1536);

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($responseData),
        ]);

        $provider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKeyIdentifier' => 'sk-test']],
        ]);

        $adapterRegistry = self::createStub(ProviderAdapterRegistry::class);
        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger(), $adapterRegistry);
        $serviceManager->registerProvider($provider);
        // setHttpClient must be called AFTER registerProvider() since it calls configure()
        $provider->setHttpClient($httpClient);
        $serviceManager->setDefaultProvider('openai');

        // Mock cache manager
        $cacheManager = self::createStub(CacheManagerInterface::class);
        $cacheManager->method('getCachedEmbeddings')->willReturn(null);
        $cacheManager->method('cacheEmbeddings')->willReturn('cache-key');

        $embeddingService = new EmbeddingService($serviceManager, $cacheManager);

        // Act
        $result = $embeddingService->embedFull('Test text for embedding');

        // Assert
        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1, $result->embeddings);
        self::assertCount(1536, $result->embeddings[0]);
        self::assertEquals('text-embedding-3-small', $result->model);
    }

    #[Test]
    public function embeddingWithCacheHitWorkflow(): void
    {
        // Arrange - No HTTP client needed when cache hits
        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKeyIdentifier' => 'sk-test']],
        ]);

        $adapterRegistry = self::createStub(ProviderAdapterRegistry::class);
        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger(), $adapterRegistry);

        // Mock cache manager returning cached embeddings with full structure
        $cachedData = [
            'embeddings' => [
                array_map(fn() => $this->faker->randomFloat(8, -1, 1), range(1, 1536)),
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'promptTokens' => 10,
                'totalTokens' => 10,
            ],
        ];

        $cacheManager = self::createStub(CacheManagerInterface::class);
        $cacheManager->method('getCachedEmbeddings')
            ->willReturn($cachedData);

        $embeddingService = new EmbeddingService($serviceManager, $cacheManager);

        // Act - Should hit cache, not make HTTP request
        $result = $embeddingService->embedFull('Cached text');

        // Assert
        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1, $result->embeddings);
        self::assertCount(1536, $result->embeddings[0]);
    }

    #[Test]
    public function batchEmbeddingWorkflow(): void
    {
        // Arrange
        $responseData = [
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => array_map(
                        fn() => $this->faker->randomFloat(8, -1, 1),
                        range(1, 1536),
                    ),
                ],
                [
                    'object' => 'embedding',
                    'index' => 1,
                    'embedding' => array_map(
                        fn() => $this->faker->randomFloat(8, -1, 1),
                        range(1, 1536),
                    ),
                ],
                [
                    'object' => 'embedding',
                    'index' => 2,
                    'embedding' => array_map(
                        fn() => $this->faker->randomFloat(8, -1, 1),
                        range(1, 1536),
                    ),
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 30, 'total_tokens' => 30],
        ];

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($responseData),
        ]);

        $provider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKeyIdentifier' => 'sk-test']],
        ]);

        $adapterRegistry = self::createStub(ProviderAdapterRegistry::class);
        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger(), $adapterRegistry);
        $serviceManager->registerProvider($provider);
        // setHttpClient must be called AFTER registerProvider() since it calls configure()
        $provider->setHttpClient($httpClient);
        $serviceManager->setDefaultProvider('openai');

        $cacheManager = self::createStub(CacheManagerInterface::class);
        $cacheManager->method('getCachedEmbeddings')->willReturn(null);
        $cacheManager->method('cacheEmbeddings')->willReturn('cache-key');

        $embeddingService = new EmbeddingService($serviceManager, $cacheManager);

        // Act: Batch embedding
        $result = $embeddingService->embedBatch([
            'First text',
            'Second text',
            'Third text',
        ]);

        // Assert: embedBatch returns array of vectors directly
        self::assertCount(3, $result);
        foreach ($result as $embedding) {
            self::assertCount(1536, $embedding);
        }
    }

    #[Test]
    public function semanticSimilarityWorkflow(): void
    {
        // Arrange: Create two embeddings that would be similar
        $embedding1 = array_map(fn() => $this->faker->randomFloat(8, 0, 1), range(1, 1536));
        $embedding2 = array_map(fn($i) => $embedding1[$i - 1] + $this->faker->randomFloat(8, -0.1, 0.1), range(1, 1536));

        $response1 = [
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => $embedding1]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ];

        $response2 = [
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => $embedding2]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ];

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($response1),
            $this->createJsonResponse($response2),
        ]);

        $provider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKeyIdentifier' => 'sk-test']],
        ]);

        $adapterRegistry = self::createStub(ProviderAdapterRegistry::class);
        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger(), $adapterRegistry);
        $serviceManager->registerProvider($provider);
        // setHttpClient must be called AFTER registerProvider() since it calls configure()
        $provider->setHttpClient($httpClient);
        $serviceManager->setDefaultProvider('openai');

        $cacheManager = self::createStub(CacheManagerInterface::class);
        $cacheManager->method('getCachedEmbeddings')->willReturn(null);
        $cacheManager->method('cacheEmbeddings')->willReturn('cache-key');

        $embeddingService = new EmbeddingService($serviceManager, $cacheManager);

        // Act: Get embedding vectors (not full responses)
        $vector1 = $embeddingService->embed('Hello world');
        $vector2 = $embeddingService->embed('Hello world!');

        // Assert: Both should return valid embedding vectors
        self::assertCount(1536, $vector1);
        self::assertCount(1536, $vector2);

        // Calculate cosine similarity using service method
        $similarity = $embeddingService->cosineSimilarity($vector1, $vector2);

        // Similar texts should have similarity > 0.5
        self::assertGreaterThan(0.5, $similarity);
    }
}
