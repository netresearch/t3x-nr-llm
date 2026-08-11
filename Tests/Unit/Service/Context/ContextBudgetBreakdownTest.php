<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextBudgetBreakdown;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ADR-151 accounting: what {@see ContextWindowManager::fit()} reports about
 * where the window went.
 *
 * The load-bearing property is that the lines CLOSE — a breakdown whose
 * components do not add up to the figure the pruning decision used is worse
 * than no breakdown, because a reader would subtract with it.
 */
#[CoversClass(ContextBudgetBreakdown::class)]
#[CoversClass(ContextWindowManager::class)]
final class ContextBudgetBreakdownTest extends TestCase
{
    /** The manager's seed; every assertion here is on a first, uncalibrated fit. */
    private const SEED = 1.15;

    #[Test]
    public function theFourComponentLinesSumToTheEstimateTheFitUsed(): void
    {
        $messages = [ChatMessage::system('sys'), ChatMessage::user('hi'), ...$this->turn('call_1', 'small')];

        $result    = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null);
        $breakdown = $result->breakdown;

        self::assertSame(
            $breakdown->estimatedTokens,
            $breakdown->transcriptTokens
                + $breakdown->toolSchemaTokens
                + $breakdown->systemPromptTokens
                + $breakdown->skillTokens,
        );
        self::assertSame($result->estimatedTokens, $breakdown->estimatedTokens);
    }

    #[Test]
    public function theLinesCloseWhenAllFourOfThemCarrySomething(): void
    {
        // The sum above is a four-term sum only on paper: with no tool specs, no
        // injected block and a leading system message, three of its addends are
        // structurally 0 and it would hold with them hardcoded. This is the same
        // invariant over a fit where every line is non-empty at once — no
        // leading system message, schemas on the wire, a skill block and a
        // prompt the shaper will prepend.
        $toolSpecs = [[
            'type'     => 'function',
            'function' => [
                'name'        => 'fetch_page',
                'description' => str_repeat('d', 400),
                'parameters'  => ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']]],
            ],
        ]];

        $breakdown = (new ContextWindowManager())
            ->fit(
                [ChatMessage::user('hi')],
                $this->config(128000),
                null,
                null,
                $toolSpecs,
                str_repeat('s', 4000),
                str_repeat('p', 2000),
            )
            ->breakdown;

        self::assertGreaterThan(0, $breakdown->transcriptTokens);
        self::assertGreaterThan(0, $breakdown->toolSchemaTokens);
        self::assertGreaterThan(0, $breakdown->systemPromptTokens);
        self::assertGreaterThan(0, $breakdown->skillTokens);
        self::assertSame(
            $breakdown->estimatedTokens,
            $breakdown->transcriptTokens
                + $breakdown->toolSchemaTokens
                + $breakdown->systemPromptTokens
                + $breakdown->skillTokens,
        );
    }

    #[Test]
    public function theWindowArithmeticCloses(): void
    {
        $model = new Model();
        $model->setContextLength(10000);
        $model->setMaxOutputTokens(8000);

        $breakdown = (new ContextWindowManager())
            ->fit([ChatMessage::user('hi')], new LlmConfiguration(), null, null, [], '', null, $model)
            ->breakdown;

        self::assertSame(10000, $breakdown->contextLength);
        self::assertSame(8000, $breakdown->reservedOutput);
        self::assertSame(300, $breakdown->safetyMargin);
        self::assertSame(1700, $breakdown->budget);
        self::assertSame(
            $breakdown->contextLength - $breakdown->reservedOutput - $breakdown->safetyMargin,
            $breakdown->budget,
        );
        self::assertSame($breakdown->budget - $breakdown->estimatedTokens, $breakdown->remaining);
    }

    #[Test]
    public function theToolSchemaGetsItsOwnLineAndTheLinesStillClose(): void
    {
        $messages  = [ChatMessage::system('sys'), ChatMessage::user('hi')];
        $toolSpecs = [[
            'type'     => 'function',
            'function' => [
                'name'        => 'fetch_page',
                'description' => str_repeat('d', 400),
                'parameters'  => ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']]],
            ],
        ]];

        $withoutTools = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null)->breakdown;
        $withTools    = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null, $toolSpecs)->breakdown;

        self::assertSame(0, $withoutTools->toolSchemaTokens, 'no schema on the wire, no schema line');
        self::assertGreaterThan(0, $withTools->toolSchemaTokens);
        // The transcript line is unchanged by the schema: the two really are
        // separate lines and not one figure split by guesswork.
        self::assertSame($withoutTools->transcriptTokens, $withTools->transcriptTokens);
        self::assertSame(
            $withTools->estimatedTokens,
            $withTools->transcriptTokens + $withTools->toolSchemaTokens,
        );
    }

    #[Test]
    public function theInjectedSkillBlockGetsItsOwnLine(): void
    {
        $messages = [ChatMessage::system('sys'), ChatMessage::user('hi')];
        $block    = str_repeat('s', 4000);

        $breakdown = (new ContextWindowManager())
            ->fit($messages, $this->config(128000), null, null, [], $block)
            ->breakdown;

        self::assertSame(
            (new TranscriptEstimator())->estimate([ChatMessage::user($block)], [], self::SEED),
            $breakdown->skillTokens,
        );
        self::assertSame(
            $breakdown->estimatedTokens,
            $breakdown->transcriptTokens + $breakdown->skillTokens,
        );
    }

    #[Test]
    public function aLeadingSystemMessagePutsThePromptInsideTheTranscriptLine(): void
    {
        // Zero on the system-prompt line means two different things, and a
        // surface must be able to tell them apart: here the prompt is on the
        // wire, inside the transcript, and the flag says so.
        $messages = [ChatMessage::system('the system prompt'), ChatMessage::user('hi')];

        $breakdown = (new ContextWindowManager())
            ->fit($messages, $this->config(128000), null, null, [], '', 'a composed prompt that is NOT prepended')
            ->breakdown;

        self::assertTrue($breakdown->systemPromptInTranscript);
        self::assertSame(0, $breakdown->systemPromptTokens);
    }

    #[Test]
    public function aPromptThePromptShaperWillPrependGetsItsOwnLine(): void
    {
        $messages = [ChatMessage::user('hi')];
        $prompt   = str_repeat('p', 2000);

        $breakdown = (new ContextWindowManager())
            ->fit($messages, $this->config(128000), null, null, [], '', $prompt)
            ->breakdown;

        self::assertFalse($breakdown->systemPromptInTranscript);
        self::assertSame(
            (new TranscriptEstimator())->estimate([ChatMessage::system($prompt)], [], self::SEED),
            $breakdown->systemPromptTokens,
        );
    }

    #[Test]
    public function composedSnippetsAreCountedOnTheSystemPromptLineAndNowhereElse(): void
    {
        // Snippets are appended INTO the effective system prompt by
        // ConfigurationSnippetResolver before fit() ever sees them (ADR-031), so
        // the breakdown cannot give them a line of their own. What it must not
        // do is lose them: the system-prompt line grows by exactly the block.
        $messages = [ChatMessage::user('hi')];
        $prompt   = 'the configuration prompt';
        $composed = $prompt . "\n\n" . str_repeat('snippet text ', 200);

        $bare     = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null, [], '', $prompt)->breakdown;
        $withSnip = (new ContextWindowManager())->fit($messages, $this->config(128000), null, null, [], '', $composed)->breakdown;

        self::assertGreaterThan($bare->systemPromptTokens, $withSnip->systemPromptTokens);
        self::assertSame($bare->transcriptTokens, $withSnip->transcriptTokens, 'not smuggled into the transcript line');
        self::assertSame(0, $withSnip->skillTokens, 'not smuggled into the skill line');
    }

    #[Test]
    public function theTranscriptLineMeasuresThePrunedListNotTheOriginal(): void
    {
        $big      = str_repeat('x', 6000);
        $messages = [
            ChatMessage::system('sys'),
            ChatMessage::user('do the task'),
            ...$this->turn('call_1', $big),
            ...$this->turn('call_2', $big),
            ...$this->turn('call_3', 'the newest result'),
        ];

        $result = (new ContextWindowManager())->fit($messages, $this->config(4000), null, null);
        self::assertTrue($result->pruned, 'the premise: this fit drops turns');

        self::assertSame(
            (new TranscriptEstimator())->estimate($result->messages, [], self::SEED),
            $result->breakdown->transcriptTokens,
        );
        self::assertGreaterThanOrEqual(0, $result->breakdown->remaining);
    }

    #[Test]
    public function remainingIsNegativeWhenEvenTheFloorOverflows(): void
    {
        $messages = [
            ChatMessage::system('sys'),
            ChatMessage::user(str_repeat('u', 40000)),
            ...$this->turn('call_1', 'small'),
        ];

        $result = (new ContextWindowManager())->fit($messages, $this->config(4000), null, null);
        self::assertTrue($result->overflowAtFloor, 'the premise: nothing left to drop and it still does not fit');

        self::assertLessThan(0, $result->breakdown->remaining);
        self::assertTrue($result->breakdown->isMeasured());
    }

    #[Test]
    public function aNonPositiveBudgetReportsThatNothingWasMeasured(): void
    {
        // The reserve exceeds the whole window: the manager defers to the
        // provider without estimating, so the readout must say "no accounting"
        // rather than render a window of zero as a measurement.
        $result = (new ContextWindowManager())->fit([ChatMessage::user('hi')], $this->config(1000, 2000), null, null);

        self::assertFalse($result->breakdown->isMeasured());
        self::assertSame(ContextBudgetBreakdown::none()->toArray(), $result->breakdown->toArray());
        self::assertFalse($result->breakdown->toArray()['measured'], 'the client reads this key, not contextLength');
    }

    #[Test]
    public function theSerialisedShapeCarriesEveryLine(): void
    {
        $breakdown = new ContextBudgetBreakdown(8192, 1000, 246, 6946, 500, 40, 30, false, 20, 590, 6356);

        self::assertSame([
            // Serialised, not re-derived: the client renders "no accounting"
            // off this key rather than spelling out the predicate itself.
            'measured'                 => true,
            'contextLength'            => 8192,
            'reservedOutput'           => 1000,
            'safetyMargin'             => 246,
            'budget'                   => 6946,
            'transcriptTokens'         => 500,
            'toolSchemaTokens'         => 40,
            'systemPromptTokens'       => 30,
            'systemPromptInTranscript' => false,
            'skillTokens'              => 20,
            'estimatedTokens'          => 590,
            'remaining'                => 6356,
        ], $breakdown->toArray());
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
}
