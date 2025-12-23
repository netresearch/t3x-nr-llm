<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CompletionService
 */
class CompletionServiceTest extends TestCase
{
    private CompletionService $subject;
    private LlmServiceManager&MockObject $llmManagerMock;

    protected function setUp(): void
    {
        $this->llmManagerMock = $this->createMock(LlmServiceManager::class);
        $this->subject = new CompletionService($this->llmManagerMock);
    }

    /**
     * @test
     */
    public function completeGeneratesTextWithDefaultOptions(): void
    {
        $prompt = 'Test prompt';
        $expectedResponse = 'Test response';

        $mockResponse = $this->createMockResponse($expectedResponse);

        $this->llmManagerMock
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

        $result = $this->subject->complete($prompt);

        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals($expectedResponse, $result->content);
        $this->assertEquals('stop', $result->finishReason);
    }

    /**
     * @test
     */
    public function completeIncludesSystemPromptWhenProvided(): void
    {
        $prompt = 'User prompt';
        $systemPrompt = 'System instructions';

        $mockResponse = $this->createMockResponse('Response');

        $this->llmManagerMock
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

        $this->subject->complete($prompt, ['system_prompt' => $systemPrompt]);
    }

    /**
     * @test
     */
    public function completeJsonReturnsDecodedArray(): void
    {
        $jsonResponse = '{"key": "value", "number": 42}';
        $mockResponse = $this->createMockResponse($jsonResponse);

        $this->llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $this->subject->completeJson('Generate JSON');

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(42, $result['number']);
    }

    /**
     * @test
     */
    public function completeJsonThrowsOnInvalidJson(): void
    {
        $mockResponse = $this->createMockResponse('Not valid JSON');

        $this->llmManagerMock
            ->method('chat')
            ->willReturn($mockResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->subject->completeJson('Generate JSON');
    }

    /**
     * @test
     */
    public function completeMarkdownReturnsString(): void
    {
        $mockResponse = $this->createMockResponse('# Markdown');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $this->subject->completeMarkdown('Test');

        $this->assertEquals('# Markdown', $result);
    }

    /**
     * @test
     */
    public function completeFactualUsesLowTemperature(): void
    {
        $mockResponse = $this->createMockResponse('Factual response');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function (array $options) {
                    return $options['temperature'] === 0.2
                        && $options['top_p'] === 0.9;
                })
            )
            ->willReturn($mockResponse);

        $this->subject->completeFactual('Factual question');
    }

    /**
     * @test
     */
    public function completeCreativeUsesHighTemperature(): void
    {
        $mockResponse = $this->createMockResponse('Creative response');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function (array $options) {
                    return $options['temperature'] === 1.2
                        && $options['presence_penalty'] === 0.6;
                })
            )
            ->willReturn($mockResponse);

        $this->subject->completeCreative('Creative prompt');
    }

    /**
     * @test
     */
    public function validateOptionsThrowsOnInvalidTemperature(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Temperature must be between 0.0 and 2.0');

        $this->subject->complete('Test', ['temperature' => 3.0]);
    }

    /**
     * @test
     */
    public function validateOptionsThrowsOnInvalidMaxTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be a positive integer');

        $this->subject->complete('Test', ['max_tokens' => -1]);
    }

    /**
     * @test
     */
    public function validateOptionsThrowsOnInvalidResponseFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response_format must be');

        $this->subject->complete('Test', ['response_format' => 'invalid']);
    }

    /**
     * @test
     */
    public function completionResponseIndicatesTruncation(): void
    {
        $mockResponse = $this->createMockResponse('Truncated', 'length');

        $this->llmManagerMock
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $this->subject->complete('Test');

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
