<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation\Grader;

use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Throwable;

/**
 * Grades a response by asking an LLM judge to score it 0.0-1.0 with a
 * justification (ADR-060).
 *
 * Opt-in: unlike the deterministic grader it spends tokens. The judge runs
 * through the existing CompletionService — so it uses the default chat
 * configuration the admin has set up; selecting a dedicated judge model is a
 * documented follow-up. The judge is asked for strict JSON; any deviation,
 * transport error, or out-of-range score is handled defensively so a single
 * bad judge response fails that one grading rather than aborting the run.
 */
final readonly class LlmJudgeGrader implements GraderInterface
{
    public const IDENTIFIER = 'llm_judge';

    public function __construct(
        private CompletionServiceInterface $completionService,
        private float $passThreshold = 0.6,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function grade(string $response, GoldenPrompt $prompt): GradingResult
    {
        $options = (new ChatOptions())
            ->withResponseFormat('json')
            ->withTemperature(0.0)
            ->withSystemPrompt(
                'You are a strict evaluation judge. You score how well an AI response fulfils a task '
                . 'on a scale from 0.0 (completely fails) to 1.0 (perfect). '
                . 'Respond ONLY with a JSON object: {"score": <number 0..1>, "reason": "<short justification>"}.',
            );

        try {
            $judgeResponse = $this->completionService->complete($this->buildJudgePrompt($response, $prompt), $options);
        } catch (Throwable $e) {
            return new GradingResult(false, 0.0, self::IDENTIFIER, 'Judge call failed: ' . $e->getMessage());
        }

        return $this->parseVerdict($judgeResponse->content);
    }

    private function buildJudgePrompt(string $response, GoldenPrompt $prompt): string
    {
        $parts = [
            'TASK PROMPT:',
            $prompt->prompt,
        ];
        if ($prompt->reference !== null && $prompt->reference !== '') {
            $parts[] = '';
            $parts[] = 'REFERENCE ANSWER (an ideal response):';
            $parts[] = $prompt->reference;
        }
        $parts[] = '';
        $parts[] = 'RESPONSE TO GRADE:';
        $parts[] = $response;

        return implode("\n", $parts);
    }

    private function parseVerdict(string $content): GradingResult
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['score']) || !is_numeric($decoded['score'])) {
            return new GradingResult(
                false,
                0.0,
                self::IDENTIFIER,
                'Judge response was not parseable as {"score", "reason"} JSON.',
            );
        }

        $score = max(0.0, min(1.0, (float)$decoded['score']));
        $reason = isset($decoded['reason']) && is_string($decoded['reason']) ? $decoded['reason'] : '';

        return new GradingResult($score >= $this->passThreshold, $score, self::IDENTIFIER, $reason);
    }
}
