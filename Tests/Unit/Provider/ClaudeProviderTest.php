<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;

#[CoversClass(ClaudeProvider::class)]
class ClaudeProviderTest extends AbstractUnitTestCase
{
    private ClaudeProvider $subject;
    private ClientInterface $httpClientStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new ClaudeProvider(
            $this->httpClientStub,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $this->subject->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'claude-sonnet-4-20250514',
            'baseUrl' => '',
            'timeout' => 30,
        ]);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: ClaudeProvider, httpClient: ClientInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new ClaudeProvider(
            $httpClientMock,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $subject->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'claude-sonnet-4-20250514',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsAnthropicClaude(): void
    {
        self::assertEquals('Anthropic Claude', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsClaude(): void
    {
        self::assertEquals('claude', $this->subject->getIdentifier());
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
            'id' => 'msg_' . $this->faker->uuid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Claude response content',
                ],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 20,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Claude response content', $result->content);
        self::assertEquals('claude-sonnet-4-20250514', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionWithSystemPromptSendsItSeparately(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hi there']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
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
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
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
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 429));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function getAvailableModelsReturnsClaudeModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertIsArray($models);
        self::assertNotEmpty($models);
        // Models are returned as key => label pairs
        self::assertArrayHasKey('claude-sonnet-4-20250514', $models);
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
    public function chatCompletionHandlesMultipleContentBlocks(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'First part. '],
                ['type' => 'text', 'text' => 'Second part.'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('First part. Second part.', $result->content);
    }

    #[Test]
    public function chatCompletionMapsEndTurnToStop(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Test']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('stop', $result->finishReason);
    }
}
