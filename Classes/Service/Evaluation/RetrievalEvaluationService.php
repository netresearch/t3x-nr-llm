<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Runs a golden question set against a retriever and scores every question
 * by document-level top-1/top-3 hit (ADR-072) — the retrieval counterpart
 * of {@see EvaluationService}.
 *
 * Scoring follows the methodology the BMDV set established: a top-k hit
 * means any of the k best-ranked DISTINCT documents is one of the
 * question's expected documents. Duplicate ids in the retriever's ranking
 * (e.g. several chunks of the same document mapped to one document id) are
 * collapsed to the first occurrence before ranks are counted, so top-3
 * always means the three best distinct documents. A question that expects
 * no result (empty `expectedDocumentIds`) is a hit — on both metrics —
 * only when the retriever returns nothing.
 *
 * Like EvaluationService, this is an explicitly invoked, out-of-request
 * operation that neither persists nor compares — the retrieval eval
 * command wires persistence and regression detection around it.
 */
final readonly class RetrievalEvaluationService
{
    /**
     * The retrieval depth the evaluation looks at — the deepest metric is
     * the top-3 hit rate, so retrievers are asked for three documents.
     */
    public const TOP_K = 3;

    /**
     * Execute the set against the retriever and return the scored result.
     */
    public function run(GoldenQuestionSet $set, EvaluatableRetrieverInterface $retriever): RetrievalSetEvaluationResult
    {
        $evaluations = [];

        foreach ($set->questions as $question) {
            $startedAt = microtime(true);
            $ranked = $retriever->retrieve($question->question, self::TOP_K);
            $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

            $documents = $this->distinctTopDocuments($ranked);

            if ($question->expectsNoResult()) {
                $top1Hit = $top3Hit = $documents === [];
            } else {
                $top1Hit = $documents !== [] && in_array($documents[0], $question->expectedDocumentIds, true);
                $top3Hit = array_intersect($documents, $question->expectedDocumentIds) !== [];
            }

            $evaluations[] = new QuestionEvaluation(
                $question->id,
                $question->form,
                $question->hardClass,
                $top1Hit,
                $top3Hit,
                $documents,
                $latencyMs,
            );
        }

        return new RetrievalSetEvaluationResult($set->identifier, $retriever->getIdentifier(), $evaluations, time());
    }

    /**
     * The first TOP_K distinct non-empty document ids in ranking order.
     * Collapsing duplicates before slicing keeps the document-level rank
     * semantics even when a retriever hands back one id per chunk.
     *
     * @param list<string> $ranked
     *
     * @return list<string>
     */
    private function distinctTopDocuments(array $ranked): array
    {
        $documents = [];
        foreach ($ranked as $documentId) {
            if ($documentId === '' || in_array($documentId, $documents, true)) {
                continue;
            }
            $documents[] = $documentId;
            if (count($documents) === self::TOP_K) {
                break;
            }
        }

        return $documents;
    }
}
