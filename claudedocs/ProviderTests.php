<?php

declare(strict_types=1);

namespace Netresearch\AiBase\Tests\Unit\Provider;

use Netresearch\AiBase\Exception\ConfigurationException;
use Netresearch\AiBase\Exception\NotSupportedException;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Service\Provider\DeepLProvider;
use Netresearch\AiBase\Service\Provider\GeminiProvider;
use Netresearch\AiBase\Service\Provider\OpenRouterProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit Tests for Secondary Providers.
 */

// =============================================================================
// GeminiProvider Tests
// =============================================================================

class GeminiProviderTest extends UnitTestCase
{
    private GeminiProvider $provider;
    private RequestFactory $requestFactory;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new GeminiProvider(
            [
                'apiKey' => 'test-api-key',
                'model' => 'gemini-1.5-flash',
                'safetyLevel' => 'BLOCK_MEDIUM_AND_ABOVE',
            ],
            $this->requestFactory,
            $this->logger,
        );
    }

    /**
     * @test
     */
    public function throwsExceptionWhenApiKeyMissing(): void
    {
        $this->expectException(ConfigurationException::class);

        new GeminiProvider(
            ['model' => 'gemini-1.5-flash'],
            $this->requestFactory,
            $this->logger,
        );
    }

    /**
     * @test
     */
    public function completionRequestSucceeds(): void
    {
        $mockResponse = $this->createMockResponse([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Generated response']],
                    ],
                    'finishReason' => 'STOP',
                    'safetyRatings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT', 'probability' => 'NEGLIGIBLE'],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
                'totalTokenCount' => 30,
            ],
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->complete('Test prompt');

        self::assertEquals('Generated response', $response->getContent());
        self::assertEquals(30, $response->getTokenUsage()['total_tokens']);
    }

    /**
     * @test
     */
    public function handlesMultimodalVisionRequest(): void
    {
        $mockResponse = $this->createMockResponse([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'This image shows a cat']],
                    ],
                    'finishReason' => 'STOP',
                    'safetyRatings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT', 'probability' => 'NEGLIGIBLE'],
                    ],
                ],
            ],
            'usageMetadata' => [
                'totalTokenCount' => 50,
            ],
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->analyzeImage('https://example.com/cat.jpg', 'Describe this image');

        self::assertEquals('This image shows a cat', $response->getDescription());
        self::assertGreaterThan(0.9, $response->getConfidence());
    }

    /**
     * @test
     */
    public function throwsExceptionOnSafetyBlock(): void
    {
        $mockResponse = $this->createMockResponse([
            'candidates' => [
                [
                    'finishReason' => 'SAFETY',
                    'safetyRatings' => [
                        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'probability' => 'HIGH'],
                    ],
                ],
            ],
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('blocked by safety filters');

        $this->provider->complete('Harmful content');
    }

    /**
     * @test
     */
    public function embeddingsRequestSucceeds(): void
    {
        $mockResponse = $this->createMockResponse([
            'embedding' => [
                'values' => array_fill(0, 768, 0.1),
            ],
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->embed('Test text');

        self::assertCount(1, $response->getEmbeddings());
        self::assertCount(768, $response->getEmbeddings()[0]);
    }

    /**
     * @test
     */
    public function estimatesCostCorrectly(): void
    {
        // Gemini 1.5 Flash: $0.075 per 1M input, $0.30 per 1M output (≤128K)
        $cost = $this->provider->estimateCost(100000, 50000, 'gemini-1.5-flash');

        $expectedCost = (100000 / 1_000_000) * 0.075 + (50000 / 1_000_000) * 0.30;

        self::assertEquals($expectedCost, $cost, '', 0.0001);
    }

    /**
     * @test
     */
    public function declaresCorrectCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        self::assertTrue($capabilities['completion']);
        self::assertTrue($capabilities['streaming']);
        self::assertTrue($capabilities['vision']);
        self::assertTrue($capabilities['embeddings']);
        self::assertTrue($capabilities['multimodal']);
        self::assertTrue($capabilities['safety_filtering']);
    }

    private function createMockResponse(array $data): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode($data));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}

// =============================================================================
// DeepLProvider Tests
// =============================================================================

class DeepLProviderTest extends UnitTestCase
{
    private DeepLProvider $provider;
    private RequestFactory $requestFactory;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new DeepLProvider(
            [
                'apiKey' => 'test-api-key',
                'tier' => 'free',
                'formality' => 'default',
            ],
            $this->requestFactory,
            $this->logger,
        );
    }

    /**
     * @test
     */
    public function throwsExceptionWhenApiKeyMissing(): void
    {
        $this->expectException(ConfigurationException::class);

        new DeepLProvider(
            ['tier' => 'free'],
            $this->requestFactory,
            $this->logger,
        );
    }

    /**
     * @test
     */
    public function translationRequestSucceeds(): void
    {
        $mockResponse = $this->createMockResponse([
            'translations' => [
                [
                    'text' => 'Hallo Welt',
                    'detected_source_language' => 'EN',
                ],
            ],
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->translate('Hello world', 'DE');

        self::assertEquals('Hallo Welt', $response->getTranslation());
        self::assertEquals('EN', $response->getSourceLanguage());
        self::assertEquals('DE', $response->getTargetLanguage());
    }

    /**
     * @test
     */
    public function batchTranslationSucceeds(): void
    {
        $mockResponse = $this->createMockResponse([
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ['text' => 'Welt', 'detected_source_language' => 'EN'],
            ],
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $responses = $this->provider->translateBatch(['Hello', 'World'], 'DE');

        self::assertCount(2, $responses);
        self::assertEquals('Hallo', $responses[0]->getTranslation());
        self::assertEquals('Welt', $responses[1]->getTranslation());
    }

    /**
     * @test
     */
    public function throwsExceptionOnUnsupportedLanguage(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unsupported target language');

        $this->provider->translate('Hello', 'XX');
    }

    /**
     * @test
     */
    public function completionThrowsNotSupportedException(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support completion');

        $this->provider->complete('Test prompt');
    }

    /**
     * @test
     */
    public function streamingThrowsNotSupportedException(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support streaming');

        $this->provider->stream('Test prompt', fn($x) => null);
    }

    /**
     * @test
     */
    public function embeddingsThrowsNotSupportedException(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support embeddings');

        $this->provider->embed('Test text');
    }

    /**
     * @test
     */
    public function visionThrowsNotSupportedException(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support vision');

        $this->provider->analyzeImage('https://example.com/image.jpg', 'Describe');
    }

    /**
     * @test
     */
    public function getUsageSucceeds(): void
    {
        $mockResponse = $this->createMockResponse([
            'character_count' => 123456,
            'character_limit' => 500000,
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $usage = $this->provider->getUsage();

        self::assertEquals(123456, $usage['character_count']);
        self::assertEquals(500000, $usage['character_limit']);
        self::assertEquals(24.69, $usage['usage_percent'], '', 0.01);
    }

    /**
     * @test
     */
    public function declaresCorrectCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        self::assertFalse($capabilities['completion']);
        self::assertFalse($capabilities['streaming']);
        self::assertFalse($capabilities['vision']);
        self::assertFalse($capabilities['embeddings']);
        self::assertTrue($capabilities['translation']); // ONLY translation
        self::assertTrue($capabilities['formality_control']);
        self::assertTrue($capabilities['glossary_support']);
    }

    private function createMockResponse(array $data): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode($data));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}

// =============================================================================
// OpenRouterProvider Tests
// =============================================================================

class OpenRouterProviderTest extends UnitTestCase
{
    private OpenRouterProvider $provider;
    private RequestFactory $requestFactory;
    private FrontendInterface $cache;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new OpenRouterProvider(
            [
                'apiKey' => 'test-api-key',
                'model' => 'anthropic/claude-3-sonnet',
                'autoFallback' => true,
                'fallbackModels' => 'anthropic/claude-3-haiku,openai/gpt-3.5-turbo',
                'routingStrategy' => 'balanced',
                'budgetLimit' => 100.0,
            ],
            $this->requestFactory,
            $this->cache,
            $this->logger,
        );
    }

    /**
     * @test
     */
    public function throwsExceptionWhenApiKeyMissing(): void
    {
        $this->expectException(ConfigurationException::class);

        new OpenRouterProvider(
            ['model' => 'test-model'],
            $this->requestFactory,
            $this->cache,
            $this->logger,
        );
    }

    /**
     * @test
     */
    public function completionRequestSucceeds(): void
    {
        $mockResponse = $this->createMockResponse([
            'id' => 'gen-123',
            'model' => 'anthropic/claude-3-sonnet',
            'choices' => [
                [
                    'message' => ['content' => 'Generated response'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
            'total_cost' => 0.0005,
            'provider' => 'Anthropic',
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->complete('Test prompt');

        self::assertEquals('Generated response', $response->getContent());
        self::assertEquals(0.0005, $response->getCost());
        self::assertEquals('Anthropic', $response->getMetadata()['provider']);
    }

    /**
     * @test
     */
    public function includesFallbackModelsInRequest(): void
    {
        $requestBody = null;

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturnCallback(function ($url, $method, $options) use (&$requestBody) {
                $requestBody = json_decode($options['body'], true);

                return $this->createMockResponse([
                    'choices' => [
                        ['message' => ['content' => 'Response'], 'finish_reason' => 'stop'],
                    ],
                    'usage' => ['total_tokens' => 30],
                ]);
            });

        $this->provider->complete('Test prompt');

        self::assertEquals('fallback', $requestBody['route']);
        self::assertContains('anthropic/claude-3-sonnet', $requestBody['models']);
        self::assertContains('anthropic/claude-3-haiku', $requestBody['models']);
    }

    /**
     * @test
     */
    public function getAvailableModelsReturnsModelList(): void
    {
        $mockResponse = $this->createMockResponse([
            'data' => [
                [
                    'id' => 'anthropic/claude-3-opus',
                    'name' => 'Claude 3 Opus',
                    'context_length' => 200000,
                    'pricing' => ['prompt' => 0.000015, 'completion' => 0.000075],
                    'architecture' => ['modality' => 'multimodal'],
                ],
                [
                    'id' => 'openai/gpt-4-turbo',
                    'name' => 'GPT-4 Turbo',
                    'context_length' => 128000,
                    'pricing' => ['prompt' => 0.00001, 'completion' => 0.00003],
                ],
            ],
        ]);

        $this->cache->method('get')->willReturn(false);
        $this->cache->expects(self::once())->method('set');

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $models = $this->provider->getAvailableModels();

        self::assertArrayHasKey('anthropic/claude-3-opus', $models);
        self::assertArrayHasKey('openai/gpt-4-turbo', $models);
        self::assertEquals(200000, $models['anthropic/claude-3-opus']['context_length']);
    }

    /**
     * @test
     */
    public function estimatesCostCorrectly(): void
    {
        $this->cache->method('get')->willReturn([
            'anthropic/claude-3-sonnet' => [
                'pricing' => [
                    'prompt' => 0.000003,      // $3 per 1M tokens
                    'completion' => 0.000015,  // $15 per 1M tokens
                ],
            ],
        ]);

        $cost = $this->provider->estimateCost(100000, 50000, 'anthropic/claude-3-sonnet');

        $expectedCost = (100000 * 0.000003) + (50000 * 0.000015);

        self::assertEquals($expectedCost, $cost, '', 0.0001);
    }

    /**
     * @test
     */
    public function visionRequestSelectsVisionCapableModel(): void
    {
        $this->cache->method('get')->willReturn([
            'anthropic/claude-3-opus' => [
                'capabilities' => ['vision' => true],
            ],
        ]);

        $mockResponse = $this->createMockResponse([
            'choices' => [
                ['message' => ['content' => 'Image description'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['total_tokens' => 50],
            'total_cost' => 0.001,
        ]);

        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->analyzeImage('https://example.com/image.jpg', 'Describe');

        self::assertEquals('Image description', $response->getDescription());
    }

    /**
     * @test
     */
    public function declaresCorrectCapabilities(): void
    {
        $this->cache->method('get')->willReturn([]);

        $capabilities = $this->provider->getCapabilities();

        self::assertTrue($capabilities['completion']);
        self::assertTrue($capabilities['streaming']);
        self::assertTrue($capabilities['vision']);
        self::assertTrue($capabilities['embeddings']);
        self::assertTrue($capabilities['translation']);
        self::assertTrue($capabilities['multi_provider']);
        self::assertTrue($capabilities['automatic_fallback']);
        self::assertTrue($capabilities['exact_cost_tracking']);
    }

    private function createMockResponse(array $data): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode($data));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}

// =============================================================================
// Integration Tests
// =============================================================================

class ProviderIntegrationTest extends UnitTestCase
{
    /**
     * @test
     */
    public function deeplFallsBackToLlmProviderWhenUnavailable(): void
    {
        // This would be tested in integration with AiServiceManager
        self::markTestIncomplete('Integration test with AiServiceManager');
    }

    /**
     * @test
     */
    public function openRouterFallsBackToSecondaryModel(): void
    {
        // Test that OpenRouter's fallback mechanism works
        self::markTestIncomplete('Integration test with model fallback');
    }

    /**
     * @test
     */
    public function costTrackingWorksAcrossProviders(): void
    {
        // Test cost tracking and quota enforcement
        self::markTestIncomplete('Integration test with UsageRepository');
    }
}
