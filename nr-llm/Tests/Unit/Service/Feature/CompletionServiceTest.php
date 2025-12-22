<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CompletionService
 */
class CompletionServiceTest extends TestCase
{
    private CompletionService $subject;
    private LlmServiceManager|MockObject $llmManagerMock;

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

        $mockResponse = $this->createMockLlmResponse($expectedResponse);

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) use ($prompt) {
                $this->assertArrayHasKey('messages', $options);
                $this->assertArrayHasKey('temperature', $options);
                $this->assertEquals(0.7, $options['temperature']);
                $this->assertEquals($prompt, $options['messages'][0]['content']);
                return true;
            }))
            ->willReturn($mockResponse);

        $result = $this->subject->complete($prompt);

        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals($expectedResponse, $result->text);
        $this->assertEquals('stop', $result->finishReason);
    }

    /**
     * @test
     */
    public function completeIncludesSystemPromptWhenProvided(): void
    {
        $prompt = 'User prompt';
        $systemPrompt = 'System instructions';

        $mockResponse = $this->createMockLlmResponse('Response');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) use ($systemPrompt, $prompt) {
                $messages = $options['messages'];
                $this->assertCount(2, $messages);
                $this->assertEquals('system', $messages[0]['role']);
                $this->assertEquals($systemPrompt, $messages[0]['content']);
                $this->assertEquals('user', $messages[1]['role']);
                $this->assertEquals($prompt, $messages[1]['content']);
                return true;
            }))
            ->willReturn($mockResponse);

        $this->subject->complete($prompt, ['system_prompt' => $systemPrompt]);
    }

    /**
     * @test
     */
    public function completeAppliesCustomTemperature(): void
    {
        $mockResponse = $this->createMockLlmResponse('Response');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) {
                $this->assertEquals(1.5, $options['temperature']);
                return true;
            }))
            ->willReturn($mockResponse);

        $this->subject->complete('Test', ['temperature' => 1.5]);
    }

    /**
     * @test
     */
    public function completeJsonReturnsDecodedArray(): void
    {
        $jsonResponse = '{"key": "value", "number": 42}';
        $mockResponse = $this->createMockLlmResponse($jsonResponse);

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) {
                $this->assertArrayHasKey('response_format', $options);
                $this->assertEquals(['type' => 'json_object'], $options['response_format']);
                return true;
            }))
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
        $mockResponse = $this->createMockLlmResponse('Not valid JSON');

        $this->llmManagerMock
            ->method('complete')
            ->willReturn($mockResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $this->subject->completeJson('Generate JSON');
    }

    /**
     * @test
     */
    public function completeMarkdownAddsFormattingInstruction(): void
    {
        $mockResponse = $this->createMockLlmResponse('# Markdown');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) {
                $messages = $options['messages'];
                $systemMessage = $messages[0]['content'] ?? '';
                $this->assertStringContainsString('Markdown', $systemMessage);
                return true;
            }))
            ->willReturn($mockResponse);

        $result = $this->subject->completeMarkdown('Test');

        $this->assertEquals('# Markdown', $result);
    }

    /**
     * @test
     */
    public function completeFactualUsesLowTemperature(): void
    {
        $mockResponse = $this->createMockLlmResponse('Factual response');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) {
                $this->assertEquals(0.2, $options['temperature']);
                $this->assertEquals(0.9, $options['top_p']);
                return true;
            }))
            ->willReturn($mockResponse);

        $this->subject->completeFactual('Factual question');
    }

    /**
     * @test
     */
    public function completeCreativeUsesHighTemperature(): void
    {
        $mockResponse = $this->createMockLlmResponse('Creative response');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) {
                $this->assertEquals(1.2, $options['temperature']);
                $this->assertEquals(0.6, $options['presence_penalty']);
                return true;
            }))
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
        $mockResponse = $this->createMockLlmResponse('Truncated', 'length');

        $this->llmManagerMock
            ->method('complete')
            ->willReturn($mockResponse);

        $result = $this->subject->complete('Test');

        $this->assertTrue($result->wasTruncated());
        $this->assertFalse($result->isComplete());
    }

    /**
     * Create mock LLM response
     */
    private function createMockLlmResponse(
        string $content,
        string $finishReason = 'stop'
    ): object {
        return new class($content, $finishReason) {
            public function __construct(
                private string $content,
                private string $finishReason
            ) {}

            public function getContent(): string
            {
                return $this->content;
            }

            public function getFinishReason(): string
            {
                return $this->finishReason;
            }

            public function getUsage(): array
            {
                return [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 20,
                    'estimated_cost' => 0.001,
                ];
            }

            public function getModel(): ?string
            {
                return 'test-model';
            }

            public function getMetadata(): ?array
            {
                return ['test' => 'metadata'];
            }
        };
    }
}
