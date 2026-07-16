<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\ConfigurationGenerator;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ConfigurationGenerator::class)]
#[CoversClass(SuggestedConfiguration::class)]
class ConfigurationGeneratorTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private VaultServiceInterface $vaultStub;
    private LoggerInterface&Stub $loggerStub;
    private ConfigurationGenerator $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = self::createStub(ClientInterface::class);
        $this->requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $this->streamFactoryStub = self::createStub(StreamFactoryInterface::class);
        $this->vaultStub = $this->createVaultServiceMock();
        $this->loggerStub = self::createStub(LoggerInterface::class);

        $this->subject = new ConfigurationGenerator(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->loggerStub,
        );
        // Test seam: requests bypass the vault secure client so the stubbed
        // HTTP responses drive the test. The SSRF test uses a fresh subject
        // WITHOUT the seam to exercise the host gate.
        $this->subject->setHttpClient($this->httpClientStub);
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
    public function generateRejectsDisallowedHostAndReturnsFallback(): void
    {
        // No setHttpClient() seam → dispatch() runs the real isHostAllowed()
        // gate. The cloud metadata IP (169.254.169.254) is always blocked, so
        // the request never reaches the wire and generate() fails soft.
        $subject = new ConfigurationGenerator(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->loggerStub,
        );

        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://169.254.169.254/v1',
            suggestedName: 'Metadata SSRF',
        );

        $result = $subject->generate($provider, 'test-key', $this->createTestModels());

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result));
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

    // ==================== API response body is not valid JSON (non-array) ====================

    #[Test]
    public function generateReturnsFallbackWhenApiBodyIsNotValidJson(): void
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

        // The outer response body itself decodes to a non-array (a string)
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '"just a string"'));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    // ==================== extractAnthropicContent edge cases ====================

    #[Test]
    public function generateAnthropicReturnsFallbackWhenContentIsEmpty(): void
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

        // content is an empty array — extractAnthropicContent returns ''
        $llmResponse = (string)json_encode(['content' => []]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        // Empty content → empty string → parse fails → fallback
        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateAnthropicReturnsFallbackWhenFirstBlockIsNotArray(): void
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

        // First block is a scalar, not an array
        $llmResponse = (string)json_encode(['content' => ['not-an-array']]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateAnthropicReturnsFallbackWhenTextIsNotString(): void
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

        // text is an int, not a string
        $llmResponse = (string)json_encode(['content' => [['text' => 12345]]]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    // ==================== extractGeminiContent edge cases ====================

    #[Test]
    public function generateGeminiReturnsFallbackWhenCandidatesIsEmpty(): void
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

        $llmResponse = (string)json_encode(['candidates' => []]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateGeminiReturnsFallbackWhenFirstCandidateIsNotArray(): void
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

        $llmResponse = (string)json_encode(['candidates' => ['not-an-array']]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateGeminiReturnsFallbackWhenContentIsNotArray(): void
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

        $llmResponse = (string)json_encode(['candidates' => [['content' => 'not-an-array']]]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateGeminiReturnsFallbackWhenPartsIsEmpty(): void
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

        $llmResponse = (string)json_encode(['candidates' => [['content' => ['parts' => []]]]]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateGeminiReturnsFallbackWhenFirstPartIsNotArray(): void
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
            'candidates' => [['content' => ['parts' => ['not-an-array']]]],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateGeminiReturnsFallbackWhenTextIsNotString(): void
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
            'candidates' => [['content' => ['parts' => [['text' => 999]]]]],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    // ==================== extractOpenAIContent edge cases ====================

    #[Test]
    public function generateOpenAiReturnsFallbackWhenChoicesIsEmpty(): void
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

        $llmResponse = (string)json_encode(['choices' => []]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateOpenAiReturnsFallbackWhenFirstChoiceIsNotArray(): void
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

        $llmResponse = (string)json_encode(['choices' => ['not-an-array']]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateOpenAiReturnsFallbackWhenMessageIsNotArray(): void
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

        $llmResponse = (string)json_encode(['choices' => [['message' => 'not-an-array']]]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    #[Test]
    public function generateOpenAiReturnsFallbackWhenContentIsNotString(): void
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

        $llmResponse = (string)json_encode(['choices' => [['message' => ['content' => ['not', 'a', 'string']]]]]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(fn($c) => $c->identifier, $result));
    }

    // ==================== parseResponse edge cases ====================

    #[Test]
    public function generateHandlesObjectWithConfigsKeyAlias(): void
    {
        // Tests the `$json['configs']` branch (alias to 'configurations')
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
                            'configs' => [
                                [
                                    'identifier' => 'alias-config',
                                    'name' => 'Alias Config',
                                    'description' => 'Via configs key',
                                    'systemPrompt' => 'Test.',
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
        self::assertEquals('alias-config', $result[0]->identifier);
    }

    #[Test]
    public function generateSkipsItemsWithMissingIdentifierOrName(): void
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
                            // Missing identifier
                            ['name' => 'No Identifier', 'description' => 'Test'],
                            // Missing name
                            ['identifier' => 'no-name', 'description' => 'Test'],
                            // Non-array item
                            'just a string',
                            // Valid item
                            [
                                'identifier' => 'valid-item',
                                'name' => 'Valid Item',
                                'description' => 'Works',
                                'systemPrompt' => 'Help.',
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

        self::assertCount(1, $result);
        self::assertEquals('valid-item', $result[0]->identifier);
    }

    #[Test]
    public function generateReturnsFallbackWhenConfigurationsKeyIsNotAnArray(): void
    {
        // Tests parseResponse line 374: $items is set via $json['configurations'] but is not an array
        $provider = $this->createOpenAiProvider();
        $models = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        // 'configurations' is a string, not an array — triggers the !is_array($items) branch
        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'configurations' => 'this-should-be-an-array-but-is-not',
                        ]),
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        // parseResponse returns [] for non-array items, so fallback configs are used
        self::assertNotEmpty($result);
        foreach ($result as $config) {
            self::assertInstanceOf(SuggestedConfiguration::class, $config);
        }
    }

    #[Test]
    public function generateHandlesResponseWithEmbeddedJsonInText(): void
    {
        // Tests the regex branch: find JSON array/object embedded in text
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
                'identifier' => 'embedded-config',
                'name' => 'Embedded Config',
                'description' => 'Found via regex',
                'systemPrompt' => 'Help.',
            ],
        ]);

        // The outer OpenAI response wraps content with text around the JSON
        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Here is the JSON: ' . $jsonContent . ' That is all.',
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, $llmResponse));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertEquals('embedded-config', $result[0]->identifier);
    }

    // ==================== outgoing request capture ====================

    /**
     * Install capturing callbacks on the request/stream factory stubs so a
     * test can assert on the exact HTTP request the generator builds
     * (method, URI, headers, JSON body). The request stub records every
     * withHeader() call and returns itself for chaining, mirroring the real
     * PSR-7 immutable-with pattern closely enough for assertions.
     *
     * @param array<string, mixed> $capturedHeaders
     */
    private function captureOutgoingRequest(
        string &$capturedMethod,
        string &$capturedUri,
        array &$capturedHeaders,
        string &$capturedBody,
    ): void {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturnCallback(
                function (string $method, string $uri) use (&$capturedMethod, &$capturedUri, &$capturedHeaders): RequestInterface {
                    $capturedMethod = $method;
                    $capturedUri    = $uri;

                    $uriStub = self::createStub(UriInterface::class);
                    $uriStub->method('getHost')->willReturn((string)(parse_url($uri, PHP_URL_HOST) ?? ''));

                    $request = self::createStub(RequestInterface::class);
                    $request->method('getMethod')->willReturn($method);
                    $request->method('getUri')->willReturn($uriStub);
                    $request->method('withHeader')->willReturnCallback(
                        function (string $name, mixed $value) use (&$capturedHeaders, $request): RequestInterface {
                            $capturedHeaders[$name] = $value;
                            return $request;
                        },
                    );
                    $request->method('withBody')->willReturnCallback(fn(): RequestInterface => $request);

                    return $request;
                },
            );

        $this->streamFactoryStub
            ->method('createStream')
            ->willReturnCallback(
                function (string $content) use (&$capturedBody): StreamInterface {
                    $capturedBody = $content;
                    $stream       = self::createStub(StreamInterface::class);
                    $stream->method('getContents')->willReturn($content);
                    return $stream;
                },
            );
    }

    #[Test]
    public function generateSendsExpectedOpenAiRequest(): void
    {
        $provider = $this->createOpenAiProvider();
        $models   = $this->createTestModels();

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        // Method + endpoint (endpoint helper builds it from the provider URL).
        self::assertSame('POST', $capturedMethod);
        self::assertSame('https://api.openai.com/chat/completions', $capturedUri);

        // Headers: content type + bearer auth, no anthropic key.
        self::assertArrayHasKey('Content-Type', $capturedHeaders);
        $contentType = $capturedHeaders['Content-Type'];
        self::assertIsString($contentType);
        self::assertSame('application/json', $contentType);

        self::assertArrayHasKey('Authorization', $capturedHeaders);
        $authorization = $capturedHeaders['Authorization'];
        self::assertIsString($authorization);
        self::assertSame('Bearer test-key', $authorization);

        self::assertArrayNotHasKey('x-api-key', $capturedHeaders);
        self::assertArrayNotHasKey('anthropic-version', $capturedHeaders);

        // Body: OpenAI chat-completions shape.
        $body = json_decode($capturedBody, true);
        self::assertIsArray($body);

        // Selected model is the recommended, higher-context one (gpt-5.2).
        self::assertSame('gpt-5.2', $body['model'] ?? null);
        self::assertSame(0.7, $body['temperature'] ?? null);
        self::assertSame(4096, $body['max_tokens'] ?? null);
        self::assertSame(['type' => 'json_object'], $body['response_format'] ?? null);

        self::assertArrayHasKey('messages', $body);
        $messages = $body['messages'];
        self::assertIsArray($messages);
        self::assertCount(2, $messages);

        self::assertSame('system', $messages[0]['role'] ?? null);
        $systemContent = $messages[0]['content'] ?? null;
        self::assertIsString($systemContent);
        self::assertStringStartsWith('You are an expert at configuring LLM integrations.', $systemContent);

        self::assertSame('user', $messages[1]['role'] ?? null);
        self::assertSame(
            "Available models:\n"
            . "- GPT-5.2 (gpt-5.2): Advanced model\n"
            . "- GPT-4o (gpt-4o): Multimodal model\n\n"
            . 'Generate configuration presets that work well with these models.',
            $messages[1]['content'] ?? null,
        );
    }

    #[Test]
    public function generateStripsTrailingSlashFromOpenAiEndpoint(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com/',
            suggestedName: 'OpenAI',
        );
        $models = $this->createTestModels();

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        // rtrim() removes exactly one trailing slash — no double slash.
        self::assertSame('https://api.openai.com/chat/completions', $capturedUri);
    }

    #[Test]
    public function generateSendsExpectedAnthropicRequest(): void
    {
        $provider = $this->createAnthropicProvider();
        $models   = [
            new DiscoveredModel(
                modelId: 'claude-opus-4-5',
                name: 'Claude Opus 4.5',
                description: 'Most capable',
                capabilities: ['chat'],
                contextLength: 200000,
                recommended: true,
            ),
        ];

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('https://api.anthropic.com/messages', $capturedUri);

        // Anthropic auth headers, not a bearer token.
        self::assertArrayHasKey('x-api-key', $capturedHeaders);
        $apiKeyHeader = $capturedHeaders['x-api-key'];
        self::assertIsString($apiKeyHeader);
        self::assertSame('test-key', $apiKeyHeader);

        self::assertArrayHasKey('anthropic-version', $capturedHeaders);
        $versionHeader = $capturedHeaders['anthropic-version'];
        self::assertIsString($versionHeader);
        self::assertSame('2023-06-01', $versionHeader);

        self::assertArrayHasKey('Content-Type', $capturedHeaders);
        $contentType = $capturedHeaders['Content-Type'];
        self::assertIsString($contentType);
        self::assertSame('application/json', $contentType);

        self::assertArrayNotHasKey('Authorization', $capturedHeaders);

        // Body: Anthropic messages shape (system top-level, no response_format).
        $body = json_decode($capturedBody, true);
        self::assertIsArray($body);
        self::assertSame('claude-opus-4-5', $body['model'] ?? null);
        self::assertSame(0.7, $body['temperature'] ?? null);
        self::assertSame(4096, $body['max_tokens'] ?? null);
        self::assertArrayNotHasKey('response_format', $body);
        self::assertArrayNotHasKey('contents', $body);

        $system = $body['system'] ?? null;
        self::assertIsString($system);
        self::assertStringStartsWith('You are an expert at configuring LLM integrations.', $system);

        self::assertArrayHasKey('messages', $body);
        $messages = $body['messages'];
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role'] ?? null);
        self::assertSame(
            "Available models:\n"
            . "- Claude Opus 4.5 (claude-opus-4-5): Most capable\n\n"
            . "Generate configuration presets that work well with these models.\n\n"
            . 'Respond with valid JSON only.',
            $messages[0]['content'] ?? null,
        );
    }

    #[Test]
    public function generateSendsExpectedGeminiRequest(): void
    {
        $provider = $this->createGeminiProvider();
        $models   = [
            new DiscoveredModel(
                modelId: 'gemini-3-flash',
                name: 'Gemini 3 Flash',
                description: 'Fast model',
                capabilities: ['chat'],
                contextLength: 1000000,
                recommended: true,
            ),
        ];

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        self::assertSame('POST', $capturedMethod);
        self::assertSame(
            'https://generativelanguage.googleapis.com/models/gemini-3-flash:generateContent',
            $capturedUri,
        );

        // Gemini carries the key in the URL — no auth headers are added.
        self::assertArrayHasKey('Content-Type', $capturedHeaders);
        $contentType = $capturedHeaders['Content-Type'];
        self::assertIsString($contentType);
        self::assertSame('application/json', $contentType);
        self::assertArrayNotHasKey('Authorization', $capturedHeaders);
        self::assertArrayNotHasKey('x-api-key', $capturedHeaders);

        // Body: Gemini contents/generationConfig shape.
        $body = json_decode($capturedBody, true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('model', $body);
        self::assertArrayNotHasKey('messages', $body);

        self::assertArrayHasKey('generationConfig', $body);
        $generationConfig = $body['generationConfig'];
        self::assertIsArray($generationConfig);
        self::assertSame(0.7, $generationConfig['temperature'] ?? null);
        self::assertSame(4096, $generationConfig['maxOutputTokens'] ?? null);
        self::assertSame('application/json', $generationConfig['responseMimeType'] ?? null);

        self::assertArrayHasKey('contents', $body);
        $contents = $body['contents'];
        self::assertIsArray($contents);
        $text = $contents[0]['parts'][0]['text'] ?? null;
        self::assertIsString($text);
        self::assertStringStartsWith('You are an expert at configuring LLM integrations.', $text);
        // SYSTEM_PROMPT (ending "…code/technical assistance.") and the model
        // prompt are joined by exactly one blank line ("\n\n").
        self::assertStringContainsString(
            "and code/technical assistance.\n\nAvailable models:",
            $text,
        );
        self::assertStringContainsString(
            "Available models:\n- Gemini 3 Flash (gemini-3-flash): Fast model",
            $text,
        );
        self::assertStringEndsWith("\n\nRespond with valid JSON only.", $text);
    }

    #[Test]
    public function generatePromptListsAtMostFirstFiveModels(): void
    {
        $provider = $this->createOpenAiProvider();
        $models   = [];
        for ($i = 1; $i <= 6; $i++) {
            $models[] = new DiscoveredModel(
                modelId: 'model-' . $i,
                name: 'Model ' . $i,
                description: 'Description ' . $i,
                capabilities: ['chat'],
                contextLength: 1000 - $i, // strictly descending, first stays first
                recommended: false,
            );
        }

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        $body = json_decode($capturedBody, true);
        self::assertIsArray($body);
        $messages = $body['messages'] ?? null;
        self::assertIsArray($messages);
        $userContent = $messages[1]['content'] ?? null;
        self::assertIsString($userContent);

        // First five models are listed, in order; the sixth is dropped.
        self::assertStringContainsString('- Model 1 (model-1): Description 1', $userContent);
        self::assertStringContainsString('- Model 5 (model-5): Description 5', $userContent);
        self::assertStringNotContainsString('Model 6', $userContent);
    }

    #[Test]
    public function generateSelectsHigherContextModelAmongRecommended(): void
    {
        $provider = $this->createOpenAiProvider();
        $models   = [
            new DiscoveredModel(
                modelId: 'small-context',
                name: 'Small',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 100000,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'large-context',
                name: 'Large',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 200000,
                recommended: true,
            ),
        ];

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        $body = json_decode($capturedBody, true);
        self::assertIsArray($body);
        // Descending sort by context length picks the 200k model.
        self::assertSame('large-context', $body['model'] ?? null);
    }

    #[Test]
    public function generatePrefersRecommendedOverHigherContextNonRecommended(): void
    {
        $provider = $this->createOpenAiProvider();
        $models   = [
            new DiscoveredModel(
                modelId: 'huge-non-recommended',
                name: 'Huge',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 900000,
                recommended: false,
            ),
            new DiscoveredModel(
                modelId: 'recommended-model',
                name: 'Recommended',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 100000,
                recommended: true,
            ),
        ];

        $capturedMethod  = '';
        $capturedUri     = '';
        $capturedHeaders = [];
        $capturedBody    = '';
        $this->captureOutgoingRequest($capturedMethod, $capturedUri, $capturedHeaders, $capturedBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator(200, '{}'));

        $this->subject->generate($provider, 'test-key', $models);

        $body = json_decode($capturedBody, true);
        self::assertIsArray($body);
        // The recommended filter wins even though a non-recommended model has
        // a much larger context length.
        self::assertSame('recommended-model', $body['model'] ?? null);
    }

    #[Test]
    public function generateUsesAvailableModelAndParsesWhenNoneRecommended(): void
    {
        // With no recommended models, selectGenerationModel falls back to the
        // full list (rather than returning null): the API call still runs and
        // its parsed result is returned instead of the static fallback.
        $provider = $this->createOpenAiProvider();
        $models   = [
            new DiscoveredModel(
                modelId: 'only-model',
                name: 'Only Model',
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

        $llmResponse = (string)json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            [
                                'identifier' => 'parsed-from-non-recommended',
                                'name' => 'Parsed',
                                'description' => 'Test',
                                'systemPrompt' => 'Help.',
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

        self::assertCount(1, $result);
        self::assertSame('parsed-from-non-recommended', $result[0]->identifier);
        self::assertSame('only-model', $result[0]->recommendedModelId);
    }

    #[Test]
    public function generateLogsSanitizedWarningContextOnFailure(): void
    {
        $provider = $this->createAnthropicProvider();
        $models   = $this->createTestModels();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())
            ->method('warning')
            ->with(
                'LLM setup-wizard configuration generation failed; using fallback presets',
                self::callback(static function (mixed $context): bool {
                    self::assertIsArray($context);
                    self::assertArrayHasKey('provider', $context);
                    self::assertArrayHasKey('exception', $context);
                    self::assertSame('anthropic', $context['provider']);
                    return true;
                }),
            );

        $subject = new ConfigurationGenerator(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $loggerMock,
        );
        $subject->setHttpClient($this->httpClientStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Network error'));

        $result = $subject->generate($provider, 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertContains('content-assistant', array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result));
    }

    // ==================== second-pass mutation kills ====================

    /**
     * Stub the full HTTP round trip: request factory, stream factory and
     * HTTP client return canned objects so generate() sees $body as the
     * API response.
     */
    private function stubHttpRoundTrip(int $statusCode, string $body): void
    {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForGenerator($statusCode, $body));
    }

    /**
     * Wrap an LLM "content" string in the OpenAI chat-completions
     * response envelope.
     */
    private function openAiResponseWithContent(string $content): string
    {
        return (string)json_encode(['choices' => [['message' => ['content' => $content]]]]);
    }

    /**
     * Build a subject wired to the shared stubs but with a caller-supplied
     * logger (mock), including the HTTP-client test seam.
     */
    private function createSubjectWithLogger(LoggerInterface $logger): ConfigurationGenerator
    {
        $subject = new ConfigurationGenerator(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $logger,
        );
        $subject->setHttpClient($this->httpClientStub);

        return $subject;
    }

    #[Test]
    public function generateWithoutModelsReturnsFallbackWithoutLoggingAWarning(): void
    {
        // With no models the guard returns the fallback BEFORE the try
        // block: no LLM call is attempted, so no warning may be logged.
        // (Removing the guard's return would TypeError on callLLM(model:
        // null) inside the try and log a warning.)
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::never())->method('warning');

        $subject = $this->createSubjectWithLogger($loggerMock);

        $result = $subject->generate($this->createOpenAiProvider(), 'test-key', []);

        self::assertContains('content-assistant', array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result));
    }

    #[Test]
    public function generateSubstitutesInvalidUtf8InRequestBodyInsteadOfFailing(): void
    {
        // The model description carries a non-UTF-8 byte. With
        // JSON_INVALID_UTF8_SUBSTITUTE the request body encodes (U+FFFD
        // substitution) and the call proceeds to the parsed result. With
        // the flags AND-ed (= 0) json_encode returns false and the run
        // falls back to the static presets.
        $provider = $this->createOpenAiProvider();
        $models   = [
            new DiscoveredModel(
                modelId: 'utf8-model',
                name: 'UTF-8 Model',
                description: "Latin-1 byte: \xB1 end",
                capabilities: ['chat'],
                contextLength: 100000,
                recommended: true,
            ),
        ];

        $content = (string)json_encode([
            ['identifier' => 'utf8-config', 'name' => 'UTF-8 Config', 'systemPrompt' => 'Help.'],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($provider, 'test-key', $models);

        self::assertCount(1, $result);
        self::assertSame('utf8-config', $result[0]->identifier);
    }

    #[Test]
    public function generateLogsExactApiErrorMessageOnNon200Response(): void
    {
        // The thrown ProviderResponseException message surfaces verbatim in
        // the warning context ('LLM API error: ' . status; the sanitizer
        // only redacts credential query params). Pins concat order and both
        // operands.
        $this->stubHttpRoundTrip(503, '{}');

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())
            ->method('warning')
            ->with(
                'LLM setup-wizard configuration generation failed; using fallback presets',
                self::callback(static function (mixed $context): bool {
                    self::assertIsArray($context);
                    self::assertArrayHasKey('exception', $context);
                    self::assertSame('LLM API error: 503', $context['exception']);
                    return true;
                }),
            );

        $subject = $this->createSubjectWithLogger($loggerMock);

        $result = $subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertContains('content-assistant', array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result));
    }

    #[Test]
    public function generateDoesNotParseBodyOfNon200Response(): void
    {
        // A parseable body on an error status must NOT be used: the throw
        // aborts callLLM and the fallback presets are returned.
        $content = (string)json_encode([
            ['identifier' => 'should-not-appear', 'name' => 'Nope', 'systemPrompt' => 'Nope.'],
        ]);
        $this->stubHttpRoundTrip(500, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        $identifiers = array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result);
        self::assertContains('content-assistant', $identifiers);
        self::assertNotContains('should-not-appear', $identifiers);
    }

    #[Test]
    public function generateHandlesNonArrayApiBodyWithoutLoggingAWarning(): void
    {
        // Body decodes to a string, not an array: callLLM returns ''
        // cleanly (no exception, no warning). Removing that return would
        // TypeError in extractContentFromResponse and log a warning.
        $this->stubHttpRoundTrip(200, '"just a string"');

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::never())->method('warning');

        $subject = $this->createSubjectWithLogger($loggerMock);

        $result = $subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertContains('content-assistant', array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result));
    }

    #[Test]
    public function generateHandlesUnparseableContentWithoutLoggingAWarning(): void
    {
        // extractJson() yields null → parseResponse returns [] cleanly.
        // Removing that return would TypeError in array_is_list(null) and
        // log a warning.
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent('No JSON here at all'));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::never())->method('warning');

        $subject = $this->createSubjectWithLogger($loggerMock);

        $result = $subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertContains('content-assistant', array_map(static fn(SuggestedConfiguration $c): string => $c->identifier, $result));
    }

    #[Test]
    public function generatePrefersConfigurationsKeyOverConfigsKey(): void
    {
        $content = (string)json_encode([
            'configurations' => [
                ['identifier' => 'from-configurations', 'name' => 'A', 'systemPrompt' => 'A.'],
            ],
            'configs' => [
                ['identifier' => 'from-configs', 'name' => 'B', 'systemPrompt' => 'B.'],
            ],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('from-configurations', $result[0]->identifier);
    }

    #[Test]
    public function generateReturnsAllParsedConfigurationsInOrder(): void
    {
        $content = (string)json_encode([
            ['identifier' => 'first-config', 'name' => 'First', 'systemPrompt' => 'One.'],
            ['identifier' => 'second-config', 'name' => 'Second', 'systemPrompt' => 'Two.'],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(2, $result);
        self::assertSame('first-config', $result[0]->identifier);
        self::assertSame('second-config', $result[1]->identifier);
    }

    #[Test]
    public function generateReadsSnakeCaseFallbackKeys(): void
    {
        // Only snake_case keys present: the ?? chains must reach the
        // second operand ($item['system_prompt'], $item['max_tokens']).
        $content = (string)json_encode([
            [
                'identifier'    => 'snake-item',
                'name'          => 'Snake Item',
                'system_prompt' => 'Snake prompt.',
                'max_tokens'    => 1234,
            ],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('snake-item', $result[0]->identifier);
        self::assertSame('Snake prompt.', $result[0]->systemPrompt);
        self::assertSame(1234, $result[0]->maxTokens);
        self::assertSame(0.7, $result[0]->temperature);
    }

    #[Test]
    public function generatePrefersCamelCaseKeysOverSnakeCase(): void
    {
        $content = (string)json_encode([
            [
                'identifier'    => 'camel-item',
                'name'          => 'Camel Item',
                'systemPrompt'  => 'Camel wins.',
                'system_prompt' => 'Snake loses.',
                'maxTokens'     => 2222,
                'max_tokens'    => 3333,
            ],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('Camel wins.', $result[0]->systemPrompt);
        self::assertSame(2222, $result[0]->maxTokens);
    }

    #[Test]
    public function generateAppliesDefaultsForOmittedItemFields(): void
    {
        $content = (string)json_encode([
            ['identifier' => 'defaults-item', 'name' => 'Defaults'],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('', $result[0]->description);
        self::assertSame('', $result[0]->systemPrompt);
        self::assertSame(0.7, $result[0]->temperature);
        self::assertSame(4096, $result[0]->maxTokens);
    }

    #[Test]
    public function generateCoercesNumericStringTemperatureAndMaxTokens(): void
    {
        // Numeric strings must be cast — without (float)/(int) casts the
        // strict-typed DTO constructor would TypeError and force fallback.
        $content = (string)json_encode([
            [
                'identifier'  => 'stringy-item',
                'name'        => 'Stringy',
                'temperature' => '0.4',
                'maxTokens'   => '1234',
            ],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('stringy-item', $result[0]->identifier);
        self::assertSame(0.4, $result[0]->temperature);
        self::assertSame(1234, $result[0]->maxTokens);
    }

    #[Test]
    public function generateFallsBackToDefaultsForNonNumericValues(): void
    {
        $content = (string)json_encode([
            [
                'identifier'  => 'nonnumeric-item',
                'name'        => 'Non Numeric',
                'temperature' => 'hot',
                'maxTokens'   => 'lots',
            ],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame(0.7, $result[0]->temperature);
        self::assertSame(4096, $result[0]->maxTokens);
    }

    #[Test]
    public function generateExtractsJsonOnlyReachableViaMarkdownFence(): void
    {
        // A stray "]" after the fence poisons both the raw candidate and
        // the bracket-regex candidate (greedy match runs to the LAST "]"),
        // so ONLY the markdown-fence capture ($matches[1]) decodes.
        $jsonContent = (string)json_encode([
            ['identifier' => 'md-only-config', 'name' => 'MD Only', 'systemPrompt' => 'Fence.'],
        ]);
        $content = "```json\n" . $jsonContent . "\n```\nStray ] bracket.";
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('md-only-config', $result[0]->identifier);
    }

    #[Test]
    public function generateTrimsHyphensFromSanitizedIdentifier(): void
    {
        // '_trimmed_' → '-trimmed-' after the underscore replacement; the
        // final trim($identifier, '-') must strip the edge hyphens.
        $content = (string)json_encode([
            ['identifier' => '_trimmed_', 'name' => 'Trimmed', 'systemPrompt' => 'Trim.'],
        ]);
        $this->stubHttpRoundTrip(200, $this->openAiResponseWithContent($content));

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $this->createTestModels());

        self::assertCount(1, $result);
        self::assertSame('trimmed', $result[0]->identifier);
    }

    #[Test]
    public function fallbackUsesFirstRecommendedModelWhenSeveralAreRecommended(): void
    {
        // getFallbackConfigurations breaks on the FIRST recommended model
        // (list order), not the last.
        $models = [
            new DiscoveredModel(
                modelId: 'rec-a',
                name: 'Rec A',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 100000,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'rec-b',
                name: 'Rec B',
                description: 'Test',
                capabilities: ['chat'],
                contextLength: 200000,
                recommended: true,
            ),
        ];
        $this->stubHttpRoundTrip(500, '{}');

        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', $models);

        self::assertNotEmpty($result);
        self::assertSame('rec-a', $result[0]->recommendedModelId);
    }

    #[Test]
    public function fallbackConfigurationsHaveExpectedMaxTokens(): void
    {
        $result = $this->subject->generate($this->createOpenAiProvider(), 'test-key', []);

        $maxTokensByIdentifier = [];
        foreach ($result as $config) {
            $maxTokensByIdentifier[$config->identifier] = $config->maxTokens;
        }

        self::assertSame(
            [
                'content-assistant'  => 4096,
                'content-summarizer' => 2048,
                'translator'         => 8192,
                'seo-optimizer'      => 4096,
                'code-assistant'     => 8192,
            ],
            $maxTokensByIdentifier,
        );
    }
}
