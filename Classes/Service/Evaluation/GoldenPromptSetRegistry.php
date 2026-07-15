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
 * Collects every DI-tagged GoldenPromptSetProviderInterface and exposes the
 * declared golden prompt sets (ADR-060).
 *
 * Providers are injected through the `nr_llm.golden_prompt_set` tagged
 * iterator (mirroring ConfigurationPresetRegistry / ToolRegistry) and their
 * sets indexed by identifier; a duplicate identifier across providers is a
 * developer error and fails fast with a LogicException at construction time.
 */
final class GoldenPromptSetRegistry
{
    /** @var array<string, GoldenPromptSet> */
    private array $byIdentifier = [];

    /**
     * @param iterable<GoldenPromptSetProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(GoldenPromptSetProviderInterface::TAG_NAME)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            foreach ($provider->getGoldenPromptSets() as $set) {
                if (isset($this->byIdentifier[$set->identifier])) {
                    throw new LogicException(
                        sprintf('Duplicate golden prompt set identifier "%s".', $set->identifier),
                        1794000030,
                    );
                }
                $this->byIdentifier[$set->identifier] = $set;
            }
        }
    }

    /**
     * @return list<GoldenPromptSet>
     */
    public function all(): array
    {
        return array_values($this->byIdentifier);
    }

    public function findByIdentifier(string $identifier): ?GoldenPromptSet
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
