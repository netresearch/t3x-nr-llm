<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Task;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\ValueObject\TaskCostEstimate;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use Netresearch\NrLlm\Service\Task\TaskCostEstimator;
use Netresearch\NrLlm\Service\Task\TaskExecutionService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The cost estimate on the AI Tasks run form (NEXT-170).
 *
 * The expected token count is worked out by hand from TranscriptEstimator's
 * rules, not by calling it, so a change to what the estimate counts shows up
 * here: system "You are terse." (14 bytes) + user "Summarise: hello"
 * (16 bytes) = 30 prose bytes / 3.5 -> 9, the empty tool block "[]" 2 bytes /
 * 2.5 -> 1, two messages x 8 overhead -> 16. Total 26.
 */
#[CoversClass(TaskCostEstimator::class)]
#[CoversClass(TaskCostEstimate::class)]
final class TaskCostEstimatorTest extends AbstractUnitTestCase
{
    private const INPUT_TOKENS = 26;

    #[Test]
    public function theRangeRunsFromInputOnlyToInputPlusTheFullCeiling(): void
    {
        $estimate = $this->estimator()->estimate($this->task(), $this->configuration(), 'hello', true);

        self::assertSame(self::INPUT_TOKENS, $estimate->inputTokens);
        self::assertSame(1000, $estimate->maxOutputTokens);
        self::assertTrue($estimate->isPriced());
        // $10 per million input, $30 per million output.
        self::assertEqualsWithDelta(26 * 10 / 1_000_000, $estimate->costLow, 1e-12);
        self::assertEqualsWithDelta(26 * 10 / 1_000_000 + 1000 * 30 / 1_000_000, $estimate->costHigh, 1e-12);
        self::assertEqualsWithDelta($estimate->costHigh, $estimate->plannedCost(), 1e-12);
    }

    #[Test]
    public function aModelPricedOnOneSideOnlyGivesNoRange(): void
    {
        $configuration = $this->configuration();
        $configuration->getLlmModel()?->setCostOutput(0);

        $estimate = $this->estimator()->estimate($this->task(), $configuration, 'hello', true);

        self::assertFalse($estimate->isPriced());
        self::assertNull($estimate->costLow);
        self::assertNull($estimate->costHigh);
        self::assertSame(0.0, $estimate->plannedCost());
        self::assertSame(self::INPUT_TOKENS, $estimate->inputTokens);
    }

    #[Test]
    public function anInputPriceOfZeroGivesNoRangeEither(): void
    {
        $configuration = $this->configuration();
        $configuration->getLlmModel()?->setCostInput(0);

        self::assertFalse($this->estimator()->estimate($this->task(), $configuration, 'hello', true)->isPriced());
    }

    #[Test]
    public function noCeilingMeansUnboundedAndNoRange(): void
    {
        $configuration = $this->configuration();
        $configuration->setMaxTokens(0);

        $estimate = $this->estimator()->estimate($this->task(), $configuration, 'hello', true);

        self::assertNull($estimate->maxOutputTokens);
        self::assertFalse($estimate->isPriced());
    }

    #[Test]
    public function noConfigurationGivesNoEstimate(): void
    {
        $estimate = $this->estimator()->estimate($this->task(), null, 'hello', false);

        self::assertSame(0, $estimate->inputTokens);
        self::assertFalse($estimate->isPriced());
        self::assertFalse($estimate->inputIncluded);
    }

    #[Test]
    public function theKnownInputIsCountedAndTheFlagCarried(): void
    {
        $short = $this->estimator()->estimate($this->task(), $this->configuration(), '', false);
        // 35 more prose bytes -> 10 more tokens.
        $long = $this->estimator()->estimate($this->task(), $this->configuration(), 'hello' . str_repeat('x', 35), true);

        self::assertFalse($short->inputIncluded);
        self::assertTrue($long->inputIncluded);
        // "Summarise: " alone: 25 prose bytes -> 8, one fewer.
        self::assertSame(self::INPUT_TOKENS - 1, $short->inputTokens);
        self::assertSame(self::INPUT_TOKENS + 10, $long->inputTokens);
    }

    #[Test]
    public function aJsonTaskCountsTheInstructionTheRunAppends(): void
    {
        $task = $this->task();
        $task->setOutputFormat('json');

        $estimate = $this->estimator()->estimate($task, $this->configuration(), 'hello', true);

        $extra = (int) ceil((30 + strlen(TaskExecutionService::JSON_INSTRUCTION)) / 3.5) - 9;
        self::assertSame(self::INPUT_TOKENS + $extra, $estimate->inputTokens);
    }

    #[Test]
    public function anEmptySystemPromptSendsNoSystemMessage(): void
    {
        $configuration = $this->configuration();
        $configuration->setSystemPrompt('');

        $estimate = $this->estimator()->estimate($this->task(), $configuration, 'hello', true);

        // 16 prose bytes -> 5, "[]" -> 1, one message -> 8.
        self::assertSame(14, $estimate->inputTokens);
    }

    private function estimator(): TaskCostEstimator
    {
        return new TaskCostEstimator(new TranscriptEstimator());
    }

    private function task(): Task
    {
        $task = new Task();
        $task->setPromptTemplate('Summarise: {{input}}');
        $task->setOutputFormat('markdown');

        return $task;
    }

    private function configuration(): LlmConfiguration
    {
        $model = new Model();
        // Stored as cents per million tokens: $10 and $30.
        $model->setCostInput(1000);
        $model->setCostOutput(3000);

        $configuration = new LlmConfiguration();
        $configuration->setSystemPrompt('You are terse.');
        $configuration->setMaxTokens(1000);
        $configuration->setLlmModel($model);

        return $configuration;
    }
}
