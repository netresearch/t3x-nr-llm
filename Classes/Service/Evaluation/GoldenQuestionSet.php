<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A named collection of golden questions a consuming extension declares for
 * retrieval-quality evaluation (ADR-072), mirroring {@see GoldenPromptSet}.
 *
 * The identifier is namespaced (e.g. `nr_ai_search.bmdv`) so sets from
 * different extensions cannot collide, following the ConfigurationPreset
 * identifier convention (ADR-056). nr_llm ships no golden questions — the
 * labels only mean something against a concrete corpus, so every set lives
 * in the extension that owns the indexed content.
 */
final readonly class GoldenQuestionSet
{
    /**
     * Lowercase dot-namespaced identifier: segments of `[a-z0-9_]`
     * separated by single dots, e.g. `nr_ai_search.bmdv`.
     */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/';

    /**
     * @param list<GoldenQuestion> $questions
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public array $questions,
    ) {
        if ($identifier === '' || preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid golden question set identifier "%s": expected lowercase segments of [a-z0-9_] separated by dots, e.g. "nr_ai_search.bmdv".',
                    $identifier,
                ),
                1794000055,
            );
        }
        if ($name === '') {
            throw new InvalidArgumentException(
                sprintf('Golden question set "%s" must declare a non-empty name.', $identifier),
                1794000056,
            );
        }
        if ($questions === []) {
            throw new InvalidArgumentException(
                sprintf('Golden question set "%s" must declare at least one question.', $identifier),
                1794000057,
            );
        }
        $seen = [];
        foreach ($questions as $question) {
            if (isset($seen[$question->id])) {
                throw new InvalidArgumentException(
                    sprintf('Golden question set "%s" declares duplicate question id "%s".', $identifier, $question->id),
                    1794000058,
                );
            }
            $seen[$question->id] = true;
        }
    }
}
