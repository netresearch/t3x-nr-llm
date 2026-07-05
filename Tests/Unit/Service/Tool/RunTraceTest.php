<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunTrace::class)]
final class RunTraceTest extends TestCase
{
    #[Test]
    public function recordsLlmCallWithTokenSplitThinkingAndRequestedCalls(): void
    {
        $trace    = new RunTrace();
        $response = new CompletionResponse(
            content: 'let me look that up',
            model: 'gpt-x',
            usage: new UsageStatistics(promptTokens: 40, completionTokens: 8, totalTokens: 48, estimatedCost: 0.0012),
            finishReason: 'tool_calls',
            toolCalls: [new ToolCall(id: 'c1', name: 'fetch', arguments: ['uid' => 42])],
            thinking: 'need the page first',
        );

        $trace->recordLlmCall(1, 12.5, [ChatMessage::user('translate page 42')], ['fetch'], $response);

        $steps = $trace->getSteps();
        self::assertCount(1, $steps);
        $step = $steps[0];
        self::assertSame(RunStep::KIND_LLM, $step->kind);
        self::assertSame(40, $step->promptTokens);
        self::assertSame(8, $step->completionTokens);
        self::assertSame(48, $step->totalTokens);
        self::assertSame(0.0012, $step->estimatedCost);
        self::assertSame('need the page first', $step->thinking);
        self::assertSame('tool_calls', $step->finishReason);
        self::assertNotNull($step->requestedToolCalls);
        self::assertSame('fetch', $step->requestedToolCalls[0]['name']);
        self::assertSame(['uid' => 42], $step->requestedToolCalls[0]['arguments']);
        // A ChatMessage snapshot is flattened to a role/content array.
        self::assertNotNull($step->messagesSent);
        self::assertSame('user', $step->messagesSent[0]['role']);
    }

    #[Test]
    public function extractsRawResponseOnlyWhenPresentInMetadata(): void
    {
        $trace = new RunTrace(captureRaw: true);
        self::assertTrue($trace->capturesRaw());

        $withRaw = $this->response(['_raw' => ['done_reason' => 'stop', 'eval_count' => 3]]);
        $trace->recordLlmCall(1, 1.0, [], [], $withRaw);
        self::assertSame(['done_reason' => 'stop', 'eval_count' => 3], $trace->getSteps()[0]->raw);

        $withoutRaw = $this->response(null);
        $trace->recordLlmCall(2, 1.0, [], [], $withoutRaw);
        self::assertNull($trace->getSteps()[1]->raw);
    }

    #[Test]
    public function recordsToolExecutionAndAssembledMessages(): void
    {
        $trace = new RunTrace();
        $trace->recordToolExecution(1, 3.5, 'fetch', ['uid' => 42], '{"title":"About"}', false);
        $trace->recordAssembledMessages([ChatMessage::system('be precise'), ChatMessage::user('go')]);

        $tool = $trace->getSteps()[0];
        self::assertSame(RunStep::KIND_TOOL, $tool->kind);
        self::assertSame('fetch', $tool->toolName);
        self::assertSame(['uid' => 42], $tool->toolArguments);
        self::assertFalse($tool->toolIsError);

        $assembled = $trace->getSteps()[1];
        self::assertSame(RunStep::KIND_ASSEMBLED, $assembled->kind);
        self::assertNotNull($assembled->messagesSent);
        self::assertCount(2, $assembled->messagesSent);
        self::assertSame('system', $assembled->messagesSent[0]['role']);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function response(?array $metadata): CompletionResponse
    {
        return new CompletionResponse(
            content: 'ok',
            model: 'm',
            usage: UsageStatistics::fromTokens(1, 1),
            metadata: $metadata,
        );
    }
}
