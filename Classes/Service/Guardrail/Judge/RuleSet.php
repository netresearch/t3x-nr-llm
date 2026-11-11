<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;

use function count;
use function preg_split;
use function trim;

/**
 * The rules a judge checks against: every non-empty line of the selected
 * snippets, numbered from 1 in snippet order (ADR-288).
 *
 * A line is the unit because a hint has to quote what an administrator wrote,
 * and a line is what a snippet's author can point at.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RuleSet
{
    /** A rule set is prose for one prompt, not a manual. */
    public const MAX_RULES = 100;

    /**
     * @param list<Rule>          $rules
     * @param list<ToolDataClass> $dataClasses the declared classes of the snippets the rules come from
     */
    public function __construct(
        public array $rules,
        public array $dataClasses = [],
    ) {}

    /**
     * @param list<array{name: string, text: string, dataClass: ?ToolDataClass}> $snippets
     */
    public static function fromSnippets(array $snippets): self
    {
        $rules   = [];
        $classes = [];
        foreach ($snippets as $snippet) {
            if ($snippet['dataClass'] instanceof ToolDataClass) {
                $classes[] = $snippet['dataClass'];
            }

            foreach (preg_split('/\R/u', $snippet['text']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || count($rules) >= self::MAX_RULES) {
                    continue;
                }

                $rules[] = new Rule(count($rules) + 1, $line, $snippet['name']);
            }
        }

        return new self($rules, $classes);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    public function rule(int $number): ?Rule
    {
        return $this->rules[$number - 1] ?? null;
    }
}
