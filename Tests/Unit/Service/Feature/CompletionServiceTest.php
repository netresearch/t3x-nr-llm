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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(CompletionService::class)]
class CompletionServiceTest extends AbstractUnitTestCase
{
    private CompletionService $subject;
    private LlmServiceManagerInterface $llmManagerStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
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
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(function (array $messages) use ($prompt): bool {
                    /** @var array{role: string, content: string} $msg */
                    $msg = $messages[0];
                    return $msg['role'] === 'user' && $msg['content'] === $prompt;
                }),
                self::anything(),
            )
            ->willReturn($mockResponse);

        $result = $subject->complete($prompt);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals($expectedResponse, $result->content);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function completeIncludesSystemPromptWhenProvided(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $prompt = 'User prompt';
        $systemPrompt = 'System instructions';

        $mockResponse = $this->createMockResponse('Response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(function (array $messages) use ($prompt, $systemPrompt): bool {
                    if (count($messages) !== 2) {
                        return false;
                    }
                    /** @var array{role: string, content: string} $msg0 */
                    $msg0 = $messages[0];
                    /** @var array{role: string, content: string} $msg1 */
                    $msg1 = $messages[1];
                    return $msg0['role'] === 'system'
                        && $msg0['content'] === $systemPrompt
                        && $msg1['role'] === 'user'
                        && $msg1['content'] === $prompt;
                }),
                self::anything(),
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
            ->expects(self::once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->completeJson('Generate JSON');

        self::assertSame('value', $result['key']);
        self::assertSame(42, $result['number']);
    }

    #[Test]
    public function completeJsonThrowsOnInvalidJson(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Not valid JSON');

        $llmManagerMock
            ->expects(self::once())
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
            ->expects(self::once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->completeMarkdown('Test');

        self::assertEquals('# Markdown', $result);
    }

    #[Test]
    public function completeFactualUsesLowTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Factual response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $options) => $options->getTemperature() === 0.2
                        && $options->getTopP() === 0.9),
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
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $options) => $options->getTemperature() === 1.2
                        && $options->getPresencePenalty() === 0.6),
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
            ->expects(self::once())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->complete('Test');

        self::assertTrue($result->wasTruncated());
        self::assertFalse($result->isComplete());
    }

    #[Test]
    public function validateOptionsThrowsOnInvalidTopP(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('top_p must be between 0 and 1');

        $this->subject->complete('Test', new ChatOptions(topP: 1.5));
    }

    #[Test]
    public function completeJsonThrowsOnNonObjectResponse(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('"just a string"');

        $llmManagerMock
            ->expects(self::atLeastOnce())
            ->method('chat')
            ->willReturn($mockResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON response must be an object');

        $subject->completeJson('Test');
    }

    #[Test]
    public function completeMarkdownReturnsMarkdownContent(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $markdownContent = "# Header\n\n- List item 1\n- List item 2";
        $mockResponse = $this->createMockResponse($markdownContent);

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(function (ChatOptions $options): bool {
                    // Verify markdown format is requested
                    $systemPrompt = $options->getSystemPrompt() ?? '';
                    return str_contains($systemPrompt, 'Markdown');
                }),
            )
            ->willReturn($mockResponse);

        $result = $subject->completeMarkdown('Test');

        self::assertSame($markdownContent, $result);
    }

    #[Test]
    public function completeWithStopSequences(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::isInstanceOf(ChatOptions::class),
            )
            ->willReturn($mockResponse);

        $options = new ChatOptions(stopSequences: ['END', 'STOP']);
        $result = $subject->complete('Test prompt', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeWithJsonResponseFormat(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('{"key": "value"}');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($mockResponse);

        $options = new ChatOptions(responseFormat: 'json');
        $result = $subject->complete('Return JSON', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeWithTextResponseFormat(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Plain text');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($mockResponse);

        $options = new ChatOptions(responseFormat: 'text');
        $result = $subject->complete('Return text', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeFactualWithCustomTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Factual response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTemperature() === 0.5),
            )
            ->willReturn($mockResponse);

        // When temperature is already set, completeFactual should use it
        $options = new ChatOptions(temperature: 0.5);
        $subject->completeFactual('Test', $options);
    }

    #[Test]
    public function completeFactualWithCustomTopP(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Factual response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTopP() === 0.5),
            )
            ->willReturn($mockResponse);

        // When topP is already set, completeFactual should use it
        $options = new ChatOptions(topP: 0.5);
        $subject->completeFactual('Test', $options);
    }

    #[Test]
    public function completeCreativeWithCustomTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Creative response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTemperature() === 0.8),
            )
            ->willReturn($mockResponse);

        // When temperature is already set, completeCreative should use it
        $options = new ChatOptions(temperature: 0.8);
        $subject->completeCreative('Test', $options);
    }

    #[Test]
    public function completeCreativeWithCustomTopPAndPresencePenalty(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Creative response');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTopP() === 0.8 && $opts->getPresencePenalty() === 0.3),
            )
            ->willReturn($mockResponse);

        // When topP and presencePenalty are already set, completeCreative should use them
        $options = new ChatOptions(topP: 0.8, presencePenalty: 0.3);
        $subject->completeCreative('Test', $options);
    }

    #[Test]
    public function completeMarkdownWithExistingSystemPrompt(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('# Markdown');

        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(function (ChatOptions $options): bool {
                    $systemPrompt = $options->getSystemPrompt() ?? '';
                    return str_contains($systemPrompt, 'Custom instruction')
                        && str_contains($systemPrompt, 'Markdown');
                }),
            )
            ->willReturn($mockResponse);

        $options = new ChatOptions(systemPrompt: 'Custom instruction');
        $subject->completeMarkdown('Test', $options);
    }

    #[Test]
    public function validateOptionsAcceptsZeroTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Response');
        $llmManagerMock->expects(self::atLeastOnce())->method('chat')->willReturn($mockResponse);

        $result = $subject->complete('Test', new ChatOptions(temperature: 0.0));

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function validateOptionsAcceptsMaxTemperature(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Response');
        $llmManagerMock->expects(self::atLeastOnce())->method('chat')->willReturn($mockResponse);

        $result = $subject->complete('Test', new ChatOptions(temperature: 2.0));

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function validateOptionsAcceptsZeroTopP(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Response');
        $llmManagerMock->expects(self::atLeastOnce())->method('chat')->willReturn($mockResponse);

        $result = $subject->complete('Test', new ChatOptions(topP: 0.0));

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function validateOptionsAcceptsMaxTopP(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $mockResponse = $this->createMockResponse('Response');
        $llmManagerMock->expects(self::atLeastOnce())->method('chat')->willReturn($mockResponse);

        $result = $subject->complete('Test', new ChatOptions(topP: 1.0));

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeJsonThrowsOnInvalidJsonSyntax(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        // Return malformed JSON
        $mockResponse = $this->createMockResponse('{ invalid json syntax }');

        $llmManagerMock
            ->expects(self::atLeastOnce())
            ->method('chat')
            ->willReturn($mockResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $subject->completeJson('Test');
    }

    #[Test]
    public function completeJsonParsesValidJsonObject(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $jsonContent = '{"name": "test", "value": 123, "nested": {"key": "value"}}';
        $mockResponse = $this->createMockResponse($jsonContent);

        $llmManagerMock
            ->expects(self::atLeastOnce())
            ->method('chat')
            ->willReturn($mockResponse);

        $result = $subject->completeJson('Return JSON');

        self::assertEquals('test', $result['name']);
        self::assertEquals(123, $result['value']);
        self::assertIsArray($result['nested']);
    }

    #[Test]
    public function completeJsonThrowsOnJsonNull(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        // Return valid JSON but null
        $mockResponse = $this->createMockResponse('null');

        $llmManagerMock
            ->expects(self::atLeastOnce())
            ->method('chat')
            ->willReturn($mockResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON response must be an object');

        $subject->completeJson('Test');
    }

    /**
     * Create mock CompletionResponse.
     */
    private function createMockResponse(
        string $content,
        string $finishReason = 'stop',
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(
                promptTokens: 10,
                completionTokens: 20,
                totalTokens: 30,
            ),
            finishReason: $finishReason,
            provider: 'test',
        );
    }
}
