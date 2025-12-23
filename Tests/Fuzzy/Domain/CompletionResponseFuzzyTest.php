<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for CompletionResponse
 */
#[CoversClass(CompletionResponse::class)]
class CompletionResponseFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function completionResponsePreservesContent(): void
    {
        $this
            ->forAll(Generator\string())
            ->then(function (string $content) {
                $usage = new UsageStatistics(10, 20, 30);
                $response = new CompletionResponse(
                    content: $content,
                    model: 'gpt-4o',
                    finishReason: 'stop',
                    usage: $usage,
                    provider: 'openai'
                );

                $this->assertSame($content, $response->content);
            });
    }

    #[Test]
    public function isCompleteReturnsTrueOnlyForStopFinishReason(): void
    {
        $this
            ->forAll(Generator\string())
            ->then(function (string $content) {
                $usage = new UsageStatistics(10, 20, 30);
                $response = new CompletionResponse(
                    content: $content,
                    model: 'test-model',
                    finishReason: 'stop',
                    usage: $usage,
                    provider: 'test'
                );

                $this->assertTrue($response->isComplete());
            });
    }

    #[Test]
    public function wasTruncatedReturnsTrueForLengthFinishReason(): void
    {
        $this
            ->forAll(Generator\string())
            ->then(function (string $content) {
                $usage = new UsageStatistics(10, 20, 30);
                $response = new CompletionResponse(
                    content: $content,
                    model: 'test-model',
                    finishReason: 'length',
                    usage: $usage,
                    provider: 'test'
                );

                $this->assertTrue($response->wasTruncated());
                $this->assertFalse($response->isComplete());
            });
    }

    #[Test]
    public function modelAndProviderArePreserved(): void
    {
        $this
            ->forAll(
                Generator\suchThat(
                    static fn(string $s) => strlen(trim($s)) > 0 && strlen($s) < 50,
                    Generator\string()
                ),
                Generator\suchThat(
                    static fn(string $s) => strlen(trim($s)) > 0 && strlen($s) < 50,
                    Generator\string()
                )
            )
            ->then(function (string $model, string $provider) {
                $usage = new UsageStatistics(10, 20, 30);
                $response = new CompletionResponse(
                    content: 'test content',
                    model: $model,
                    finishReason: 'stop',
                    usage: $usage,
                    provider: $provider
                );

                $this->assertSame($model, $response->model);
                $this->assertSame($provider, $response->provider);
            });
    }

    #[Test]
    public function usageStatisticsArePreserved(): void
    {
        $this
            ->forAll(
                Generator\choose(0, 100000),
                Generator\choose(0, 100000)
            )
            ->then(function (int $promptTokens, int $completionTokens) {
                $totalTokens = $promptTokens + $completionTokens;
                $usage = new UsageStatistics($promptTokens, $completionTokens, $totalTokens);

                $response = new CompletionResponse(
                    content: 'test',
                    model: 'test-model',
                    finishReason: 'stop',
                    usage: $usage,
                    provider: 'test'
                );

                $this->assertEquals($promptTokens, $response->usage->promptTokens);
                $this->assertEquals($completionTokens, $response->usage->completionTokens);
                $this->assertEquals($totalTokens, $response->usage->totalTokens);
            });
    }

    #[Test]
    public function finishReasonDeterminesCompletionState(): void
    {
        $this
            ->forAll(
                Generator\elements(['stop', 'length', 'tool_calls', 'content_filter'])
            )
            ->then(function (string $finishReason) {
                $usage = new UsageStatistics(10, 20, 30);
                $response = new CompletionResponse(
                    content: 'test',
                    model: 'test-model',
                    finishReason: $finishReason,
                    usage: $usage,
                    provider: 'test'
                );

                // Only 'stop' returns true for isComplete
                $isComplete = $finishReason === 'stop';
                $wasTruncated = $finishReason === 'length';

                $this->assertSame($isComplete, $response->isComplete());
                $this->assertSame($wasTruncated, $response->wasTruncated());
            });
    }
}
