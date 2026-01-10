<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\ConfigurationGenerator;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[CoversClass(ConfigurationGenerator::class)]
#[CoversClass(SuggestedConfiguration::class)]
class ConfigurationGeneratorTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ConfigurationGenerator $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = self::createStub(ClientInterface::class);
        $this->requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $this->streamFactoryStub = self::createStub(StreamFactoryInterface::class);

        $this->subject = new ConfigurationGenerator(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
        );
    }

    private function createJsonResponseStubForGenerator(int $statusCode, string $body): ResponseInterface&Stub
    {
        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('getContents')->willReturn($body);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($streamStub);

        return $responseStub;
    }

    private function createOpenAiProvider(): DetectedProvider
    {
        return new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );
    }

    private function createAnthropicProvider(): DetectedProvider
    {
        return new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com',
            suggestedName: 'Anthropic',
        );
    }

    private function createGeminiProvider(): DetectedProvider
    {
        return new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com',
            suggestedName: 'Google Gemini',
        );
    }

    /**
     * @return array<DiscoveredModel>
     */
    private function createTestModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'gpt-5.2',
                name: 'GPT-5.2',
                description: 'Advanced model',
                capabilities: ['chat', 'vision'],
                contextLength: 200000,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'gpt-4o',
                name: 'GPT-4o',
                description: 'Multimodal model',
                capabilities: ['chat'],
                contextLength: 128000,
                recommended: false,
            ),
        ];
    }

    // ==================== generate tests ====================

    #[Test]
    public function generateReturnsFallbackWhenNoModels(): void
    {
        $provider = $this->createOpenAiProvider();

        $result = $this->subject->generate($provider, 'test-key', []);

        self::assertNotEmpty($result);
    }

    #[Test]
    public function generateReturnsFallbackOnApiError(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(500, '{}'));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        // Should return fallback configurations
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateParsesOpenAiResponse(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            [
                                'identifier' => 'test-config',
                                'name' => 'Test Configuration',
                                'description' => 'A test configuration',
                                'systemPrompt' => 'You are a test assistant.',
                                'temperature' => 0.5,
                                'maxTokens' => 2048,
                            ],
                        ]),
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('test-config', $result[0]->identifier);
        self::assertEquals('Test Configuration', $result[0]->name);
        self::assertEquals('A test configuration', $result[0]->description);
        self::assertEquals(0.5, $result[0]->temperature);
    }

    #[Test]
    public function generateParsesAnthropicResponse(): void
    {
        $provider = $this->createAnthropicProvider();
        $models = [
            new DiscoveredModel(
                modelId: 'claude-opus-4-5',
                name: 'Claude Opus 4.5',
                description: 'Most capable',
                capabilities: ['chat'],
                contextLength: 200000,
                recommended: true,
            ),
        ];

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        [
                            'identifier' => 'anthropic-config',
                            'name' => 'Anthropic Config',
                            'description' => 'Test',
                            'system_prompt' => 'You are helpful.',
                            'temperature' => 0.3,
                            'max_tokens' => 4096,
                        ],
                    ]),
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('anthropic-config', $result[0]->identifier);
    }

    #[Test]
    public function generateParsesGeminiResponse(): void
    {
        $provider = $this->createGeminiProvider();
        $models = [
            new DiscoveredModel(
                modelId: 'gemini-3-flash',
                name: 'Gemini 3 Flash',
                description: 'Fast model',
                capabilities: ['chat'],
                contextLength: 1000000,
                recommended: true,
            ),
        ];

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    [
                                        'identifier' => 'gemini-config',
                                        'name' => 'Gemini Config',
                                        'description' => 'Test',
                                        'systemPrompt' => 'You are helpful.',
                                        'temperature' => 0.7,
                                        'maxTokens' => 8192,
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('gemini-config', $result[0]->identifier);
    }

    #[Test]
    public function generateHandlesMarkdownCodeBlock(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $jsonContent = (string)json_encode([
            [
                'identifier' => 'markdown-config',
                'name' => 'Markdown Config',
                'description' => 'Test',
                'systemPrompt' => 'Test prompt',
                'temperature' => 0.5,
                'maxTokens' => 2048,
            ],
        ]);

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => "Here's the configuration:\n```json\n{$jsonContent}\n```",
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('markdown-config', $result[0]->identifier);
    }

    #[Test]
    public function generateHandlesObjectWithConfigsKey(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'configurations' => [
                                [
                                    'identifier' => 'object-config',
                                    'name' => 'Object Config',
                                    'description' => 'Test',
                                    'systemPrompt' => 'Test prompt',
                                    'temperature' => 0.5,
                                    'maxTokens' => 2048,
                                ],
                            ],
                        ]),
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('object-config', $result[0]->identifier);
    }

    #[Test]
    public function generateSanitizesIdentifiers(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            [
                                'identifier' => 'Test_Config With  Spaces!!!',
                                'name' => 'Test Config',
                                'description' => 'Test',
                                'systemPrompt' => 'Test prompt',
                            ],
                        ]),
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('test-config-with-spaces', $result[0]->identifier);
    }

    #[Test]
    public function generateSelectsRecommendedModels(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = [
            new DiscoveredModel(
                modelId: 'not-recommended',
                name: 'Not Recommended',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 50000,
                recommended: false,
            ),
            new DiscoveredModel(
                modelId: 'recommended',
                name: 'Recommended',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 100000,
                recommended: true,
            ),
        ];

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        // Return fallback by making API fail
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(500, '{}'));

        $result = $this->subject->generate($provider, 'test-key', $models);

        // Check that fallback uses recommended model
        self::assertNotEmpty($result);
        self::assertEquals('recommended', $result[0]->recommendedModelId);
    }

    #[Test]
    public function generateUsesFirstModelWhenNoneRecommended(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = [
            new DiscoveredModel(
                modelId: 'first-model',
                name: 'First Model',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 50000,
                recommended: false,
            ),
        ];

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(500, '{}'));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('first-model', $result[0]->recommendedModelId);
    }

    #[Test]
    public function generateHandlesInvalidJsonResponse(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => 'This is not valid JSON at all!',
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        // Should return fallback configurations
        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateHandlesEmptyResponse(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => '[]',
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        // Should return fallback configurations
        self::assertNotEmpty($result);
    }

    #[Test]
    public function generateHandlesException(): void
    {
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Network error'));

        $result = $this->subject->generate($provider, 'test-key', $models);

        // Should return fallback configurations
        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    // ==================== SuggestedConfiguration tests ====================

    #[Test]
    public function suggestedConfigurationHasCorrectProperties(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'test-config',
            name: 'Test Configuration',
            description: 'A test configuration',
            systemPrompt: 'You are helpful.',
            recommendedModelId: 'gpt-5.2',
            temperature: 0.7,
            maxTokens: 4096,
        );

        self::assertEquals('test-config', $config->identifier);
        self::assertEquals('Test Configuration', $config->name);
        self::assertEquals('A test configuration', $config->description);
        self::assertEquals('You are helpful.', $config->systemPrompt);
        self::assertEquals('gpt-5.2', $config->recommendedModelId);
        self::assertEquals(0.7, $config->temperature);
        self::assertEquals(4096, $config->maxTokens);
    }

    #[Test]
    public function suggestedConfigurationToArrayWorks(): void
    {
        $config = new SuggestedConfiguration(
            identifier: 'test-config',
            name: 'Test Configuration',
            description: 'A test configuration',
            systemPrompt: 'You are helpful.',
            recommendedModelId: 'gpt-5.2',
            temperature: 0.7,
            maxTokens: 4096,
        );

        $array = $config->toArray();

        self::assertEquals('test-config', $array['identifier']);
        self::assertEquals('Test Configuration', $array['name']);
        self::assertEquals(0.7, $array['temperature']);
        self::assertEquals(4096, $array['maxTokens']);
    }

    // ==================== Fallback configuration tests ====================

    #[Test]
    public function fallbackConfigurationsContainExpectedPresets(): void
    {
        $provider = $this->createOpenAiProvider();

        $result = $this->subject->generate($provider, 'test-key', []);

        $identifiers = array_map(fn($c) => $c->identifier, $result);

        self::assertContains('content-assistant', $identifiers);
        self::assertContains('content-summarizer', $identifiers);
        self::assertContains('translator', $identifiers);
        self::assertContains('seo-optimizer', $identifiers);
        self::assertContains('code-assistant', $identifiers);
    }

    #[Test]
    public function fallbackConfigurationsHaveValidTemperatures(): void
    {
        $provider = $this->createOpenAiProvider();

        $result = $this->subject->generate($provider, 'test-key', []);

        foreach ($result as $config) {
            self::assertGreaterThanOrEqual(0.0, $config->temperature);
            self::assertLessThanOrEqual(2.0, $config->temperature);
        }
    }

    #[Test]
    public function fallbackConfigurationsHaveSystemPrompts(): void
    {
        $provider = $this->createOpenAiProvider();

        $result = $this->subject->generate($provider, 'test-key', []);

        foreach ($result as $config) {
            self::assertNotEmpty($config->systemPrompt);
        }
    }
}
