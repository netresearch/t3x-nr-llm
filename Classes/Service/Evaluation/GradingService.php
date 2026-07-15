<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\GraderInterface;
use Netresearch\NrLlm\Service\Evaluation\Grader\LlmJudgeGrader;

/**
 * Selects a grading strategy and grades a response against a golden prompt
 * (ADR-060).
 *
 * The deterministic grader is the default; the LLM judge is opt-in via the
 * grader identifier because it spends tokens. An unknown identifier falls
 * back to the deterministic grader — evaluation must never silently invoke
 * the token-spending judge.
 */
final readonly class GradingService
{
    public function __construct(
        private DeterministicGrader $deterministicGrader,
        private LlmJudgeGrader $llmJudgeGrader,
    ) {}

    public function grade(string $response, GoldenPrompt $prompt, string $graderId = DeterministicGrader::IDENTIFIER): GradingResult
    {
        return $this->resolveGrader($graderId)->grade($response, $prompt);
    }

    /**
     * @return list<string>
     */
    public function availableGraders(): array
    {
        return [DeterministicGrader::IDENTIFIER, LlmJudgeGrader::IDENTIFIER];
    }

    private function resolveGrader(string $graderId): GraderInterface
    {
        return match ($graderId) {
            LlmJudgeGrader::IDENTIFIER => $this->llmJudgeGrader,
            default => $this->deterministicGrader,
        };
    }
}
