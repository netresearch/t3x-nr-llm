<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\TaskCostEstimate;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;

/**
 * Estimates one run of a task before it starts (NEXT-170).
 *
 * The rules are {@see \Netresearch\NrLlm\Service\Tool\EditorActionBatchPlanner}'s
 * estimate, applied to the one provider request a task run sends: the
 * transcript estimator at calibration 1.0, the configuration's `maxTokens` as
 * the output ceiling, and a price range only when both rates and the ceiling
 * exist. See {@see TaskCostEstimate} for what each number means.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TaskCostEstimator
{
    public function __construct(
        private TranscriptEstimator $estimator,
    ) {}

    /**
     * @param ?LlmConfiguration $configuration the configuration the run resolves to, null when none does
     * @param string            $knownInput    the input the server knows; '' when it knows none
     * @param bool              $inputIncluded whether `$knownInput` is the input the run will carry
     */
    public function estimate(Task $task, ?LlmConfiguration $configuration, string $knownInput, bool $inputIncluded): TaskCostEstimate
    {
        if (!$configuration instanceof LlmConfiguration) {
            return TaskCostEstimate::withoutConfiguration($inputIncluded);
        }

        $prompt = $task->buildPrompt(['input' => $knownInput]);
        if ($task->getOutputFormatEnum() === TaskOutputFormat::JSON) {
            $prompt .= TaskExecutionService::JSON_INSTRUCTION;
        }

        $messages = [];
        if ($configuration->getSystemPrompt() !== '') {
            $messages[] = ChatMessage::system($configuration->getSystemPrompt());
        }

        $messages[] = ChatMessage::user($prompt);

        // Calibration 1.0 for the same reason as the batch estimate: the
        // runtime's learned factor belongs to a live context window.
        $inputTokens = $this->estimator->estimate($messages, [], 1.0);

        // maxTokens 0 sends no max_tokens at all: unbounded, not zero.
        $ceiling   = $configuration->getMaxTokens();
        $maxOutput = $ceiling > 0 ? $ceiling : null;

        $model = $configuration->getLlmModel();
        if ($maxOutput === null || !$model instanceof Model || $model->getCostInput() <= 0.0 || $model->getCostOutput() <= 0.0) {
            return new TaskCostEstimate($inputTokens, $maxOutput, $inputIncluded);
        }

        return new TaskCostEstimate(
            $inputTokens,
            $maxOutput,
            $inputIncluded,
            $model->estimateCost($inputTokens, 0),
            $model->estimateCost($inputTokens, $maxOutput),
        );
    }
}
