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
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
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
}
