<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A named collection of golden prompts a consuming extension declares for
 * quality evaluation (ADR-060).
 *
 * The identifier is namespaced (e.g. `nr_ai_search.chat`) so sets from
 * different extensions cannot collide, mirroring the ConfigurationPreset
 * identifier convention (ADR-056). The optional `criteria` document the
 * model requirements the set is meant to exercise; they are metadata for
 * consumers and future criteria-based routing — the evaluation itself runs
 * against whatever model the caller selects.
 */
final readonly class GoldenPromptSet
{
    /**
     * Lowercase dot-namespaced identifier: segments of `[a-z0-9_]`
     * separated by single dots, e.g. `nr_ai_search.chat`.
     */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/';

    /**
     * @param list<GoldenPrompt> $prompts
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public array $prompts,
        public ?ModelSelectionCriteria $criteria = null,
    ) {
        if ($identifier === '' || preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid golden set identifier "%s": expected lowercase segments of [a-z0-9_] separated by dots, e.g. "nr_ai_search.chat".',
                    $identifier,
                ),
                1794000020,
            );
        }
        if ($name === '') {
            throw new InvalidArgumentException(
                sprintf('Golden set "%s" must declare a non-empty name.', $identifier),
                1794000021,
            );
        }
        if ($prompts === []) {
            throw new InvalidArgumentException(
                sprintf('Golden set "%s" must declare at least one prompt.', $identifier),
                1794000022,
            );
        }
        $seen = [];
        foreach ($prompts as $prompt) {
            if (isset($seen[$prompt->id])) {
                throw new InvalidArgumentException(
                    sprintf('Golden set "%s" declares duplicate prompt id "%s".', $identifier, $prompt->id),
                    1794000023,
                );
            }
            $seen[$prompt->id] = true;
        }
    }
}
