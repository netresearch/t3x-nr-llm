<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A single graded prompt inside a golden set (ADR-060).
 *
 * The `id` is unique within its set and identifies the prompt in run
 * results and regression comparisons. `assertions` drive the
 * deterministic grader; `reference` is an optional ideal answer the
 * LLM-as-a-judge grader uses as its grading reference. A prompt needs at
 * least one assertion OR a reference, otherwise there is nothing to grade
 * against.
 */
final readonly class GoldenPrompt
{
    /**
     * @param list<Assertion> $assertions Deterministic expectations on the response
     */
    public function __construct(
        public string $id,
        public string $prompt,
        public array $assertions = [],
        public ?string $systemPrompt = null,
        public ?string $reference = null,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Golden prompt id must not be empty.', 1794000010);
        }
        if ($prompt === '') {
            throw new InvalidArgumentException(
                sprintf('Golden prompt "%s" must declare a non-empty prompt.', $id),
                1794000011,
            );
        }
        if ($assertions === [] && ($reference === null || $reference === '')) {
            throw new InvalidArgumentException(
                sprintf('Golden prompt "%s" must declare at least one assertion or a reference answer.', $id),
                1794000012,
            );
        }
    }
}
