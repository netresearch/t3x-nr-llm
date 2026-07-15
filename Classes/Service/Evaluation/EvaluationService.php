<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Runs a golden prompt set against a model and aggregates the per-prompt
 * gradings into a set result (ADR-060).
 *
 * This is an explicitly invoked, out-of-request operation: it calls the
 * model once per prompt via the existing CompletionService, grades each
 * response through GradingService, and records the wall-clock latency. It
 * neither persists nor compares — that is the caller's job (the eval
 * command wires persistence and regression detection around it), which
 * keeps this orchestrator unit-testable without a database.
 */
final readonly class EvaluationService
{
    public function __construct(
        private CompletionServiceInterface $completionService,
        private GradingService $gradingService,
    ) {}

    /**
     * The grader identifiers this service can run, for callers that need to
     * validate a requested grader before invoking run().
     *
     * @return list<string>
     */
    public function availableGraders(): array
    {
        return $this->gradingService->availableGraders();
    }

    /**
     * Execute the set and return the aggregated result.
     *
     * @param string           $graderId    Grader identifier (default: deterministic; llm_judge is opt-in)
     * @param ChatOptions|null $baseOptions Options applied to every call (e.g. the model/provider to evaluate);
     *                                      a prompt's own system prompt overrides the base system prompt
     */
    public function run(
        GoldenPromptSet $set,
        string $graderId = DeterministicGrader::IDENTIFIER,
        ?ChatOptions $baseOptions = null,
    ): SetEvaluationResult {
        $evaluations = [];
        $model = $baseOptions?->getModel() ?? '';

        foreach ($set->prompts as $prompt) {
            $options = $baseOptions ?? new ChatOptions();
            if ($prompt->systemPrompt !== null) {
                $options = $options->withSystemPrompt($prompt->systemPrompt);
            }

            $startedAt = microtime(true);
            $response = $this->completionService->complete($prompt->prompt, $options);
            $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

            if ($response->model !== '') {
                $model = $response->model;
            }

            $grading = $this->gradingService->grade($response->content, $prompt, $graderId);
            $evaluations[] = new PromptEvaluation($prompt->id, $grading, $latencyMs);
        }

        return new SetEvaluationResult($set->identifier, $model, $graderId, $evaluations, time());
    }
}
