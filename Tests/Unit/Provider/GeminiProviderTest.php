<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(GeminiProvider::class)]
class GeminiProviderTest extends AbstractUnitTestCase
{
    private GeminiProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-3-flash-preview',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $this->subject->setHttpClient($this->httpClientStub);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: GeminiProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-3-flash-preview',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsGoogleGemini(): void
    {
        self::assertEquals('Google Gemini', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsGemini(): void
    {
        self::assertEquals('gemini', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => $this->randomPrompt()],
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Gemini response content'],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Gemini response content', $result->content);
        self::assertEquals('gemini-3-flash-preview', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionNeverPutsApiKeyInTheUrl(): void
    {
        // Security regression guard: the Gemini API key must travel in the
        // x-goog-api-key header, never the URL query string (which leaks into
        // server/proxy logs, browser history and the Referer header).
        $capturedUri = null;
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')
            ->willReturnCallback(function (string $method, string $uri) use (&$capturedUri): RequestInterface {
                $capturedUri = $uri;

                return $this->createRequestMock($method, $uri);
            });

        $subject = new GeminiProvider(
            $requestFactory,
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-3-flash-preview',
            'baseUrl' => '',
            'timeout' => 30,
        ]);
        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ]));
        $subject->setHttpClient($httpClientMock);

        $subject->chatCompletion([['role' => 'user', 'content' => 'Hello']]);

        self::assertIsString($capturedUri);
        self::assertStringNotContainsString('key=', $capturedUri);
    }

    #[Test]
    public function chatCompletionWithSystemPromptSendsSystemInstruction(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Hi there']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 5,
                'candidatesTokenCount' => 2,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals('Hi there', $result->content);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn401(): void
    {
        $errorResponse = [
            'error' => [
                'code' => 401,
                'message' => 'API key not valid',
                'status' => 'UNAUTHENTICATED',
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 401));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn429(): void
    {
        $errorResponse = [
            'error' => [
                'code' => 429,
                'message' => 'Resource exhausted',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 429));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function getAvailableModelsReturnsGeminiModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        // Models are returned as key => label pairs
        self::assertArrayHasKey('gemini-3-flash-preview', $models);
        self::assertArrayHasKey('gemini-2.5-flash', $models);
    }

    #[Test]
    public function supportsVisionReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsVision());
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsStreaming());
    }

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsTools());
    }

    #[Test]
    public function chatCompletionMapsStopFinishReason(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Test']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 1,
                'candidatesTokenCount' => 1,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionMapsMaxTokensFinishReason(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Truncated response']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'MAX_TOKENS',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 1,
                'candidatesTokenCount' => 100,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('length', $result->finishReason);
    }

    #[Test]
    public function chatCompletionMapsSafetyFinishReason(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => '']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'SAFETY',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 1,
                'candidatesTokenCount' => 0,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('content_filter', $result->finishReason);
    }

    #[Test]
    public function chatCompletionWithToolsReturnsToolCalls(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => 'What is the weather in London?'],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get current weather',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => ['type' => 'string'],
                        ],
                    ],
                ],
            ]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['location' => 'London'],
                                ],
                            ],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 15,
                'candidatesTokenCount' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall->name);
        self::assertEquals(['location' => 'London'], $toolCall->arguments);
    }

    #[Test]
    public function chatCompletionWithToolsSkipsFunctionCallWithEmptyName(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [['role' => 'user', 'content' => 'What is the weather in London?']];
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ]),
        ];

        // A functionCall part with no usable name must be skipped rather than
        // build an invalid ToolCall (empty name) and abort the response.
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['name' => '', 'args' => ['location' => 'London']]],
                            ['functionCall' => ['name' => 'get_weather', 'args' => ['location' => 'London']]],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 15, 'candidatesTokenCount' => 10],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools($messages, $tools);

        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        self::assertSame('get_weather', $result->toolCalls[0]->name);
    }

    #[Test]
    public function embeddingsReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'embedding' => [
                'values' => [0.1, 0.2, 0.3, 0.4, 0.5],
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings('Test text');

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1, $result->embeddings);
        self::assertEquals([0.1, 0.2, 0.3, 0.4, 0.5], $result->embeddings[0]);
    }

    #[Test]
    public function analyzeImageReturnsVisionResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this image']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==']]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'This is an image of a sunset.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 15,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->analyzeImage($content);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('This is an image of a sunset.', $result->description);
    }

    #[Test]
    public function getSupportedImageFormatsReturnsExpectedFormats(): void
    {
        $formats = $this->subject->getSupportedImageFormats();

        self::assertContains('png', $formats);
        self::assertContains('jpeg', $formats);
        self::assertContains('gif', $formats);
        self::assertContains('webp', $formats);
        self::assertNotContains('pdf', $formats);
    }

    #[Test]
    public function supportsDocumentsReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsDocuments());
    }

    #[Test]
    public function getSupportedDocumentFormatsReturnsPdf(): void
    {
        $formats = $this->subject->getSupportedDocumentFormats();

        self::assertContains('pdf', $formats);
    }

    #[Test]
    public function getMaxImageSizeReturns20MB(): void
    {
        $maxSize = $this->subject->getMaxImageSize();

        self::assertEquals(20 * 1024 * 1024, $maxSize);
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('gemini-3-flash-preview', $this->subject->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsFallbackWhenNotConfigured(): void
    {
        $subject = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => '',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        self::assertEquals('gemini-3-flash-preview', $subject->getDefaultModel());
    }

    #[Test]
    public function chatCompletionWithTopPOption(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Response']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['top_p' => 0.9],
        );

        self::assertEquals('Response', $result->content);
    }

    #[Test]
    public function chatCompletionWithTopKOption(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Response']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['top_k' => 40],
        );

        self::assertEquals('Response', $result->content);
    }

    #[Test]
    public function chatCompletionWithStopSequencesOption(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Response stopped']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['stop_sequences' => ['END', 'STOP']],
        );

        self::assertEquals('Response stopped', $result->content);
    }

    #[Test]
    public function chatCompletionMapsRecitationFinishReason(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => '']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'RECITATION',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 1,
                'candidatesTokenCount' => 0,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('content_filter', $result->finishReason);
    }

    #[Test]
    public function chatCompletionMapsUnknownFinishReason(): void
    {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Response']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'UNKNOWN_REASON',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 1,
                'candidatesTokenCount' => 1,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('unknown_reason', $result->finishReason);
    }

    #[Test]
    public function chatCompletionWithToolsWithTextAndFunctionCall(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => 'Check weather and tell me'],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get current weather',
                    'parameters' => ['type' => 'object'],
                ],
            ]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'I will check the weather. '],
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['location' => 'London'],
                                ],
                            ],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 15,
                'candidatesTokenCount' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools($messages, $tools);

        self::assertEquals('I will check the weather. ', $result->content);
        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
    }

    #[Test]
    public function chatCompletionWithToolsWithSystemInstruction(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'system', 'content' => 'You are a weather assistant.'],
            ['role' => 'user', 'content' => 'Weather in Paris'],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => ['type' => 'object'],
                ],
            ]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['location' => 'Paris'],
                                ],
                            ],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools($messages, $tools);

        self::assertNotNull($result->toolCalls);
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall->name);
    }

    #[Test]
    public function analyzeImageWithUrlNotBase64(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'A beautiful landscape.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->analyzeImage($content);

        self::assertEquals('A beautiful landscape.', $result->description);
    }

    #[Test]
    public function analyzeImageWithSystemPrompt(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Technical analysis: This is a diagram.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 150,
                'candidatesTokenCount' => 20,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->analyzeImage($content, ['system_prompt' => 'You are a technical analyst.']);

        self::assertEquals('Technical analysis: This is a diagram.', $result->description);
    }

    #[Test]
    public function analyzeImageWithImageOnlyContent(): void
    {
        // Pre-VisionContent migration this test passed an empty-text item
        // alongside the image and asserted Gemini silently dropped it. The
        // typed VO now rejects empty-text payloads at construction (covered
        // by VisionContentTest::constructorRejectsEmptyTextPayload), so the
        // scenario degenerates into "image-only content also works" — that
        // path remains worth exercising on Gemini, where the previous code
        // had a defensive `if ($text !== '')` branch.
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $content = [
            VisionContent::imageUrl('data:image/jpeg;base64,/9j/4AAQ'),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'An image.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 50,
                'candidatesTokenCount' => 5,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->analyzeImage($content);

        self::assertEquals('An image.', $result->description);
    }

    #[Test]
    public function embeddingsWithArrayInput(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'embedding' => [
                'values' => [0.1, 0.2, 0.3],
            ],
        ];

        $httpClientMock
            ->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings(['First text', 'Second text']);

        self::assertCount(2, $result->embeddings);
        self::assertEquals([0.1, 0.2, 0.3], $result->embeddings[0]);
        self::assertEquals([0.1, 0.2, 0.3], $result->embeddings[1]);
    }

    #[Test]
    public function embeddingsWithCustomModel(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'embedding' => [
                'values' => [0.5, 0.6, 0.7],
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings('Test text', ['model' => 'custom-embedding-model']);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertEquals([0.5, 0.6, 0.7], $result->embeddings[0]);
    }

    #[Test]
    public function streamChatCompletionYieldsText(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $sseData = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hello\"}]}}]}\n\n";
        $sseData .= "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\" World\"}]}}]}\n\n";

        $streamStub = self::createStub(StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $chunks = [];
        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'Hi']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Hello', ' World'], $chunks);
    }

    #[Test]
    public function streamChatCompletionWithSystemInstruction(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $sseData = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Response\"}]}}]}\n\n";

        $streamStub = self::createStub(StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $messages = [
            ['role' => 'system', 'content' => 'Be helpful.'],
            ['role' => 'user', 'content' => 'Hi'],
        ];

        $chunks = [];
        foreach ($subject->streamChatCompletion($messages) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Response'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsMalformedJson(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $sseData = "data: {invalid json}\n";
        $sseData .= "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Valid\"}]}}]}\n\n";

        $streamStub = self::createStub(StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $chunks = [];
        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Valid'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsEmptyText(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $sseData = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"\"}]}}]}\n";
        $sseData .= "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Content\"}]}}]}\n\n";

        $streamStub = self::createStub(StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $chunks = [];
        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Content'], $chunks);
    }

    #[Test]
    public function chatCompletionConvertsAssistantRole(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
            ['role' => 'user', 'content' => 'How are you?'],
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'I am fine!']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 5,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals('I am fine!', $result->content);
    }

    // ===== Multimodal content tests =====

    #[Test]
    public function chatCompletionHandlesMultimodalContentArray(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this image'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
                ],
            ],
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'This is a diagram.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 10,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('This is a diagram.', $result->content);
    }

    #[Test]
    public function chatCompletionHandlesDocumentMultimodalContent(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Summarize this PDF'],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => 'JVBERi0xLjQ=',
                        ],
                    ],
                ],
            ],
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'PDF summary']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 200,
                'candidatesTokenCount' => 5,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('PDF summary', $result->content);
    }

    #[Test]
    public function chatCompletionPreservesStringContentBackwardCompatible(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Simple text message'],
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Simple response']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 5,
                'candidatesTokenCount' => 3,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Simple response', $result->content);
    }

    // ===== Tool message conversion tests =====

    #[Test]
    public function chatCompletionWithToolsConvertsToolResultMessages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'What is the weather?'],
            [
                'role' => 'assistant',
                'content' => 'Let me check.',
                'tool_calls' => [
                    [
                        'id' => 'call_123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"Berlin"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_123',
                'name' => 'get_weather',
                'content' => '{"temp": 20, "condition": "sunny"}',
            ],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather for a location',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['location' => ['type' => 'string']],
                    ],
                ],
            ]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'The weather in Berlin is 20C and sunny.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 50,
                'candidatesTokenCount' => 15,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('The weather in Berlin is 20C and sunny.', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsConvertsAssistantToolCalls(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Check weather'],
            [
                'role' => 'assistant',
                'content' => 'Checking now.',
                'tool_calls' => [
                    [
                        'id' => 'call_789',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"Munich"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_789',
                'name' => 'get_weather',
                'content' => '{"temp": 15}',
            ],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => ['type' => 'object'],
                ],
            ]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Munich is 15C.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 40,
                'candidatesTokenCount' => 10,
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Munich is 15C.', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsResolvesToolNameFromMapping(): void
    {
        // Tool result message WITHOUT 'name' field — name must be resolved from
        // the preceding assistant tool_calls via tool_call_id mapping
        $messages = [
            ['role' => 'user', 'content' => 'Check the weather'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_abc',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Hamburg"}'],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_abc',
                // NO 'name' field — standard OpenAI format
                'content' => '{"temp": 12}',
            ],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ],
            ]),
        ];

        $apiResponse = [
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => 'Hamburg is 12C.']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 8],
        ];

        // Use the standard stub from setUp — just verify the call succeeds
        // The tool_call_id→name mapping is tested by the fact that Gemini API
        // would reject an invalid functionResponse.name
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);
        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Hamburg is 12C.', $result->content);
    }

    /**
     * A model id interpolated raw into the request path must be rejected when
     * it could escape the endpoint (path traversal) or inject query parameters.
     *
     * @return iterable<string, array{string}>
     */
    public static function maliciousModelIdProvider(): iterable
    {
        yield 'path traversal' => ['../../v1/models/secret'];
        yield 'query injection' => ['gemini-3-flash-preview?evil=1'];
        yield 'query ampersand' => ['gemini-3-flash-preview&key=leak'];
        yield 'slash' => ['models/gemini-3-flash-preview'];
        yield 'whitespace' => ['gemini 3 flash'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('maliciousModelIdProvider')]
    public function chatCompletionRejectsInvalidModelId(string $model): void
    {
        // The guard fires before any HTTP call, so the default stub
        // (which would otherwise return a stubbed JSON body) is never reached.
        $this->expectException(ProviderConfigurationException::class);

        $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => $model],
        );
    }

    #[Test]
    public function embeddingsRejectsInvalidModelId(): void
    {
        $this->expectException(ProviderConfigurationException::class);

        $this->subject->embeddings('hi', ['model' => '../../secret']);
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModelList(): void
    {
        $apiResponse = [
            'models' => [
                ['name' => 'models/gemini-3-flash-preview'],
                ['name' => 'models/gemini-3-pro'],
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connection successful', $result['message']);
        self::assertStringContainsString('2 models', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        // The `models/` prefix is stripped from the reported id.
        self::assertArrayHasKey('gemini-3-flash-preview', $result['models']);
    }

    #[Test]
    public function testConnectionThrowsOnHttpError(): void
    {
        // A static-list provider must NOT silently report success on an
        // unreachable / unauthorized endpoint: the real HTTP call surfaces
        // the typed exception instead.
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(403, 'API key not valid'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->testConnection();
    }

    // ===== Outgoing-request assertions =====
    //
    // The tests below pin the exact request the provider constructs (method,
    // relative URI, decoded JSON body) rather than only the parsed response.
    // The subject is built with baseUrl '' (mirroring setUp), so the captured
    // URI is the RELATIVE path AbstractProvider::sendRequest() produces
    // (rtrim('', '/') . '/' . ltrim($endpoint, '/')), never the production
    // https://generativelanguage.googleapis.com/... base URL.

    /**
     * Build a subject whose request-factory / stream-factory capture the
     * outgoing method, URI and JSON body, and whose HTTP client returns the
     * given decoded response.
     *
     * @param array<string, mixed> $apiResponse
     */
    private function createCapturingSubject(
        array $apiResponse,
        ?string &$capturedMethod,
        ?string &$capturedUri,
        ?string &$capturedBody,
    ): GeminiProvider {
        $subject = new GeminiProvider(
            $this->createCapturingRequestFactory($capturedMethod, $capturedUri),
            $this->createCapturingStreamFactory($capturedBody),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-3-flash-preview',
            'baseUrl' => '',
            'timeout' => 30,
        ]);
        $subject->setHttpClient($this->createJsonHttpClient($apiResponse));

        return $subject;
    }

    /**
     * Request factory stub that records the method and URI of every
     * created request.
     */
    private function createCapturingRequestFactory(
        ?string &$capturedMethod,
        ?string &$capturedUri,
    ): RequestFactoryInterface {
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')
            ->willReturnCallback(function (string $method, string $uri) use (
                &$capturedMethod,
                &$capturedUri,
            ): RequestInterface {
                $capturedMethod = $method;
                $capturedUri    = $uri;

                return $this->createRequestMock($method, $uri);
            });

        return $requestFactory;
    }

    /**
     * Stream factory stub that records the JSON body of every created stream.
     */
    private function createCapturingStreamFactory(?string &$capturedBody): StreamFactoryInterface
    {
        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')
            ->willReturnCallback(function (string $content) use (&$capturedBody): StreamInterface {
                $capturedBody = $content;
                $stream       = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);

                return $stream;
            });

        return $streamFactory;
    }

    /**
     * Build an SSE streaming response stub: read() replays $sseData once,
     * eof() flips to true afterwards.
     */
    private function createSseResponse(string $sseData, int $statusCode = 200): ResponseInterface
    {
        $streamStub = self::createStub(StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });
        $streamStub->method('__toString')->willReturn($sseData);
        $streamStub->method('getContents')->willReturn($sseData);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($streamStub);

        return $responseStub;
    }

    /**
     * Build a subject for streaming tests whose request-factory /
     * stream-factory capture the outgoing method, URI and JSON body, and
     * whose HTTP client replays the given SSE response.
     */
    private function createStreamingCapturingSubject(
        ResponseInterface $sseResponse,
        ?string &$capturedMethod,
        ?string &$capturedUri,
        ?string &$capturedBody,
        string $baseUrl = '',
    ): GeminiProvider {
        $subject = new GeminiProvider(
            $this->createCapturingRequestFactory($capturedMethod, $capturedUri),
            $this->createCapturingStreamFactory($capturedBody),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-3-flash-preview',
            'baseUrl' => $baseUrl,
            'timeout' => 30,
        ]);

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($sseResponse);
        $subject->setHttpClient($httpClient);

        return $subject;
    }

    /**
     * Minimal one-candidate generateContent response for capture tests.
     *
     * @return array<string, mixed>
     */
    private static function okCandidateResponse(): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ];
    }

    /**
     * @param array<string, mixed> $apiResponse
     */
    private function createJsonHttpClient(array $apiResponse): ClientInterface
    {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->createJsonResponseMock($apiResponse));

        return $httpClient;
    }

    /**
     * Decode a captured JSON request body into an array for assertions.
     *
     * @return array<string, mixed>
     */
    private function decodeCapturedBody(?string $capturedBody): array
    {
        self::assertIsString($capturedBody);
        $decoded = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[Test]
    public function chatCompletionSendsExactRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->chatCompletion([['role' => 'user', 'content' => 'Hello']]);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/models/gemini-3-flash-preview:generateContent', $capturedUri);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
            $body['contents'],
        );
        self::assertIsArray($body['generationConfig']);
        self::assertArrayHasKey('temperature', $body['generationConfig']);
        self::assertSame(0.7, $body['generationConfig']['temperature']);
        self::assertArrayHasKey('maxOutputTokens', $body['generationConfig']);
        self::assertSame(4096, $body['generationConfig']['maxOutputTokens']);
    }

    #[Test]
    public function chatCompletionConvertsChatMessageObjectsToArrays(): void
    {
        // A ChatMessage instance (not a plain array) must be normalised via
        // toArray() before conversion; without that map the value object reaches
        // asArray() as an object → [] → an empty user turn.
        $capturedBody = null;
        $capturedUri  = null;
        $capturedMethod = null;

        $subject = $this->createCapturingSubject(
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->chatCompletion([new ChatMessage('user', 'Hello from VO')]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hello from VO']]]],
            $body['contents'],
        );
    }

    #[Test]
    public function chatCompletionWithToolsSendsExactToolPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get current weather',
                    'parameters' => ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
                ],
            ]),
        ];

        $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'Weather?']], $tools);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/models/gemini-3-flash-preview:generateContent', $capturedUri);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Weather?']]]],
            $body['contents'],
        );
        self::assertSame(
            [[
                'functionDeclarations' => [[
                    'name' => 'get_weather',
                    'description' => 'Get current weather',
                    'parameters' => ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]],
                ]],
            ]],
            $body['tools'],
        );
        self::assertIsArray($body['generationConfig']);
        self::assertArrayHasKey('temperature', $body['generationConfig']);
        self::assertSame(0.7, $body['generationConfig']['temperature']);
        self::assertArrayHasKey('maxOutputTokens', $body['generationConfig']);
        self::assertSame(4096, $body['generationConfig']['maxOutputTokens']);
    }

    #[Test]
    public function chatCompletionWithToolsConvertsChatMessageObjectsToArrays(): void
    {
        $capturedBody   = null;
        $capturedUri    = null;
        $capturedMethod = null;

        $subject = $this->createCapturingSubject(
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'noop', 'description' => 'x', 'parameters' => ['type' => 'object']],
            ]),
        ];

        $subject->chatCompletionWithTools([new ChatMessage('user', 'VO message')], $tools);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'VO message']]]],
            $body['contents'],
        );
    }

    #[Test]
    public function chatCompletionWithToolsRejectsInvalidModelId(): void
    {
        $this->expectException(ProviderConfigurationException::class);

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'noop', 'description' => 'x', 'parameters' => ['type' => 'object']],
            ]),
        ];

        $this->subject->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'hi']],
            $tools,
            ['model' => '../../secret'],
        );
    }

    #[Test]
    public function chatCompletionWithToolsConcatenatesMultipleTextParts(): void
    {
        // Two text parts in one candidate exercise the `$rawContent .= $text`
        // accumulation; a plain assignment would keep only the last part.
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'description' => 'x', 'parameters' => ['type' => 'object']],
            ]),
        ];

        $apiResponse = [
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Part one. '],
                        ['text' => 'Part two.'],
                    ],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 5],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'hi']], $tools);

        self::assertSame('Part one. Part two.', $result->content);
    }

    #[Test]
    public function embeddingsSendsExactRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            ['embedding' => ['values' => [0.1, 0.2, 0.3]]],
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->embeddings('Test text');

        self::assertSame('POST', $capturedMethod);
        // embeddings() defaults to the EMBEDDING_MODEL constant, not the
        // configured chat defaultModel.
        self::assertSame('/models/gemini-embedding-2:embedContent', $capturedUri);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame('models/gemini-embedding-2', $body['model']);
        self::assertSame(
            ['parts' => [['text' => 'Test text']]],
            $body['content'],
        );
    }

    #[Test]
    public function embeddingsReportsPromptTokensFromTextLength(): void
    {
        // 'Test text' is 9 characters → 9/4 = 2.25 → (int) 2 prompt tokens;
        // completion tokens are always 0 for the embeddings endpoint.
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['embedding' => ['values' => [0.1, 0.2]]]));

        $result = $subject->embeddings('Test text');

        self::assertSame(2, $result->usage->promptTokens);
        self::assertSame(0, $result->usage->completionTokens);
    }

    #[Test]
    public function embeddingsAccumulatesPromptTokensAcrossInputs(): void
    {
        // 'First text' (10) + 'Second text' (11): 10/4 + 11/4 = 2.5 + 2.75
        // = 5.25 → (int) 5. A non-accumulating assignment would report 2.
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $httpClientMock
            ->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['embedding' => ['values' => [0.1]]]));

        $result = $subject->embeddings(['First text', 'Second text']);

        self::assertSame(5, $result->usage->promptTokens);
    }

    #[Test]
    public function embeddingsCastsStringValuesToFloat(): void
    {
        // Gemini can return numeric-string components; they must be cast to
        // float so the vector is a list<float>, not a list<string>.
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['embedding' => ['values' => ['0.1', '0.2', '0.3']]]));

        $result = $subject->embeddings('Test text');

        self::assertSame([0.1, 0.2, 0.3], $result->embeddings[0]);
    }

    #[Test]
    public function analyzeImageSendsExactRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            [
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'desc']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this image']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQ']]),
        ];

        $subject->analyzeImage($content);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/models/gemini-3-flash-preview:generateContent', $capturedUri);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Describe this image'],
                    ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => '/9j/4AAQ']],
                ],
            ]],
            $body['contents'],
        );
        self::assertIsArray($body['generationConfig']);
        self::assertArrayHasKey('maxOutputTokens', $body['generationConfig']);
        self::assertSame(4096, $body['generationConfig']['maxOutputTokens']);
    }

    #[Test]
    public function analyzeImageRejectsInvalidModelId(): void
    {
        $this->expectException(ProviderConfigurationException::class);

        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe']),
        ];

        $this->subject->analyzeImage($content, ['model' => '../../secret']);
    }

    // ===== Second-pass outgoing-request assertions (mutation coverage) =====

    #[Test]
    public function analyzeImageSkipsNonImageDataUri(): void
    {
        // analyzeImage() is image-only: a `data:application/pdf` URI matches
        // the base64 regex but fails the `image/` MIME check and must be
        // dropped from the outgoing parts.
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->analyzeImage([
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:application/pdf;base64,JVBERi0=']]),
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Describe']]]],
            $body['contents'],
        );
    }

    #[Test]
    public function analyzeImageSkipsImageDataUriWithEmbeddedNewline(): void
    {
        // Wrapped base64 (MIME line folding) carries a raw newline; the
        // `$`-anchored regex must reject it instead of silently truncating
        // the payload to its first line.
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->analyzeImage([
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => "data:image/png;base64,iVBORw0K\nGgo="]]),
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Describe']]]],
            $body['contents'],
        );
    }

    #[Test]
    public function analyzeImageSendsFileDataForRemoteImageUrl(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->analyzeImage([
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']]),
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Describe this'],
                    ['fileData' => ['mimeType' => 'image/jpeg', 'fileUri' => 'https://example.com/image.jpg']],
                ],
            ]],
            $body['contents'],
        );
    }

    #[Test]
    public function analyzeImageSendsSystemInstructionFromOption(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->analyzeImage(
            [VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?'])],
            ['system_prompt' => 'You are a technical analyst.'],
        );

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertArrayHasKey('systemInstruction', $body);
        self::assertSame(
            ['parts' => [['text' => 'You are a technical analyst.']]],
            $body['systemInstruction'],
        );
    }

    #[Test]
    public function chatCompletionSendsSystemInstructionAndRemainingMessages(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->chatCompletion([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        // The system message maps to systemInstruction; the user turn must
        // still be converted (`continue`, not `break`).
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
            $body['contents'],
        );
        self::assertArrayHasKey('systemInstruction', $body);
        self::assertSame(
            ['parts' => [['text' => 'You are helpful.']]],
            $body['systemInstruction'],
        );
    }

    #[Test]
    public function chatCompletionWithToolsSendsExactConversationPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'description' => 'x', 'parameters' => ['type' => 'object']],
            ]),
        ];

        $messages = [
            ['role' => 'user', 'content' => 'Check weather'],
            [
                'role' => 'assistant',
                'content' => 'Checking now.',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Hamburg"}'],
                    ],
                    [
                        'id' => 'call_2',
                        'type' => 'function',
                        'function' => ['name' => 'get_time', 'arguments' => '{"zone":"Europe/Berlin"}'],
                    ],
                ],
            ],
            // NO 'name' keys: both must resolve via the tool_call_id mapping.
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"temp":12}'],
            // Non-JSON tool output must be wrapped as {result: ...}.
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => 'half past nine'],
        ];

        $subject->chatCompletionWithTools($messages, $tools);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [
                ['role' => 'user', 'parts' => [['text' => 'Check weather']]],
                ['role' => 'model', 'parts' => [
                    ['text' => 'Checking now.'],
                    ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Hamburg']]],
                    ['functionCall' => ['name' => 'get_time', 'args' => ['zone' => 'Europe/Berlin']]],
                ]],
                ['role' => 'user', 'parts' => [[
                    'functionResponse' => ['name' => 'get_weather', 'response' => ['temp' => 12]],
                ]]],
                ['role' => 'user', 'parts' => [[
                    'functionResponse' => ['name' => 'get_time', 'response' => ['result' => 'half past nine']],
                ]]],
            ],
            $body['contents'],
        );
    }

    #[Test]
    public function chatCompletionSendsExactMultimodalImagePayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->chatCompletion([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this image'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
                ],
            ],
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Describe this image'],
                    ['inlineData' => ['mimeType' => 'image/png', 'data' => 'iVBORw0KGgo=']],
                ],
            ]],
            $body['contents'],
        );
    }

    #[Test]
    public function chatCompletionSendsExactDocumentPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->chatCompletion([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Summarize this PDF'],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => 'JVBERi0xLjQ=',
                        ],
                    ],
                ],
            ],
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Summarize this PDF'],
                    ['inlineData' => ['mimeType' => 'application/pdf', 'data' => 'JVBERi0xLjQ=']],
                ],
            ]],
            $body['contents'],
        );
    }

    #[Test]
    public function chatCompletionSkipsMultimodalDataUriWithEmbeddedNewline(): void
    {
        // Same anchored-regex guard as on the vision path: wrapped base64
        // with a raw newline must not be truncated to its first line.
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createCapturingSubject(
            self::okCandidateResponse(),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $subject->chatCompletion([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe'],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:image/png;base64,iVBORw0K\nGgo="]],
                ],
            ],
        ]);

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Describe']]]],
            $body['contents'],
        );
    }

    // ===== Second-pass streaming assertions (mutation coverage) =====

    #[Test]
    public function streamChatCompletionSendsExactRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createStreamingCapturingSubject(
            $this->createSseResponse("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hi!\"}]}}]}\n"),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
            'https://gemini.example/v1beta/',
        );

        $chunks = [];
        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'Hi']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['Hi!'], $chunks);
        self::assertSame('POST', $capturedMethod);
        // rtrim() must deduplicate the trailing slash of the configured base URL.
        self::assertSame(
            'https://gemini.example/v1beta/models/gemini-3-flash-preview:streamGenerateContent?alt=sse',
            $capturedUri,
        );

        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            $body['contents'],
        );
        self::assertIsArray($body['generationConfig']);
        self::assertArrayHasKey('temperature', $body['generationConfig']);
        self::assertSame(0.7, $body['generationConfig']['temperature']);
        self::assertArrayHasKey('maxOutputTokens', $body['generationConfig']);
        self::assertSame(4096, $body['generationConfig']['maxOutputTokens']);
    }

    #[Test]
    public function streamChatCompletionConvertsChatMessageObjectsToArrays(): void
    {
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createStreamingCapturingSubject(
            $this->createSseResponse("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"ok\"}]}}]}\n"),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $chunks = [];
        foreach ($subject->streamChatCompletion([new ChatMessage('user', 'Hello from VO')]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['ok'], $chunks);
        $body = $this->decodeCapturedBody($capturedBody);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hello from VO']]]],
            $body['contents'],
        );
    }

    #[Test]
    public function streamChatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        // A message carrying a non-UTF-8 byte (e.g. ISO-8859-1 log output
        // echoed into the conversation) must degrade to U+FFFD via
        // JSON_INVALID_UTF8_SUBSTITUTE; without the flag json_encode()
        // returns false and the request cannot be built.
        $capturedMethod = null;
        $capturedUri    = null;
        $capturedBody   = null;

        $subject = $this->createStreamingCapturingSubject(
            $this->createSseResponse("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"ok\"}]}}]}\n"),
            $capturedMethod,
            $capturedUri,
            $capturedBody,
        );

        $chunks = [];
        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => "Caf\xE9"]]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['ok'], $chunks);
        self::assertIsString($capturedBody);
        // json_encode() (no JSON_UNESCAPED_UNICODE) escapes the substituted
        // U+FFFD replacement character as the literal `\ufffd` sequence.
        self::assertStringContainsString('Caf\ufffd', $capturedBody);
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeRequest(): void
    {
        $subject = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'gemini-3-flash-preview',
            'baseUrl' => '',
            'timeout' => 30,
        ]);
        // A pre-wired SSE client that would complete without error: the typed
        // configuration failure must fire BEFORE any request is attempted.
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(
            $this->createSseResponse("data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"never\"}]}}]}\n"),
        );
        $subject->setHttpClient($httpClient);

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionCode(1307337100);

        $subject->streamChatCompletion([['role' => 'user', 'content' => 'Hi']])->current();
    }

    #[Test]
    public function streamChatCompletionRejectsInvalidModelIdWithTypedException(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionCode(1751280000);

        $this->subject->streamChatCompletion(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => '../../secret'],
        )->current();
    }

    #[Test]
    public function streamChatCompletionThrowsProviderResponseExceptionOnHttpError(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createSseResponse('{"error":{"message":"API key not valid"}}', 401));

        $this->expectException(ProviderResponseException::class);

        $this->subject->streamChatCompletion([['role' => 'user', 'content' => 'Hi']])->current();
    }

    #[Test]
    public function streamChatCompletionAccumulatesBufferAcrossChunkReads(): void
    {
        // One SSE line split across two read() chunks: the buffer must
        // ACCUMULATE (`.=`) — a plain assignment would drop the first half
        // and lose the line.
        $chunkOne = 'data: {"candidates":[{"content":{"parts":[{"text":"Hel';
        $chunkTwo = "lo\"}]}}]}\n";

        $streamStub = self::createStub(StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 2; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $chunkOne, $chunkTwo) {
            $readCount++;
            return $readCount === 1 ? $chunkOne : $chunkTwo;
        });

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $chunks = [];
        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'Hi']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['Hello'], $chunks);
    }
}
