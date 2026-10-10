<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
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
     * Execute the set and retain grading separately from verified serving identity.
     *
     * @param string           $graderId    Grader identifier (default: deterministic; decision is opt-in)
     * @param ChatOptions|null $baseOptions Options applied to every call; a prompt overrides the system prompt
     */
    public function run(
        GoldenPromptSet $set,
        string $graderId = DeterministicGrader::IDENTIFIER,
        ?ChatOptions $baseOptions = null,
    ): SetEvaluationResult {
        $this->gradingService->assertReady($graderId);
        $evaluations = [];
        foreach ($set->prompts as $prompt) {
            $options = $baseOptions ?? new ChatOptions();
            if ($prompt->systemPrompt !== null) {
                $options = $options->withSystemPrompt($prompt->systemPrompt);
            }

            $startedAt = microtime(true);
            $response = $this->completionService->complete($prompt->prompt, $options);
            $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
            $provenance = GeneratorProvenance::fromArray(
                $response->metadata[GeneratorProvenance::METADATA_KEY] ?? null,
            );
            if ($provenance?->reportedModelId !== $response->model) {
                $provenance = null;
            }

            // Grade the system prompt used by the actual call, independent of generator attribution.
            $effectiveSystemPrompt = $options->getSystemPrompt();
            $gradedPrompt = $effectiveSystemPrompt === $prompt->systemPrompt ? $prompt : $prompt->withSystemPrompt($effectiveSystemPrompt);
            $grading = $this->gradingService->grade(
                $response->content,
                $gradedPrompt,
                $graderId,
            );
            $evaluations[] = new PromptEvaluation(
                $prompt->id,
                $grading,
                $latencyMs,
                $provenance,
                $response->provider,
            );
        }

        $generator = $this->singleGenerator($evaluations);
        return new SetEvaluationResult(
            $set->identifier,
            $generator->modelId ?? '',
            $this->series($graderId, $evaluations),
            $evaluations,
            time(),
            generatorProvenance: $generator,
        );
    }

    /**
     * The grader a run is stored and compared under: the one every grading
     * reports, when they agree. A grader that answers through a configurable
     * model names it (the decision grader reports its provider, model and
     * profile version, ADR-211), so runs graded by different yardsticks never
     * serve as each other's regression baseline. Runs that disagree — a
     * decision that failed for some prompts, a model switched mid-run —
     * fall back to the requested identifier and are never compared at all
     * ({@see SetEvaluationResult::sharesOneYardstick()}).
     *
     * @param list<PromptEvaluation> $evaluations
     */
    private function series(string $graderId, array $evaluations): string
    {
        $graders = array_values(array_unique(array_map(
            static fn(PromptEvaluation $evaluation): string => $evaluation->result->grader,
            $evaluations,
        )));

        return count($graders) === 1 ? $graders[0] : $graderId;
    }

    /**
     * @param list<PromptEvaluation> $evaluations
     */
    private function singleGenerator(array $evaluations): ?GeneratorProvenance
    {
        $first = $evaluations[0]->generatorProvenance ?? null;
        if (!$first instanceof GeneratorProvenance) {
            return null;
        }

        foreach ($evaluations as $evaluation) {
            if (!$evaluation->generatorProvenance instanceof GeneratorProvenance || !$first->sameGenerator($evaluation->generatorProvenance)) {
                return null;
            }
        }

        return $first;
    }
}
