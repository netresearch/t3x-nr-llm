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
            AssertionType::JSON_SCHEMA => $this->matchesJsonSchema($response, $assertion->value),
        };
    }

    /**
     * Lightweight structural JSON match: valid JSON whose shape satisfies a
     * subset schema (top-level `type`, object `required` keys, and recursive
     * `properties` types). Extra keys are allowed; this is deliberately not a
     * full JSON Schema draft validator.
     */
    private function matchesJsonSchema(string $response, string $schemaJson): bool
    {
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        $schema = json_decode($schemaJson, true);
        if (!is_array($schema)) {
            return false;
        }

        return $this->validateAgainstSchema($data, $schema);
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function validateAgainstSchema(mixed $data, array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if (is_string($type) && !$this->matchesType($data, $type)) {
            return false;
        }

        if (isset($schema['required']) && is_array($schema['required'])) {
            // An empty JSON object decodes to []; treat it as an object here
            // (consistent with matchesType()) so an empty object is not
            // mistaken for a list and the required-key checks still run.
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                return false;
            }
            foreach ($schema['required'] as $key) {
                if (!is_string($key) || !array_key_exists($key, $data)) {
                    return false;
                }
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties']) && is_array($data)) {
            foreach ($schema['properties'] as $key => $propSchema) {
                if (is_array($propSchema) && array_key_exists($key, $data)
                    && !$this->validateAgainstSchema($data[$key], $propSchema)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchesType(mixed $data, string $type): bool
    {
        return match ($type) {
            // An empty JSON object and array both decode to []; ambiguity is
            // accepted for this lightweight matcher.
            'object' => is_array($data) && (!array_is_list($data) || $data === []),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'number' => is_int($data) || is_float($data),
            'integer' => is_int($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            default => true,
        };
    }

    private function summarise(string $value): string
    {
        $value = trim($value);
        return mb_strlen($value) > 40 ? mb_substr($value, 0, 37) . '...' : $value;
    }
}
