<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CompletionResponse::class)]
class CompletionResponseTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $usage = new UsageStatistics(100, 50, 150);
        $toolCalls = [['id' => 'call_1', 'type' => 'function']];
        $metadata = ['id' => 'chatcmpl-123'];

        $response = new CompletionResponse(
            content: 'Hello, world!',
            model: 'gpt-4o',
            usage: $usage,
            finishReason: 'stop',
            provider: 'openai',
            toolCalls: $toolCalls,
            metadata: $metadata,
        );

        $this->assertEquals('Hello, world!', $response->content);
        $this->assertEquals('gpt-4o', $response->model);
        $this->assertSame($usage, $response->usage);
        $this->assertEquals('stop', $response->finishReason);
        $this->assertEquals('openai', $response->provider);
        $this->assertEquals($toolCalls, $response->toolCalls);
        $this->assertEquals($metadata, $response->metadata);
    }

    #[Test]
    public function constructorUsesDefaults(): void
    {
        $usage = new UsageStatistics(10, 5, 15);

        $response = new CompletionResponse(
            content: 'test',
            model: 'gpt-4',
            usage: $usage,
        );

        $this->assertEquals('stop', $response->finishReason);
        $this->assertEquals('', $response->provider);
        $this->assertNull($response->toolCalls);
        $this->assertNull($response->metadata);
    }

    #[Test]
    public function wasTruncatedReturnsTrueForLengthReason(): void
    {
        $response = new CompletionResponse(
            content: 'truncated content...',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 100, 110),
            finishReason: 'length',
        );

        $this->assertTrue($response->wasTruncated());
    }

    #[Test]
    public function wasTruncatedReturnsFalseForStopReason(): void
    {
        $response = new CompletionResponse(
            content: 'complete content',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: 'stop',
        );

        $this->assertFalse($response->wasTruncated());
    }

    #[Test]
    public function wasFilteredReturnsTrueForContentFilterReason(): void
    {
        $response = new CompletionResponse(
            content: '',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 0, 10),
            finishReason: 'content_filter',
        );

        $this->assertTrue($response->wasFiltered());
    }

    #[Test]
    public function wasFilteredReturnsFalseForOtherReasons(): void
    {
        $response = new CompletionResponse(
            content: 'content',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: 'stop',
        );

        $this->assertFalse($response->wasFiltered());
    }

    #[Test]
    #[DataProvider('completeFinishReasonProvider')]
    public function isCompleteReturnsCorrectValue(string $finishReason, bool $expected): void
    {
        $response = new CompletionResponse(
            content: 'content',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: $finishReason,
        );

        $this->assertEquals($expected, $response->isComplete());
    }

    public static function completeFinishReasonProvider(): array
    {
        return [
            'stop is complete' => ['stop', true],
            'length is not complete' => ['length', false],
            'content_filter is not complete' => ['content_filter', false],
            'tool_calls is not complete' => ['tool_calls', false],
        ];
    }

    #[Test]
    public function hasToolCallsReturnsTrueWhenPresent(): void
    {
        $response = new CompletionResponse(
            content: '',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: 'tool_calls',
            toolCalls: [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                ],
            ],
        );

        $this->assertTrue($response->hasToolCalls());
    }

    #[Test]
    public function hasToolCallsReturnsFalseWhenNull(): void
    {
        $response = new CompletionResponse(
            content: 'content',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            toolCalls: null,
        );

        $this->assertFalse($response->hasToolCalls());
    }

    #[Test]
    public function hasToolCallsReturnsFalseWhenEmpty(): void
    {
        $response = new CompletionResponse(
            content: 'content',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            toolCalls: [],
        );

        $this->assertFalse($response->hasToolCalls());
    }

    #[Test]
    public function getTextReturnsContent(): void
    {
        $content = 'This is the response text';

        $response = new CompletionResponse(
            content: $content,
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
        );

        $this->assertEquals($content, $response->getText());
        $this->assertEquals($response->content, $response->getText());
    }

    #[Test]
    public function responseIsImmutable(): void
    {
        $response = new CompletionResponse(
            content: 'original',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
        );

        // Properties are readonly, so this should work without modification
        $this->assertEquals('original', $response->content);
    }

    #[Test]
    public function multipleToolCallsAreStored(): void
    {
        $toolCalls = [
            ['id' => 'call_1', 'function' => ['name' => 'func1']],
            ['id' => 'call_2', 'function' => ['name' => 'func2']],
            ['id' => 'call_3', 'function' => ['name' => 'func3']],
        ];

        $response = new CompletionResponse(
            content: '',
            model: 'gpt-4',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: 'tool_calls',
            toolCalls: $toolCalls,
        );

        $this->assertCount(3, $response->toolCalls);
        $this->assertEquals('call_2', $response->toolCalls[1]['id']);
    }
}
