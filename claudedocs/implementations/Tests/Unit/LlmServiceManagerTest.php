<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Provider\ProviderFactory;
use Netresearch\NrLlm\Service\Provider\ProviderInterface;
use Netresearch\NrLlm\Service\Request\RequestBuilder;
use Netresearch\NrLlm\Service\Response\ResponseParser;
use Netresearch\NrLlm\Service\Stream\StreamHandler;
use Netresearch\NrLlm\Service\RateLimit\RateLimiter;
use Netresearch\NrLlm\Domain\Model\LlmResponse;
use Netresearch\NrLlm\Domain\Model\TokenUsage;
use Netresearch\NrLlm\Domain\Model\TranslationResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Exception\QuotaExceededException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for LlmServiceManager
 */
class LlmServiceManagerTest extends UnitTestCase
{
    private LlmServiceManager $subject;
    private ProviderInterface $mockProvider;
    private ProviderFactory $mockProviderFactory;
    private FrontendInterface $mockCache;
    private RateLimiter $mockRateLimiter;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProvider = $this->createMock(ProviderInterface::class);
        $this->mockProvider->method('getIdentifier')->willReturn('test-provider');

        $this->mockProviderFactory = $this->createMock(ProviderFactory::class);
        $this->mockProviderFactory->method('create')->willReturn($this->mockProvider);
        $this->mockProviderFactory->method('getDefaultProvider')->willReturn('openai');
        $this->mockProviderFactory->method('getAvailableProviders')->willReturn(['openai', 'anthropic']);

        $this->mockCache = $this->createMock(FrontendInterface::class);
        $this->mockRateLimiter = $this->createMock(RateLimiter::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->subject = new LlmServiceManager(
            $this->mockProviderFactory,
            new RequestBuilder(),
            new ResponseParser(),
            new StreamHandler(),
            $this->mockCache,
            $this->mockRateLimiter,
            $this->mockLogger
        );
    }

    /**
     * @test
     */
    public function completeCallsProviderWithCorrectParameters(): void
    {
        $prompt = 'Test prompt';
        $options = ['temperature' => 0.7, 'max_tokens' => 100];

        $expectedResponse = [
            'choices' => [
                [
                    'message' => ['content' => 'Test response'],
                    'finish_reason' => 'stop'
                ]
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30
            ]
        ];

        $this->mockProvider->expects($this->once())
            ->method('complete')
            ->with(
                $this->equalTo($prompt),
                $this->arrayHasKey('temperature')
            )
            ->willReturn($expectedResponse);

        $this->mockCache->method('get')->willReturn(false);

        $result = $this->subject->complete($prompt, $options);

        $this->assertInstanceOf(LlmResponse::class, $result);
        $this->assertEquals('Test response', $result->getContent());
        $this->assertEquals(10, $result->getPromptTokens());
        $this->assertEquals(20, $result->getCompletionTokens());
        $this->assertEquals(30, $result->getTotalTokens());
    }

    /**
     * @test
     */
    public function setProviderReturnsFluentInterface(): void
    {
        $result = $this->subject->setProvider('openai');

        $this->assertSame($this->subject, $result);
    }

    /**
     * @test
     */
    public function withOptionsReturnsFluentInterface(): void
    {
        $result = $this->subject->withOptions(['temperature' => 0.5]);

        $this->assertInstanceOf(LlmServiceManager::class, $result);
        // Should be a clone, not the same instance
        $this->assertNotSame($this->subject, $result);
    }

    /**
     * @test
     */
    public function withCacheReturnsFluentInterface(): void
    {
        $result = $this->subject->withCache(true, 7200);

        $this->assertInstanceOf(LlmServiceManager::class, $result);
        $this->assertNotSame($this->subject, $result);
    }

    /**
     * @test
     */
    public function withRateLimitReturnsFluentInterface(): void
    {
        $result = $this->subject->withRateLimit(false);

        $this->assertInstanceOf(LlmServiceManager::class, $result);
        $this->assertNotSame($this->subject, $result);
    }

    /**
     * @test
     */
    public function cacheIsUsedWhenAvailable(): void
    {
        $cachedResponse = new LlmResponse('Cached content');

        $this->mockCache->expects($this->once())
            ->method('get')
            ->willReturn($cachedResponse);

        // Provider should NOT be called
        $this->mockProvider->expects($this->never())
            ->method('complete');

        $result = $this->subject->complete('Test');

        $this->assertSame($cachedResponse, $result);
        $this->assertEquals('Cached content', $result->getContent());
    }

    /**
     * @test
     */
    public function cacheIsSetAfterSuccessfulRequest(): void
    {
        $providerResponse = [
            'choices' => [
                ['message' => ['content' => 'Response'], 'finish_reason' => 'stop']
            ]
        ];

        $this->mockProvider->method('complete')->willReturn($providerResponse);

        $this->mockCache->method('get')->willReturn(false);
        $this->mockCache->expects($this->once())
            ->method('set')
            ->with(
                $this->isType('string'),
                $this->isInstanceOf(LlmResponse::class),
                $this->equalTo([]),
                $this->equalTo(3600)
            );

        $this->subject->complete('Test');
    }

    /**
     * @test
     */
    public function rateLimitIsCheckedWhenEnabled(): void
    {
        $this->mockRateLimiter->expects($this->once())
            ->method('assertNotExceeded');

        $this->mockProvider->method('complete')->willReturn([
            'choices' => [['message' => ['content' => 'Response']]]
        ]);

        $this->mockCache->method('get')->willReturn(false);

        $this->subject->complete('Test');
    }

    /**
     * @test
     */
    public function rateLimitExceptionIsThrown(): void
    {
        $this->mockRateLimiter->method('assertNotExceeded')
            ->willThrowException(new QuotaExceededException('Quota exceeded'));

        $this->expectException(QuotaExceededException::class);
        $this->expectExceptionMessage('Quota exceeded');

        $this->subject->complete('Test');
    }

    /**
     * @test
     */
    public function translateReturnsTranslationResponse(): void
    {
        $translationResponse = new TranslationResponse(
            'Bonjour',
            0.95,
            ['Salut', 'Coucou']
        );

        $this->mockProvider->method('translate')
            ->with('Hello', 'fr', 'en')
            ->willReturn($translationResponse);

        $this->mockCache->method('get')->willReturn(false);

        $result = $this->subject->translate('Hello', 'fr', 'en');

        $this->assertInstanceOf(TranslationResponse::class, $result);
        $this->assertEquals('Bonjour', $result->getTranslation());
        $this->assertEquals(0.95, $result->getConfidence());
        $this->assertCount(2, $result->getAlternatives());
    }

    /**
     * @test
     */
    public function analyzeImageReturnsVisionResponse(): void
    {
        $visionResponse = new VisionResponse(
            'A dog playing in a park',
            ['dog', 'park', 'grass'],
            ['type' => 'outdoor', 'setting' => 'park'],
            0.92
        );

        $this->mockProvider->method('analyzeImage')
            ->with('https://example.com/image.jpg', 'Describe this image')
            ->willReturn($visionResponse);

        $this->mockCache->method('get')->willReturn(false);

        $result = $this->subject->analyzeImage('https://example.com/image.jpg', 'Describe this image');

        $this->assertInstanceOf(VisionResponse::class, $result);
        $this->assertEquals('A dog playing in a park', $result->getDescription());
        $this->assertContains('dog', $result->getObjects());
        $this->assertEquals('outdoor', $result->getScene()['type']);
        $this->assertEquals(0.92, $result->getConfidence());
    }

    /**
     * @test
     */
    public function embedReturnsEmbeddingResponse(): void
    {
        $embeddings = [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6]
        ];

        $embeddingResponse = new EmbeddingResponse($embeddings, 'text-embedding-ada-002');

        $this->mockProvider->method('embed')
            ->with(['Text 1', 'Text 2'])
            ->willReturn($embeddingResponse);

        $this->mockCache->method('get')->willReturn(false);

        $result = $this->subject->embed(['Text 1', 'Text 2']);

        $this->assertInstanceOf(EmbeddingResponse::class, $result);
        $this->assertCount(2, $result->getEmbeddings());
        $this->assertEquals(3, $result->getDimensions());
        $this->assertEquals('text-embedding-ada-002', $result->getModel());
    }

    /**
     * @test
     */
    public function getAvailableProvidersReturnsProviderList(): void
    {
        $providers = $this->subject->getAvailableProviders();

        $this->assertIsArray($providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
    }

    /**
     * @test
     */
    public function getDefaultProviderReturnsDefaultProvider(): void
    {
        $default = $this->subject->getDefaultProvider();

        $this->assertEquals('openai', $default);
    }

    /**
     * @test
     */
    public function getProviderReturnsProviderInstance(): void
    {
        $provider = $this->subject->getProvider();

        $this->assertInstanceOf(ProviderInterface::class, $provider);
    }

    /**
     * @test
     */
    public function highTemperatureDisablesCaching(): void
    {
        $this->mockProvider->method('complete')->willReturn([
            'choices' => [['message' => ['content' => 'Response']]]
        ]);

        $this->mockCache->method('get')->willReturn(false);

        // Should NOT cache with high temperature
        $this->mockCache->expects($this->never())->method('set');

        $this->subject->complete('Test', ['temperature' => 1.5]);
    }

    /**
     * @test
     */
    public function fluentApiWorks(): void
    {
        $this->mockProvider->method('complete')->willReturn([
            'choices' => [['message' => ['content' => 'Response']]]
        ]);

        $this->mockCache->method('get')->willReturn(false);

        $result = $this->subject
            ->setProvider('anthropic')
            ->withCache(false)
            ->withRateLimit(false)
            ->withOptions(['temperature' => 0.8])
            ->complete('Test prompt');

        $this->assertInstanceOf(LlmResponse::class, $result);
    }
}
