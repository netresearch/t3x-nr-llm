<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture;

use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverInterface;
use Netresearch\NrLlm\Service\Evaluation\RetrievalProvenance;
use Netresearch\NrLlm\Service\Evaluation\RetrievalProvenanceProviderInterface;

/**
 * An EvaluatableRetriever test double that returns a canned ranking per
 * question and records what it was asked — so hit-rate scoring and the
 * retrieval eval command can be exercised without a search backend.
 */
final class StaticRetriever implements EvaluatableRetrieverInterface, RetrievalProvenanceProviderInterface
{
    /** @var list<array{question: string, limit: int}> */
    public array $receivedCalls = [];

    /**
     * @param array<string, list<string>> $rankingsByQuestion Ranked document ids keyed by question text
     * @param list<string>                $defaultRanking     Ranking for questions without a canned entry
     */
    public function __construct(
        private readonly array $rankingsByQuestion = [],
        private readonly array $defaultRanking = [],
        private readonly string $identifier = 'test.retriever',
        private readonly ?RetrievalProvenance $provenance = null,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function retrieve(string $question, int $limit): array
    {
        $this->receivedCalls[] = ['question' => $question, 'limit' => $limit];

        return $this->rankingsByQuestion[$question] ?? $this->defaultRanking;
    }

    public function getRetrievalProvenance(): RetrievalProvenance
    {
        return $this->provenance ?? new RetrievalProvenance(
            'test-corpus-v1',
            'test-embed-v1',
            'test-chunk-v1',
            'test-pipeline-v1',
        );
    }
}
