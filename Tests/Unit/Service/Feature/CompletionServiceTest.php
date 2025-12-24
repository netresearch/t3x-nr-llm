<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(CompletionService::class)]
class CompletionServiceTest extends AbstractUnitTestCase
{
    private CompletionService $subject;
    private LlmServiceManagerInterface $llmManagerStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $this->subject = new CompletionService($this->llmManagerStub);
    }

    /**
     * Create a subject with a mock LLM manager for expectation testing.
     *
     * @return array{subject: CompletionService, llmManager: LlmServiceManagerInterface&MockObject}
     */
    private function createSubjectWithMockManager(): array
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        return [
            'subject' => new CompletionService($llmManagerMock),
            'llmManager' => $llmManagerMock,
        ];
    }

    #[Test]
    public function completeGeneratesTextWithDefaultOptions(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $prompt = 'Test prompt';
        $expectedResponse = 'Test response';

        $mockResponse = $this->createMockResponse($expectedResponse);

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) use ($prompt) {
                    return $messages[0]['role'] === 'user'
                        && $messages[0]['content'] === $prompt;
                }),
                $this->anything()
            )
            ->willReturn($mockResponse);

        $result = $subject->complete($prompt);

        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals($expectedResponse, $result->content);
        $this->assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function completeIncludesSystemPromptWhenProvided(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $prompt = 'User prompt';
        $systemPrompt = 'System instructions';

        $mockResponse = $this->createMockResponse('Response');

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) use ($systemPrompt, $prompt) {
                    return count($messages) === 2
                        && $messages[0]['role'] === 'system'
                        && $messages[0]['content'] === $systemPrompt
                        && $messages[1]['role'] === 'user'
                        && $messages[1]['content'] === $prompt;
                }),
                $this->anything()
            )
            ->willReturn($mockResponse);

        $subject->complete($prompt, new ChatOptions(systemPrompt: $systemPrompt));
    }

    #[Test]
    public function completeJsonReturnsDecodedArray(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $jsonResponse = '{"key": "value", "number": 42}';
        $mockResponse = $this->createMockResponse($jsonResponse);

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->completeJson('Generate JSON');

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(42, $result['number']);
    }

    #[Test]
    public function completeJsonThrowsOnInvalidJson(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Not valid JSON');

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $subject->completeJson('Generate JSON');
    }

    #[Test]
    public function completeMarkdownReturnsString(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('# Markdown');

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->completeMarkdown('Test');

        $this->assertEquals('# Markdown', $result);
    }

    #[Test]
    public function completeFactualUsesLowTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Factual response');

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function (ChatOptions $options) {
                    return $options->getTemperature() === 0.2
                        && $options->getTopP() === 0.9;
                })
            )
            ->willReturn($mockResponse);

        $subject->completeFactual('Factual question');
    }

    #[Test]
    public function completeCreativeUsesHighTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Creative response');

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function (ChatOptions $options) {
                    return $options->getTemperature() === 1.2
                        && $options->getPresencePenalty() === 0.6;
                })
            )
            ->willReturn($mockResponse);

        $subject->completeCreative('Creative prompt');
    }

    #[Test]
    public function validateOptionsThrowsOnInvalidTemperature(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be between 0 and 2');

        $this->subject->complete('Test', new ChatOptions(temperature: 3.0));
    }

    #[Test]
    public function validateOptionsThrowsOnInvalidMaxTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be a positive integer');

        $this->subject->complete('Test', new ChatOptions(maxTokens: -1));
    }

    #[Test]
    public function validateOptionsThrowsOnInvalidResponseFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response_format must be');

        $this->subject->complete('Test', new ChatOptions(responseFormat: 'invalid'));
    }

    #[Test]
    public function completionResponseIndicatesTruncation(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Truncated', 'length');

        $llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->complete('Test');

        $this->assertTrue($result->wasTruncated());
        $this->assertFalse($result->isComplete());
    }

    /**
     * Create mock CompletionResponse
     */
    private function createMockResponse(
        string $content,
        string $finishReason = 'stop'
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(
                promptTokens: 10,
                completionTokens: 20,
                totalTokens: 30
            ),
            finishReason: $finishReason,
            provider: 'test',
        );
    }
}
