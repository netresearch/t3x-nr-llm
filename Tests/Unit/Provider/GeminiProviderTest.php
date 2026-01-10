<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;

#[CoversClass(GeminiProvider::class)]
class GeminiProviderTest extends AbstractUnitTestCase
{
    private GeminiProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    #[Override]
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
            [
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
            ],
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
        /** @var array{function: array{name: string, arguments: array<string, string>}} $toolCall */
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall['function']['name']);
        self::assertEquals(['location' => 'London'], $toolCall['function']['arguments']);
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
            ['type' => 'text', 'text' => 'Describe this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==']],
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get current weather',
                    'parameters' => ['type' => 'object'],
                ],
            ],
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => ['type' => 'object'],
                ],
            ],
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
        /** @var array{function: array{name: string}} $toolCall */
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall['function']['name']);
    }

    #[Test]
    public function analyzeImageWithUrlNotBase64(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $content = [
            ['type' => 'text', 'text' => 'Describe this'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']],
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
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
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
    public function analyzeImageWithEmptyText(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $content = [
            ['type' => 'text', 'text' => ''],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQ']],
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $readCount = 0;
        $streamStub->method('eof')->willReturnCallback(function () use (&$readCount) {
            return $readCount >= 1; // @phpstan-ignore greaterOrEqual.alwaysFalse
        });
        $streamStub->method('read')->willReturnCallback(function () use (&$readCount, $sseData) {
            $readCount++;
            return $sseData;
        });

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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
}
