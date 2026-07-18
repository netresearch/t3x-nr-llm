<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation\Grader;

use Netresearch\NrLlm\Domain\Enum\AssertionType;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GradingResult;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;

/**
 * Grades a response by evaluating a golden prompt's deterministic
 * assertions (exact / contains / regex / json_schema) — no LLM call, no
 * tokens (ADR-060).
 *
 * The score is the fraction of assertions satisfied; the response passes
 * only when every assertion holds. json_schema uses a lightweight
 * structural matcher (required keys + per-key type), not a full JSON Schema
 * draft validator, to avoid a runtime dependency.
 */
final readonly class DeterministicGrader implements GraderInterface
{
    public const IDENTIFIER = 'deterministic';

    public function __construct(
        private JsonSchemaValidator $schemaValidator = new JsonSchemaValidator(),
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function grade(string $response, GoldenPrompt $prompt): GradingResult
    {
        if ($prompt->assertions === []) {
            return new GradingResult(
                false,
                0.0,
                self::IDENTIFIER,
                'No deterministic assertions declared; use the llm_judge grader for reference-only prompts.',
            );
        }

        $total = count($prompt->assertions);
        $passedCount = 0;
        $failures = [];
        foreach ($prompt->assertions as $assertion) {
            if ($this->matches($assertion, $response)) {
                ++$passedCount;
            } else {
                $failures[] = sprintf('%s(%s)', $assertion->type->value, $this->summarise($assertion->value));
            }
        }

        $passed = $passedCount === $total;
        $reason = $passed
            ? sprintf('All %d assertion(s) satisfied.', $total)
            : sprintf('Failed %d/%d assertion(s): %s', $total - $passedCount, $total, implode(', ', $failures));

        return new GradingResult($passed, $passedCount / $total, self::IDENTIFIER, $reason);
    }

    private function matches(Assertion $assertion, string $response): bool
    {
        return match ($assertion->type) {
            AssertionType::EXACT => trim($response) === trim($assertion->value),
            AssertionType::CONTAINS => str_contains($response, $assertion->value),
            AssertionType::REGEX => preg_match($assertion->value, $response) === 1,
            AssertionType::JSON_SCHEMA => $this->schemaValidator->validateJson($response, $assertion->value),
        };
    }

    private function summarise(string $value): string
    {
        $value = trim($value);
        return mb_strlen($value) > 40 ? mb_substr($value, 0, 37) . '...' : $value;
    }
}
