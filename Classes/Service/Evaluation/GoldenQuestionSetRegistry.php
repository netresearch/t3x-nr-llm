<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every DI-tagged GoldenQuestionSetProviderInterface and exposes
 * the declared golden question sets (ADR-072).
 *
 * Providers are injected through the `nr_llm.golden_question_set` tagged
 * iterator (mirroring GoldenPromptSetRegistry) and their sets indexed by
 * identifier; a duplicate identifier across providers is a developer error
 * and fails fast with a LogicException at construction time.
 */
final class GoldenQuestionSetRegistry
{
    /** @var array<string, GoldenQuestionSet> */
    private array $byIdentifier = [];

    /**
     * @param iterable<GoldenQuestionSetProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(GoldenQuestionSetProviderInterface::TAG_NAME)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            foreach ($provider->getGoldenQuestionSets() as $set) {
                if (isset($this->byIdentifier[$set->identifier])) {
                    throw new LogicException(
                        sprintf('Duplicate golden question set identifier "%s".', $set->identifier),
                        1794000059,
                    );
                }
                $this->byIdentifier[$set->identifier] = $set;
            }
        }
    }

    /**
     * @return list<GoldenQuestionSet>
     */
    public function all(): array
    {
        return array_values($this->byIdentifier);
    }

    public function findByIdentifier(string $identifier): ?GoldenQuestionSet
    {
        return $this->byIdentifier[$identifier] ?? null;
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        return array_keys($this->byIdentifier);
    }
}
