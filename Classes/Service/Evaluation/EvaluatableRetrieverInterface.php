<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A retriever that can be measured against a golden question set
 * (ADR-072).
 *
 * The contract is deliberately minimal — a question string in, ranked
 * document ids out — so ANY retrieval pipeline can be evaluated: nr_llm's
 * own lexical cascade (see {@see LexicalSearchRetriever}), a consumer
 * extension's vector retrieval, a reranked pipeline, or an A/B variant of
 * one. The adapter owns the mapping from its native results to document
 * ids; those ids must use the same identity scheme as the golden set's
 * `expectedDocumentIds` (e.g. chunk-id prefixes for chunked vector
 * stores), otherwise no hit can ever match.
 *
 * Implementations are discovered via the `nr_llm.evaluatable_retriever`
 * DI tag (auto-applied by AutoconfigureTag) and collected by
 * EvaluatableRetrieverRegistry, so `nrllm:eval:retrieval` can address them
 * by identifier.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface EvaluatableRetrieverInterface
{
    public const TAG_NAME = 'nr_llm.evaluatable_retriever';

    /**
     * Namespaced identifier the CLI addresses this retriever by,
     * e.g. `nr_llm.lexical` or `nr_ai_search.vector`.
     */
    public function getIdentifier(): string;

    /**
     * Retrieve the ranked document ids for a question, best match first.
     *
     * @param int $limit Maximum number of documents the evaluation will look at
     *
     * @return list<string> Ranked document ids, best first
     */
    public function retrieve(string $question, int $limit): array;
}
