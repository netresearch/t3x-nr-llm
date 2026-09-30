<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation\Grader;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Netresearch\NrLlm\Service\Decision\Profile\BuiltinDecisionProfileProvider;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Throwable;

/**
 * Grades a response through the decision service (ADR-211, amending ADR-060).
 *
 * It asks the built-in profile `nr_llm.task_fulfilment` on the configuration
 * the operator named for decisions, so the same golden set can be graded by a
 * decision model and by a chat model and the two compared. Opt-in:
 * unlike the deterministic grader it spends tokens.
 *
 * The score is the fulfilment level scaled to 0..1. A failed decision — any
 * exception, a budget denial included — is a failed grading with score 0 and
 * the reason, because in an offline run one bad call must not abort the set.
 * That folding belongs here and nowhere else: the decision service itself
 * never turns a failure into an answer.
 */
final readonly class DecisionGrader implements GraderInterface
{
    public const IDENTIFIER = 'decision';

    /** The grader a failed decision reports (ADR-211). */
    public const FAILED_SERIES = 'decision:failed';

    public function __construct(
        private DecisionServiceInterface $decisionService,
        private float $passThreshold = 0.6,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * The task as the model received it: its system instructions belong to
     * it, so a response that ignored one ("answer in French") does not
     * fulfil the task.
     */
    private function task(GoldenPrompt $prompt): string
    {
        if ($prompt->systemPrompt === null || trim($prompt->systemPrompt) === '') {
            return $prompt->prompt;
        }

        return "Instructions the response had to follow:\n" . $prompt->systemPrompt . "\n\nTask:\n" . $prompt->prompt;
    }

    /**
     * The built-in profile, a configuration with a model that can answer it,
     * and a trust zone that may receive it — checked once, before a run pays
     * for its completions (ADR-211).
     *
     * @throws DecisionException
     */
    public function assertReady(): void
    {
        $this->decisionService->assertAvailable(BuiltinDecisionProfileProvider::TASK_FULFILMENT);
    }

    public function grade(string $response, GoldenPrompt $prompt): GradingResult
    {
        $evidence = $prompt->reference !== null && $prompt->reference !== ''
            ? ['Reference answer: ' . $prompt->reference]
            : [];

        try {
            $result = $this->decisionService->evaluate(new DecisionRequest(
                profile: BuiltinDecisionProfileProvider::TASK_FULFILMENT,
                subject: new DecisionSubject(task: $this->task($prompt), candidate: $response, evidence: $evidence),
                callerSourceExtension: 'nr_llm',
                callerSourceOperation: 'evaluation',
            ));
            $level = $result->answer(BuiltinDecisionProfileProvider::TASK_FULFILMENT_QUESTION)->value ?? 0.0;
        } catch (Throwable $e) {
            // No model answered, so there is no yardstick to name: a series
            // of its own, never mistaken for one a model produced.
            return new GradingResult(false, 0.0, self::FAILED_SERIES, 'Decision failed: ' . $e->getMessage());
        }

        $maxLevel = count(BuiltinDecisionProfileProvider::TASK_FULFILMENT_LEVELS) - 1;
        $score    = min(1.0, $level / $maxLevel);

        return new GradingResult(
            $score >= $this->passThreshold,
            $score,
            // The yardstick, not just the strategy: a run on TypeSafe and one
            // on an LLM configuration, or on two model versions, are
            // different series and must not be compared as one.
            sprintf('%s:%s:%s:v%d', self::IDENTIFIER, $result->provider, $result->model, $result->profileVersion),
            sprintf('Fulfilment level %.2f of %d.', $level, $maxLevel),
        );
    }
}
