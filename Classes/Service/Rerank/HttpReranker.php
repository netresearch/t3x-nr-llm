<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Rerank;

use JsonException;
use Netresearch\NrLlm\Service\Rerank\Exception\RerankerException;
use Psr\Http\Client\ClientExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Scores candidates via the cross-encoder sidecar (Build/reranker, ADR-075).
 *
 * POST {endpoint}/rerank  {"query": string, "documents": [{"id", "text"}]}
 *                    -> {"scores": [{"id", "score"}]}   (input order)
 *
 * Pools larger than the sidecar's batch cap (RERANKER_MAX_DOCUMENTS,
 * default 128) are split into sequential requests: the cross-encoder
 * scores each (query, text) pair independently, so batching does not
 * change the scores. A sidecar configured with a lower cap answers 413,
 * which surfaces as {@see RerankerException}.
 */
final readonly class HttpReranker implements RerankerInterface
{
    /** Default of RERANKER_MAX_DOCUMENTS in Build/reranker/app.py. */
    public const MAX_DOCUMENTS_PER_REQUEST = 128;

    public function __construct(
        private RequestFactory $requestFactory,
        private string $endpoint,
        private float $timeout,
    ) {}

    public function rerank(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $scores = [];
        foreach (array_chunk($candidates, self::MAX_DOCUMENTS_PER_REQUEST) as $batch) {
            $scores = [...$scores, ...$this->fetchScores($query, $batch)];
        }

        return $scores;
    }

    /**
     * @param list<array{id: string, text: string}> $candidates
     *
     * @return list<array{id: string, score: float}>
     */
    private function fetchScores(string $query, array $candidates): array
    {
        $payload = [
            'query' => $query,
            'documents' => array_map(
                // Cast liberally: TYPO3 consumers naturally hold integer uids,
                // and a strict string contract would silently mismatch on the
                // score mapping.
                static fn(array $candidate): array => ['id' => (string)$candidate['id'], 'text' => $candidate['text']],
                $candidates,
            ),
        ];

        try {
            $response = $this->requestFactory->request(
                rtrim($this->endpoint, '/') . '/rerank',
                'POST',
                [
                    'json' => $payload,
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => $this->timeout,
                    // Return 4xx/5xx responses instead of throwing, regardless
                    // of the instance's HTTP config: the status branch below
                    // is authoritative for non-200.
                    'http_errors' => false,
                ],
            );
        } catch (ClientExceptionInterface $e) {
            throw RerankerException::forTransportFailure($this->endpoint, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw RerankerException::forStatus($this->endpoint, $response->getStatusCode());
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw RerankerException::forInvalidJson($this->endpoint, $e);
        }

        if (!is_array($decoded) || !is_array($decoded['scores'] ?? null)) {
            throw RerankerException::forMissingScores($this->endpoint);
        }

        $scores = [];
        foreach ($decoded['scores'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id    = $entry['id'] ?? null;
            $score = $entry['score'] ?? null;
            if (!is_string($id) || (!is_int($score) && !is_float($score))) {
                continue;
            }

            $scores[] = ['id' => $id, 'score' => (float)$score];
        }

        return $scores;
    }
}
