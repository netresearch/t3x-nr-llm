<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextWindowManager::class)]
final class ContextWindowManagerTest extends TestCase
{
    #[Test]
    public function fitsUnderBudgetReturnsPassthroughUnchanged(): void
    {
        $messages = [ChatMessage::system('sys'), ChatMessage::user('hi'), ...$this->turn('call_1', 'small')];

        $result = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null);

        self::assertFalse($result->pruned);
        self::assertSame($messages, $result->messages);
        self::assertSame(0, $result->droppedTurns);
    }

    #[Test]
    public function dropsOldestWholeTurnsWhenOverBudgetKeepingPairingAndTheNewest(): void
    {
        $big      = str_repeat('x', 6000);
        $messages = [
            ChatMessage::system('sys'),
            ChatMessage::user('do the task'),
            ...$this->turn('call_1', $big),   // oldest
            ...$this->turn('call_2', $big),
            ...$this->turn('call_3', 'the newest result'),
        ];

        $result = (new ContextWindowManager())->fit($messages, $this->config(4000), null, null);

        self::assertTrue($result->pruned);
        self::assertGreaterThanOrEqual(1, $result->droppedTurns);
        self::assertFalse($result->overflowAtFloor);
        // Head survives.
        self::assertSame('system', $this->roleOf($result->messages[0]));
        self::assertSame('user', $this->roleOf($result->messages[1]));
        // The newest turn survives.
        $roles = array_map($this->roleOf(...), $result->messages);
        self::assertContains('tool', $roles);
        // Pairing intact: every tool result has a preceding assistant tool-call.
        self::assertTrue($this->pairingValid($result->messages));
    }

    #[Test]
    public function neverDropsTheLeadingSystemAndTaskUnderExtremePressure(): void
    {
        $big      = str_repeat('y', 8000);
        $messages = [
            ChatMessage::system('the system prompt'),
            ChatMessage::user('the task'),
            ...$this->turn('call_1', $big),
            ...$this->turn('call_2', $big),
        ];

        $result = (new ContextWindowManager())->fit($messages, $this->config(2000), null, null);

        self::assertSame('system', $this->roleOf($result->messages[0]));
        self::assertSame('user', $this->roleOf($result->messages[1]));
        self::assertSame('the task', $this->contentOf($result->messages[1]));
    }

    #[Test]
    public function overflowAtFloorWhenEvenTheNewestTurnIsTooBig(): void
    {
        $huge     = str_repeat('z', 40000);
        $messages = [ChatMessage::system('sys'), ChatMessage::user('go'), ...$this->turn('call_1', $huge)];

        $result = (new ContextWindowManager())->fit($messages, $this->config(4000), null, null);

        self::assertTrue($result->overflowAtFloor);
        self::assertTrue($result->pruned);
    }

    #[Test]
    public function unknownContextLengthUsesTheFallbackCeilingNotANoOp(): void
    {
        $big      = str_repeat('q', 30000);
        $messages = [ChatMessage::system('sys'), ChatMessage::user('go'), ...$this->turn('call_1', $big), ...$this->turn('call_2', 'newest')];

        // contextLength 0 (unknown) -> 8192 fallback ceiling still bounds it.
        $result = (new ContextWindowManager())->fit($messages, $this->config(0), null, null);

        self::assertTrue($result->pruned);
        self::assertSame(8192 - 1000 - (int)ceil(8192 * 0.03), $result->budget);
    }

    #[Test]
    public function optionMaxTokensTakesReservePrecedenceOverTheModelCap(): void
    {
        $messages = [ChatMessage::system('sys'), ChatMessage::user('go')];
        $options  = (new ChatOptions())->withMaxTokens(5000);

        $result = (new ContextWindowManager())->fit($messages, $this->config(20000, 1000), $options, null);

        // budget = ctx - option maxTokens(5000) - safety, NOT the model cap 1000.
        self::assertSame(20000 - 5000 - (int)ceil(20000 * 0.03), $result->budget);
    }

    #[Test]
    public function calibrationRisesWhenTheEstimateUnderShotTheRealPromptTokens(): void
    {
        $manager  = new ContextWindowManager();
        $messages = [ChatMessage::system('sys'), ChatMessage::user('go'), ...$this->turn('call_1', str_repeat('a', 2000)), ...$this->turn('call_2', 'newest')];
        $config   = $this->config(128000);

        // First send establishes lastSentEstimate.
        $first = $manager->fit($messages, $config, null, null);

        // Report real usage far above the estimate -> calibration must climb.
        $second = $manager->fit($messages, $config, null, UsageStatistics::fromTokens($first->estimatedTokens * 3, 0));

        self::assertGreaterThan(1.15, $second->calibration);
        self::assertGreaterThan($first->estimatedTokens, $second->estimatedTokens);
    }

    #[Test]
    public function nonPositiveBudgetDefersTheWholeTranscriptToTheProvider(): void
    {
        // A misconfiguration where the reserved output room (max output tokens)
        // is larger than the entire context window leaves no room to prune
        // into. Rather than compute a negative budget and drop everything, the
        // manager passes the transcript through untouched and lets the provider
        // enforce its own limit.
        $messages = [ChatMessage::system('sys'), ChatMessage::user('hi'), ...$this->turn('call_1', 'small')];

        $result = (new ContextWindowManager())->fit($messages, $this->config(1000, 2000), null, null);

        self::assertFalse($result->pruned);
        self::assertFalse($result->overflowAtFloor);
        self::assertSame(0, $result->droppedTurns);
        self::assertSame($messages, $result->messages);
    }

    /**
     * @return list<ChatMessage>
     */
    private function turn(string $callId, string $result): array
    {
        return [
            ChatMessage::assistantToolCalls([new ToolCall($callId, 'fetch', ['q' => 'x'])], null),
            ChatMessage::toolResult($callId, $result),
        ];
    }

    private function config(int $contextLength, int $maxOutputTokens = 0): LlmConfiguration
    {
        $model = new Model();
        $model->setContextLength($contextLength);
        $model->setMaxOutputTokens($maxOutputTokens);

        $config = new LlmConfiguration();
        $config->setLlmModel($model);

        return $config;
    }

    private function roleOf(ChatMessage $message): string
    {
        return $message->toArray()['role'] ?? '';
    }

    private function contentOf(ChatMessage $message): string
    {
        $content = $message->toArray()['content'] ?? '';

        return is_string($content) ? $content : '';
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function pairingValid(array $messages): bool
    {
        $open = [];
        foreach ($messages as $message) {
            $data = $message instanceof ChatMessage ? $message->toArray() : $message;
            $role = $data['role'] ?? '';
            if ($role === 'assistant') {
                $toolCalls = is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [];
                foreach ($toolCalls as $call) {
                    if (is_array($call) && is_string($call['id'] ?? null)) {
                        $open[$call['id']] = true;
                    }
                }
            } elseif ($role === 'tool') {
                $id = is_string($data['tool_call_id'] ?? null) ? $data['tool_call_id'] : '';
                if ($id === '' || !isset($open[$id])) {
                    return false;
                }
            }
        }

        return true;
    }
}
