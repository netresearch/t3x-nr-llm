<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;

#[CoversClass(OpenAiProvider::class)]
class OpenAiProviderTest extends AbstractUnitTestCase
{
    private OpenAiProvider $subject;
    private ClientInterface $httpClientStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $this->subject->setHttpClient($this->httpClientStub);

        $this->subject->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'gpt-4o',
            'baseUrl' => '',
            'timeout' => 30,
        ]);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: OpenAiProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $subject->setHttpClient($httpClientMock);

        $subject->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'gpt-4o',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsOpenAI(): void
    {
        self::assertEquals('OpenAI', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenai(): void
    {
        self::assertEquals('openai', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenNoApiKey(): void
    {
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->httpClientStub);

        // Without calling configure(), provider has no API key
        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => $this->randomPrompt()],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-' . $this->faker->uuid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Test response content',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Test response content', $result->content);
        self::assertEquals('gpt-4o', $result->model);
        self::assertEquals('stop', $result->finishReason);
        self::assertEquals(30, $result->usage->totalTokens);
    }

    #[Test]
    public function chatCompletionWithSystemPromptIncludesIt(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals('Hi', $result->content);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn401(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'Invalid API key'));

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn429(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(429, 'Rate limit exceeded'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsProviderExceptionOnServerError(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(500, 'Internal server error'));

        $this->expectException(ProviderException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function embeddingsReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $text = $this->randomPrompt();

        $apiResponse = [
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => array_fill(0, 1536, 0.1),
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 10,
                'total_tokens' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings($text);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1536, $result->embeddings[0]);
        self::assertEquals('text-embedding-3-small', $result->model);
    }

    #[Test]
    public function embeddingsWithMultipleTextsReturnsMultipleVectors(): void
    {
        $texts = [$this->randomPrompt(), $this->randomPrompt()];

        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['embedding' => array_fill(0, 1536, 0.1), 'index' => 0],
                ['embedding' => array_fill(0, 1536, 0.2), 'index' => 1],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 20, 'total_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings($texts);

        self::assertCount(2, $result->embeddings);
    }

    #[Test]
    public function getAvailableModelsReturnsArray(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertIsArray($models);
        self::assertNotEmpty($models);
        // Models are returned as key => label pairs
        self::assertArrayHasKey('gpt-5.2', $models);
        self::assertArrayHasKey('gpt-5.2-pro', $models);
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
    #[DataProvider('temperatureValidationProvider')]
    public function chatCompletionAcceptsValidTemperature(float $temperature): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['temperature' => $temperature],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    public static function temperatureValidationProvider(): array
    {
        return [
            'zero' => [0.0],
            'mid' => [1.0],
            'max' => [2.0],
        ];
    }

    #[Test]
    public function chatCompletionHandlesEmptyChoices(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => 1],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        // Provider returns empty content when no choices available
        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('', $result->content);
    }

    #[Test]
    public function chatCompletionWithCustomModelUsesIt(): void
    {
        $customModel = 'gpt-4-turbo';

        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'model' => $customModel,
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['model' => $customModel],
        );

        self::assertEquals($customModel, $result->model);
    }
}
