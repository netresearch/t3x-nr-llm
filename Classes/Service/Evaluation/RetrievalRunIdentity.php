<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Separates a versioned benchmark from the retrieval treatment (ADR-215).
 *
 * Internal: consumers only declare RetrievalProvenance. Label hashing and
 * scoring identity are derived here, never supplied by a remote retriever.
 */
final readonly class RetrievalRunIdentity
{
    public const SCORING_VERSION = 'distinct-documents-top1-top3-no-result-v1';

    public function __construct(
        public ?RetrievalProvenance $provenance,
        public string $labelsFingerprint,
        public string $benchmarkFingerprint,
        public string $variantFingerprint,
    ) {}

    public static function forSet(GoldenQuestionSet $set, ?RetrievalProvenance $provenance): self
    {
        $labels = [];
        foreach ($set->questions as $question) {
            $targets = $question->expectedDocumentIds;
            sort($targets, SORT_STRING);
            $labels[$question->id] = [
                base64_encode($question->id),
                base64_encode($question->question),
                $question->form->value,
                $question->hardClass !== null ? base64_encode($question->hardClass) : null,
                array_map(base64_encode(...), $targets),
            ];
        }

        ksort($labels, SORT_STRING);
        $labelsFingerprint = self::digest(['labels-bytes-v1', array_values($labels)]);
        if (!$provenance instanceof RetrievalProvenance) {
            return new self(null, $labelsFingerprint, '', '');
        }

        return new self(
            $provenance,
            $labelsFingerprint,
            self::digest(
                [
                    'benchmark-v1',
                    $provenance->corpusRevision,
                    $labelsFingerprint,
                    self::SCORING_VERSION,
                ],
            ),
            self::digest(
                [
                    'variant-v1',
                    $provenance->modelRevision,
                    $provenance->chunkingIdentity,
                    $provenance->pipelineIdentity,
                ],
            ),
        );
    }

    /**
     * @param array<mixed> $values
     */
    private static function digest(array $values): string
    {
        return 'v1:' . hash(
            'sha256',
            json_encode(
                $values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
