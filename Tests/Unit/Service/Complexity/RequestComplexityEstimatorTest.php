<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Complexity;

use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextBudgetBreakdown;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Complexity\RequestComplexityEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestComplexityEstimator::class)]
final class RequestComplexityEstimatorTest extends TestCase
{
    #[Test]
    public function aSingleQuestionIsASingleTurnRegardlessOfItsSystemPrompt(): void
    {
        // The system prompt is configuration, not conversation. Counting it as
        // a turn would make every configuration with a prompt look like a
        // dialogue.
        $complexity = (new RequestComplexityEstimator())->estimate(
            [ChatMessage::system('You are terse.'), ChatMessage::user('Why?')],
            0,
            null,
        );

        self::assertSame(RequestShape::SINGLE_TURN->value, $complexity->shape);
    }

    #[Test]
    public function severalNonSystemMessagesAreAConversation(): void
    {
        $complexity = (new RequestComplexityEstimator())->estimate(
            [
                ChatMessage::system('You are terse.'),
                ChatMessage::user('Why?'),
                ChatMessage::assistant('Because.'),
                ChatMessage::user('And?'),
            ],
            0,
            null,
        );

        self::assertSame(RequestShape::MULTI_TURN->value, $complexity->shape);
    }

    #[Test]
    public function toolSchemasOnTheWireMakeItToolAssisted(): void
    {
        // Even on the very first turn: the send carries tools, so the model may
        // call one, and that is a different kind of request from a question.
        $complexity = (new RequestComplexityEstimator())->estimate(
            [ChatMessage::user('Delete page 4.')],
            3,
            null,
        );

        self::assertSame(RequestShape::TOOL_ASSISTED->value, $complexity->shape);
        self::assertSame(3, $complexity->toolCount);
    }

    #[Test]
    public function aToolResultInTheTranscriptMakesItToolAssisted(): void
    {
        // The resumed half of a tool exchange carries no schemas, and calling
        // it a plain conversation would split one interaction across two
        // shapes.
        $complexity = (new RequestComplexityEstimator())->estimate(
            [
                ChatMessage::user('Delete page 4.'),
                ChatMessage::assistantToolCalls([new ToolCall('call-1', 'delete_page', ['uid' => 4])]),
                ChatMessage::toolResult('call-1', 'done'),
            ],
            0,
            null,
        );

        self::assertSame(RequestShape::TOOL_ASSISTED->value, $complexity->shape);
    }

    #[Test]
    public function theSizeIsCountedInBytesOverTheMessageContents(): void
    {
        $complexity = (new RequestComplexityEstimator())->estimate(
            [ChatMessage::system('abc'), ChatMessage::user('defgh')],
            0,
            null,
        );

        self::assertSame(8, $complexity->payloadBytes);
    }

    #[Test]
    public function withoutAFitTheTokenAndWindowFiguresStayNull(): void
    {
        // "No fit ran" is not "the send was empty". A zero here would be a
        // measurement nobody took.
        $complexity = (new RequestComplexityEstimator())->estimate([ChatMessage::user('hi')], 0, null);

        self::assertNull($complexity->tokenEstimate);
        self::assertNull($complexity->contextPercent);
    }

    #[Test]
    public function contextUtilisationComesFromTheFit(): void
    {
        $complexity = (new RequestComplexityEstimator())->estimate(
            [ChatMessage::user('hi')],
            0,
            $this->fit(estimatedTokens: 250, budget: 1000),
        );

        self::assertSame(250, $complexity->tokenEstimate);
        self::assertSame(25, $complexity->contextPercent);
    }

    #[Test]
    public function anOverflowingSendReportsAboveOneHundredPercent(): void
    {
        // Clamping would hide the exact case ADR-143 exists to report.
        $complexity = (new RequestComplexityEstimator())->estimate(
            [ChatMessage::user('hi')],
            0,
            $this->fit(estimatedTokens: 3000, budget: 1000),
        );

        self::assertSame(300, $complexity->contextPercent);
    }

    #[Test]
    public function anUnknownBudgetYieldsNoUtilisation(): void
    {
        // A zero budget means the window is unknown for this model; dividing by
        // it would report a utilisation the fit never claimed.
        $complexity = (new RequestComplexityEstimator())->estimate(
            [ChatMessage::user('hi')],
            0,
            $this->fit(estimatedTokens: 250, budget: 0),
        );

        self::assertNull($complexity->contextPercent);
    }

    #[Test]
    public function theScoreRisesWithTurnsToolsAndWindowUseAndStopsAtOneHundred(): void
    {
        $estimator = new RequestComplexityEstimator();

        $simple = $estimator->estimate([ChatMessage::user('hi')], 0, $this->fit(0, 1000));

        $messages = [];
        for ($i = 0; $i < 12; ++$i) {
            $messages[] = ChatMessage::user('turn ' . $i);
        }

        $involved = $estimator->estimate($messages, 9, $this->fit(2000, 1000));

        self::assertSame(5, $simple->score);
        self::assertSame(100, $involved->score, 'every term is capped, so the total cannot run away');
        self::assertGreaterThan($simple->score, $involved->score);
    }

    #[Test]
    public function anUnmeasuredWindowContributesNothingRatherThanAMidpoint(): void
    {
        // The neutral-midpoint rule belongs to ranking, where an absent signal
        // must not demote a candidate against its rivals. Here there are no
        // rivals, and half a term for a send nobody measured would be a number
        // no measurement produced.
        $estimator = new RequestComplexityEstimator();

        $withoutFit = $estimator->estimate([ChatMessage::user('hi')], 0, null);
        $emptyFit   = $estimator->estimate([ChatMessage::user('hi')], 0, $this->fit(0, 1000));

        self::assertSame($emptyFit->score, $withoutFit->score);
    }

    #[Test]
    public function legacyArrayShapedMessagesAreMeasuredToo(): void
    {
        // The manager's message lists are normalised, but the type still admits
        // arrays; measuring nothing for them would silently under-report.
        $complexity = (new RequestComplexityEstimator())->estimate(
            [
                ['role' => 'user', 'content' => 'abcd'],
                ['role' => 'tool', 'content' => 'ok'],
            ],
            0,
            null,
        );

        self::assertSame(6, $complexity->payloadBytes);
        self::assertSame(RequestShape::TOOL_ASSISTED->value, $complexity->shape);
    }

    private function fit(int $estimatedTokens, int $budget): ContextFitResult
    {
        return new ContextFitResult(
            messages: [],
            pruned: false,
            droppedTurns: 0,
            keptTurns: 0,
            estimatedTokens: $estimatedTokens,
            budget: $budget,
            overflowAtFloor: $estimatedTokens > $budget,
            calibration: 1.0,
            // The estimator reads estimatedTokens and budget off the fit, not
            // the per-source accounting, so these cases carry none() — the
            // unmeasured breakdown — rather than inventing numbers that would
            // suggest the assertions depend on them.
            breakdown: ContextBudgetBreakdown::none(),
        );
    }
}
