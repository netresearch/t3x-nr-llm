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
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
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

    /**
     * A transcript without a leading system message is counted together with
     * the prompt that will be prepended to it. The caller passes the prompt it
     * will actually send — the configuration's own text plus its composed
     * snippets (ADR-031) — and that whole size has to land in the estimate,
     * not just the entity's field.
     */
    #[Test]
    public function theEffectiveSystemPromptIsCountedInsteadOfTheConfigurationField(): void
    {
        $messages = [ChatMessage::user('go')];
        $config   = $this->config(128000);
        $config->setSystemPrompt('short prompt');

        $withEntityPrompt    = (new ContextWindowManager())->fit($messages, $config, null, null);
        $withComposedPrompt  = (new ContextWindowManager())->fit(
            $messages,
            $config,
            null,
            null,
            [],
            effectiveSystemPrompt: 'short prompt' . "\n\n" . str_repeat('snippet text ', 400),
        );

        self::assertGreaterThan($withEntityPrompt->estimatedTokens, $withComposedPrompt->estimatedTokens);
    }

    /**
     * The composed prompt only counts when the transcript does not already
     * carry a system message — then it is already in the estimate and adding
     * it again would over-prune.
     */
    #[Test]
    public function anEffectiveSystemPromptIsIgnoredWhenTheTranscriptLeadsWithASystemMessage(): void
    {
        $messages = [ChatMessage::system('sys'), ChatMessage::user('go')];
        $config   = $this->config(128000);
        $config->setSystemPrompt('short prompt');

        $without = (new ContextWindowManager())->fit($messages, $config, null, null);
        $with    = (new ContextWindowManager())->fit(
            $messages,
            $config,
            null,
            null,
            [],
            effectiveSystemPrompt: str_repeat('snippet text ', 400),
        );

        self::assertSame($without->estimatedTokens, $with->estimatedTokens);
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

    #[Test]
    public function injectedTextIsChargedToTheEstimateWithoutJoiningTheMessages(): void
    {
        // The skill block is prepended into the send AFTER fit() returns, so it
        // is on the wire without being in this list. Counting it shrinks the
        // headroom by exactly its own estimate; the list itself is untouched.
        $messages = [ChatMessage::system('sys'), ChatMessage::user('hi'), ...$this->turn('call_1', 'small')];
        $block    = str_repeat('s', 4000);

        $without = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null);
        $with    = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null, [], $block);

        $blockTokens = (new TranscriptEstimator())->estimate([ChatMessage::user($block)], [], 1.15);
        self::assertGreaterThan(0, $blockTokens);
        self::assertSame($without->budget, $with->budget);
        self::assertSame($without->estimatedTokens + $blockTokens, $with->estimatedTokens);
        self::assertSame($messages, $with->messages);
    }

    #[Test]
    public function noInjectedTextLeavesTheCalculationExactlyAsItWas(): void
    {
        $messages = [ChatMessage::system('sys'), ChatMessage::user('hi'), ...$this->turn('call_1', str_repeat('x', 3000))];

        $implicit = (new ContextWindowManager())->fit($messages, $this->config(4000), null, null);
        $explicit = (new ContextWindowManager())->fit($messages, $this->config(4000), null, null, [], '');

        self::assertSame($implicit->budget, $explicit->budget);
        self::assertSame($implicit->estimatedTokens, $explicit->estimatedTokens);
        self::assertSame($implicit->droppedTurns, $explicit->droppedTurns);
        self::assertSame($implicit->messages, $explicit->messages);
    }

    #[Test]
    public function aTranscriptThatFitsAloneIsPrunedOnceTheInjectedBlockCountsToo(): void
    {
        // The defect this guards (#625): an unknown context length falls back to
        // 8192, and a skill block of a few thousand bytes is a large share of
        // that budget. Unaccounted, the transcript "fits" and the send overflows.
        $messages = [
            ChatMessage::system('sys'),
            ChatMessage::user('the task'),
            ...$this->turn('call_1', str_repeat('a', 3000)),
            ...$this->turn('call_2', str_repeat('b', 3000)),
            ...$this->turn('call_3', str_repeat('c', 3000)),
        ];

        $alone = (new ContextWindowManager())->fit($messages, $this->config(0), null, null);
        self::assertFalse($alone->pruned);
        self::assertSame(0, $alone->droppedTurns);

        $withBlock = (new ContextWindowManager())->fit($messages, $this->config(0), null, null, [], str_repeat('s', 12000));

        self::assertTrue($withBlock->pruned);
        self::assertGreaterThanOrEqual(1, $withBlock->droppedTurns);
        // Dropping turns was enough — it prunes into the budget instead of
        // handing the provider an oversized send.
        self::assertFalse($withBlock->overflowAtFloor);
        self::assertLessThanOrEqual($withBlock->budget, $withBlock->estimatedTokens);
    }

    #[Test]
    public function theInjectedBlockIsAccountedForWithoutEnteringTheUndroppableHead(): void
    {
        // The block is prepended to the FIRST user message downstream, and
        // partition() counts everything up to and including that message as the
        // never-droppable head. Injecting it before the fit would grow the head
        // by the block and pay for it with the whole history — and still
        // overflow. fit() therefore accounts for it and leaves the list alone.
        $messages = [
            ChatMessage::system('the system prompt'),
            ChatMessage::user('the task'),
            ...$this->turn('call_1', str_repeat('y', 8000)),
            ...$this->turn('call_2', str_repeat('y', 8000)),
        ];

        $result = (new ContextWindowManager())->fit($messages, $this->config(0), null, null, [], str_repeat('s', 20000));

        $system = $result->messages[0];
        $task   = $result->messages[1];
        self::assertInstanceOf(ChatMessage::class, $system);
        self::assertInstanceOf(ChatMessage::class, $task);
        self::assertSame('system', $this->roleOf($system));
        self::assertSame('user', $this->roleOf($task));
        self::assertSame('the task', $this->contentOf($task));
    }

    /**
     * @return list<ChatMessage>
     */
    private function turn(string $callId, string $result): array
    {
        return [
            ChatMessage::assistantToolCalls([new ToolCall($callId, 'fetch', ['q' => 'x'])]),
            ChatMessage::toolResult($callId, $result),
        ];
    }

    #[Test]
    public function aCriteriaModeConfigurationIsSizedAgainstTheResolvedModel(): void
    {
        // ADR-143. A criteria-mode configuration carries NO model relation —
        // writing the resolution back would convert the record to fixed mode —
        // so reading the window off the entity alone gave every dynamically
        // selected call the unknown-model fallback, however small the model
        // actually serving it was.
        $big      = str_repeat('x', 6000);
        $messages = [
            ChatMessage::system('sys'),
            ChatMessage::user('do the task'),
            ...$this->turn('call_1', $big),
            ...$this->turn('call_2', $big),
            ...$this->turn('call_3', 'the newest result'),
        ];

        $criteriaMode = new LlmConfiguration();
        self::assertNull($criteriaMode->getLlmModel(), 'the premise: no model on the record');

        $small = new Model();
        $small->setContextLength(4000);

        $withoutResolved = (new ContextWindowManager())->fit($messages, $criteriaMode, null, null);
        $withResolved    = (new ContextWindowManager())->fit($messages, $criteriaMode, null, null, [], '', null, $small);

        self::assertFalse($withoutResolved->pruned, 'the fallback window is large enough to hide the overflow');
        self::assertTrue($withResolved->pruned, 'the model that actually serves the call is not');
        self::assertGreaterThan(0, $withResolved->droppedTurns);
    }

    #[Test]
    public function theResolvedModelAlsoSuppliesTheResponseReserve(): void
    {
        // The reserve came off the same entity relation, so a criteria-mode
        // call reserved the generic floor rather than what its model can emit.
        $messages = [ChatMessage::user('hi')];

        $model = new Model();
        $model->setContextLength(10000);
        $model->setMaxOutputTokens(8000);

        $result = (new ContextWindowManager())->fit($messages, new LlmConfiguration(), null, null, [], '', null, $model);

        // 10000 window - 8000 reserve - 3% safety = 1700.
        self::assertSame(1700, $result->budget);
    }

    #[Test]
    public function aFixedModeConfigurationKeepsUsingItsOwnModel(): void
    {
        // No resolved model passed: the entity's relation is the answer, which
        // is the fixed-mode case and the behaviour every caller had before.
        $result = (new ContextWindowManager())->fit([ChatMessage::user('hi')], $this->config(10000, 8000), null, null);

        self::assertSame(1700, $result->budget);
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
