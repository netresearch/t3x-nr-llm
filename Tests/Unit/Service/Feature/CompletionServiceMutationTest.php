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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Additional mutation-killing tests for CompletionService.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(CompletionService::class)]
class CompletionServiceMutationTest extends AbstractUnitTestCase
{
    private function createMockResponse(
        string $content,
        string $finishReason = 'stop',
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: $finishReason,
            provider: 'test',
        );
    }

    #[Test]
    public function completeCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $result = $service->complete('Test prompt', null);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeFactualSetsDefaultTemperatureWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTemperature() === 0.2),
            )
            ->willReturn($this->createMockResponse('Factual response'));

        $service = new CompletionService($llmManagerMock);
        $service->completeFactual('Factual question');
    }

    #[Test]
    public function completeFactualSetsDefaultTopPWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTopP() === 0.9),
            )
            ->willReturn($this->createMockResponse('Factual response'));

        $service = new CompletionService($llmManagerMock);
        $service->completeFactual('Factual question');
    }

    #[Test]
    public function completeFactualPreservesUserProvidedTemperature(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTemperature() === 0.5),
            )
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(temperature: 0.5);
        $service->completeFactual('Question', $options);
    }

    #[Test]
    public function completeCreativeSetsDefaultTemperatureWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTemperature() === 1.2),
            )
            ->willReturn($this->createMockResponse('Creative response'));

        $service = new CompletionService($llmManagerMock);
        $service->completeCreative('Creative prompt');
    }

    #[Test]
    public function completeCreativeSetsDefaultTopPWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getTopP() === 1.0),
            )
            ->willReturn($this->createMockResponse('Creative response'));

        $service = new CompletionService($llmManagerMock);
        $service->completeCreative('Creative prompt');
    }

    #[Test]
    public function completeCreativeSetsDefaultPresencePenaltyWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getPresencePenalty() === 0.6),
            )
            ->willReturn($this->createMockResponse('Creative response'));

        $service = new CompletionService($llmManagerMock);
        $service->completeCreative('Creative prompt');
    }

    #[Test]
    public function completeCreativePreservesUserProvidedPresencePenalty(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getPresencePenalty() === 0.3),
            )
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(presencePenalty: 0.3);
        $service->completeCreative('Prompt', $options);
    }

    #[Test]
    public function completeJsonCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('{"key": "value"}'));

        $service = new CompletionService($llmManagerMock);
        $result = $service->completeJson('Generate JSON', null);

        self::assertEquals(['key' => 'value'], $result);
    }

    #[Test]
    public function completeMarkdownCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('# Heading'));

        $service = new CompletionService($llmManagerMock);
        $result = $service->completeMarkdown('Generate markdown', null);

        self::assertEquals('# Heading', $result);
    }

    #[Test]
    public function completeMarkdownAppendsMarkdownInstructions(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(function (array $messages) {
                    $systemMessage = $messages[0] ?? null;
                    return $systemMessage !== null
                        && $systemMessage['role'] === 'system'
                        && str_contains((string)$systemMessage['content'], 'Markdown');
                }),
                self::anything(),
            )
            ->willReturn($this->createMockResponse('# Result'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(systemPrompt: 'You are helpful');
        $service->completeMarkdown('Generate content', $options);
    }

    #[Test]
    #[DataProvider('validTemperatureProvider')]
    public function validateOptionsAcceptsValidTemperature(float $temperature): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $llmManagerStub
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerStub);
        $options = new ChatOptions(temperature: $temperature);

        $result = $service->complete('Test', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    public static function validTemperatureProvider(): array
    {
        return [
            'zero' => [0.0],
            'half' => [0.5],
            'one' => [1.0],
            'max' => [2.0],
        ];
    }

    #[Test]
    #[DataProvider('invalidTemperatureProvider')]
    public function validateOptionsRejectsInvalidTemperature(float $temperature): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new CompletionService($llmManagerStub);

        $this->expectException(InvalidArgumentException::class);

        $options = new ChatOptions(temperature: $temperature);
        $service->complete('Test', $options);
    }

    public static function invalidTemperatureProvider(): array
    {
        return [
            'negative' => [-0.1],
            'too high' => [2.1],
            'very high' => [3.0],
        ];
    }

    #[Test]
    #[DataProvider('validTopPProvider')]
    public function validateOptionsAcceptsValidTopP(float $topP): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $llmManagerStub
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerStub);
        $options = new ChatOptions(topP: $topP);

        $result = $service->complete('Test', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    public static function validTopPProvider(): array
    {
        return [
            'zero' => [0.0],
            'half' => [0.5],
            'one' => [1.0],
        ];
    }

    #[Test]
    #[DataProvider('invalidTopPProvider')]
    public function validateOptionsRejectsInvalidTopP(float $topP): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new CompletionService($llmManagerStub);

        $this->expectException(InvalidArgumentException::class);

        $options = new ChatOptions(topP: $topP);
        $service->complete('Test', $options);
    }

    public static function invalidTopPProvider(): array
    {
        return [
            'negative' => [-0.1],
            'too high' => [1.1],
        ];
    }

    #[Test]
    public function validateOptionsAcceptsValidMaxTokens(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $llmManagerStub
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerStub);
        $options = new ChatOptions(maxTokens: 100);

        $result = $service->complete('Test', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeWithStopSequencesMapsToStop(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(stopSequences: ['END', 'STOP']);

        $service->complete('Test', $options);
    }

    #[Test]
    public function completeWithJsonResponseFormatNormalizesIt(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('{"result": true}'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(responseFormat: 'json');

        $result = $service->complete('Test', $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeWithTextResponseFormat(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('Plain text response'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(responseFormat: 'text');

        $result = $service->complete('Test', $options);

        self::assertEquals('Plain text response', $result->content);
    }

    #[Test]
    public function completeWithMarkdownResponseFormat(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->createMockResponse('# Markdown'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(responseFormat: 'markdown');

        $result = $service->complete('Test', $options);

        self::assertEquals('# Markdown', $result->content);
    }

    #[Test]
    public function completeJsonThrowsOnInvalidJson(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('chat')
            ->willReturn($this->createMockResponse('not valid json'));

        $service = new CompletionService($llmManagerMock);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $service->completeJson('Generate JSON');
    }

    #[Test]
    public function completeMarkdownWithNoExistingSystemPrompt(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(
                    // Should have system message when markdown format

                    fn(array $messages) => isset($messages[0]['role'])
                    && $messages[0]['role'] === 'system'
                    && str_contains((string)$messages[0]['content'], 'Markdown'),
                ),
                self::anything(),
            )
            ->willReturn($this->createMockResponse('# Response'));

        $service = new CompletionService($llmManagerMock);
        // No system prompt in options
        $service->completeMarkdown('Generate markdown');
    }

    #[Test]
    public function completeWithSystemPromptAddsSystemMessage(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(fn(array $messages) => count($messages) === 2
                        && $messages[0]['role'] === 'system'
                        && $messages[0]['content'] === 'Be helpful'
                        && $messages[1]['role'] === 'user'),
                self::anything(),
            )
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $options = new ChatOptions(systemPrompt: 'Be helpful');

        $service->complete('User prompt', $options);
    }

    #[Test]
    public function completeWithoutSystemPromptOnlyHasUserMessage(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(fn(array $messages) => count($messages) === 1
                        && $messages[0]['role'] === 'user'
                        && $messages[0]['content'] === 'User prompt'),
                self::anything(),
            )
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);

        $service->complete('User prompt');
    }

    #[Test]
    public function validateOptionsRejectsZeroMaxTokens(): void
    {
        $llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $service = new CompletionService($llmManagerStub);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens');

        $options = new ChatOptions(maxTokens: 0);
        $service->complete('Test', $options);
    }

    #[Test]
    public function completeFactualWithNullOptionsCreatesDefaults(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $result = $service->completeFactual('Question', null);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeCreativeWithNullOptionsCreatesDefaults(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('chat')
            ->willReturn($this->createMockResponse('Response'));

        $service = new CompletionService($llmManagerMock);
        $result = $service->completeCreative('Prompt', null);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeJsonSetsJsonResponseFormat(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(fn(ChatOptions $opts) => $opts->getResponseFormat() === 'json'),
            )
            ->willReturn($this->createMockResponse('{"key": "value"}'));

        $service = new CompletionService($llmManagerMock);
        $service->completeJson('Generate JSON');
    }
}
