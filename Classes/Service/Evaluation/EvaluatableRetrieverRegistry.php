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
 * Collects every DI-tagged EvaluatableRetrieverInterface and exposes them
 * by identifier (ADR-072), mirroring GoldenQuestionSetRegistry.
 *
 * A duplicate identifier across retrievers is a developer error and fails
 * fast with a LogicException at construction time.
 */
final class EvaluatableRetrieverRegistry
{
    /** @var array<string, EvaluatableRetrieverInterface> */
    private array $byIdentifier = [];

    /**
     * @param iterable<EvaluatableRetrieverInterface> $retrievers
     */
    public function __construct(
        #[AutowireIterator(EvaluatableRetrieverInterface::TAG_NAME)]
        iterable $retrievers,
    ) {
        foreach ($retrievers as $retriever) {
            $identifier = $retriever->getIdentifier();
            if (isset($this->byIdentifier[$identifier])) {
                throw new LogicException(
                    sprintf('Duplicate evaluatable retriever identifier "%s".', $identifier),
                    1794000060,
                );
            }
            $this->byIdentifier[$identifier] = $retriever;
        }
    }

    /**
     * @return list<EvaluatableRetrieverInterface>
     */
    public function all(): array
    {
        return array_values($this->byIdentifier);
    }

    public function findByIdentifier(string $identifier): ?EvaluatableRetrieverInterface
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
