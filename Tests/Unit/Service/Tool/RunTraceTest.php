<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
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

        $trace->recordLlmCall(1, 12.5, $response);

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
        // The messages sent live on the round's request step, not the response.
        self::assertNull($step->messagesSent);
    }

    #[Test]
    public function recordsRequestBeforeTheProviderCallAndFiresTheListenerAtOnce(): void
    {
        /** @var list<RunStep> $streamed */
        $streamed = [];
        $trace    = new RunTrace(onRecord: static function (RunStep $step) use (&$streamed): void {
            $streamed[] = $step;
        });

        $trace->recordRequest(1, [ChatMessage::user('translate page 42')], ['fetch']);

        // Streamed immediately — no response needed for the listener to fire.
        self::assertCount(1, $streamed);
        $step = $streamed[0];
        self::assertSame(RunStep::KIND_REQUEST, $step->kind);
        self::assertSame(1, $step->round);
        // A ChatMessage snapshot is flattened to a role/content array.
        self::assertNotNull($step->messagesSent);
        self::assertSame('user', $step->messagesSent[0]['role']);
        self::assertSame(['fetch'], $step->toolSpecs);
        // No timing/tokens on the outbound half.
        self::assertSame(0.0, $step->durationMs);
        self::assertNull($step->totalTokens);
    }

    #[Test]
    public function extractsRawResponseOnlyWhenPresentInMetadata(): void
    {
        $trace = new RunTrace(captureRaw: true);
        self::assertTrue($trace->capturesRaw());

        $withRaw = $this->response(['_raw' => ['done_reason' => 'stop', 'eval_count' => 3]]);
        $trace->recordLlmCall(1, 1.0, $withRaw);
        self::assertSame(['done_reason' => 'stop', 'eval_count' => 3], $trace->getSteps()[0]->raw);

        $withoutRaw = $this->response(null);
        $trace->recordLlmCall(2, 1.0, $withoutRaw);
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

        // No artifacts passed → the step carries null, so toArray() drops the key.
        self::assertNull($tool->toolArtifacts);
    }

    #[Test]
    public function recordsToolExecutionArtifactsOnTheStep(): void
    {
        $artifact = new ToolArtifact(ArtifactType::TABLE, 'pages', ['columns' => ['uid'], 'rows' => [['1']]]);
        $trace    = new RunTrace();
        $trace->recordToolExecution(1, 3.5, 'read_records', [], 'ok', false, [$artifact]);

        $step = $trace->getSteps()[0];
        self::assertSame([$artifact], $step->toolArtifacts);
    }

    #[Test]
    public function firesOnRecordOncePerStepInOrderWithTheRecordedStep(): void
    {
        /** @var list<RunStep> $streamed */
        $streamed = [];
        $trace    = new RunTrace(onRecord: static function (RunStep $step) use (&$streamed): void {
            $streamed[] = $step;
        });

        $trace->recordLlmCall(1, 1.0, $this->response(null));
        $trace->recordToolExecution(1, 2.0, 'fetch', [], 'ok', false);
        $trace->recordAssembledMessages([ChatMessage::user('go')]);

        // The listener saw each step live, in order, and they are the very same
        // objects the trace collected (so a streaming caller and a batch caller
        // observe identical data).
        self::assertCount(3, $streamed);
        self::assertSame($trace->getSteps(), $streamed);
        self::assertSame(RunStep::KIND_LLM, $streamed[0]->kind);
        self::assertSame(RunStep::KIND_TOOL, $streamed[1]->kind);
        self::assertSame(RunStep::KIND_ASSEMBLED, $streamed[2]->kind);
    }

    #[Test]
    public function withoutOnRecordCallbackNothingIsStreamedButStepsCollect(): void
    {
        $trace = new RunTrace();
        $trace->recordToolExecution(1, 1.0, 'fetch', [], 'ok', false);

        // No listener ⇒ no side effect, steps still collected (batch path).
        self::assertCount(1, $trace->getSteps());
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
