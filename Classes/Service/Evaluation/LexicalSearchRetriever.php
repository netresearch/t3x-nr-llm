<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\RetrievalService;

/**
 * The evaluatable adapter over nr_llm's own lexical retrieval cascade
 * (ADR-049), so `nrllm:eval:retrieval` has a built-in target and consumer
 * extensions have a concrete adapter pattern to copy (ADR-072).
 *
 * Document identity is the evidence `sourceId` — golden sets measuring
 * this retriever must label their `expectedDocumentIds` with those ids.
 * Retrieval runs public-only, matching what RAG evidence exposes to an
 * anonymous visitor. Questions are clamped to the RetrievalQuery length
 * bounds: over-long ones are truncated, under-short ones (below two
 * characters) cannot be searched and yield an empty result.
 */
final readonly class LexicalSearchRetriever implements EvaluatableRetrieverInterface
{
    public const IDENTIFIER = 'nr_llm.lexical';

    public function __construct(
        private RetrievalService $retrievalService,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function retrieve(string $question, int $limit): array
    {
        $question = trim($question);
        if (mb_strlen($question) > RetrievalQuery::MAX_QUERY_LENGTH) {
            // Truncation can expose interior whitespace at the cut — trim again.
            $question = trim(mb_substr($question, 0, RetrievalQuery::MAX_QUERY_LENGTH));
        }
        if (mb_strlen($question) < RetrievalQuery::MIN_QUERY_LENGTH) {
            return [];
        }

        $query = RetrievalQuery::create(
            $question,
            max(1, min($limit, RetrievalQuery::MAX_SOURCES)),
        );
        $evidence = $this->retrievalService->search($query, AccessContext::publicOnly());

        $documentIds = [];
        foreach ($evidence->sources as $source) {
            $documentIds[] = $source->sourceId;
        }

        return $documentIds;
    }
}
