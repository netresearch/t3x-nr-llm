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
    private ClientInterface $httpClientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createHttpClientMock();

        $this->subject = new ClaudeProvider(
            $this->httpClientMock,
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

    #[Test]
    public function getNameReturnsAnthropicClaude(): void
    {
        $this->assertEquals('Anthropic Claude', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsClaude(): void
    {
        $this->assertEquals('claude', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        $this->assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
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

        $this->httpClientMock
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals('Claude response content', $result->content);
        $this->assertEquals('claude-sonnet-4-20250514', $result->model);
        $this->assertEquals('stop', $result->finishReason);
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

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        $this->assertEquals('Hi there', $result->content);
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

        $this->httpClientMock
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

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 429));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function getAvailableModelsReturnsClaudeModels(): void
    {
        $models = $this->subject->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        // Models are returned as key => label pairs
        $this->assertArrayHasKey('claude-sonnet-4-20250514', $models);
    }

    #[Test]
    public function supportsVisionReturnsTrue(): void
    {
        $this->assertTrue($this->subject->supportsVision());
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        $this->assertTrue($this->subject->supportsStreaming());
    }

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        $this->assertTrue($this->subject->supportsTools());
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

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        $this->assertEquals('First part. Second part.', $result->content);
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

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        $this->assertEquals('stop', $result->finishReason);
    }
}
