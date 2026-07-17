<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\EmbeddingServiceInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Throwable;

/**
 * Consumer-facing test double for {@see EmbeddingServiceInterface}.
 *
 * Ships in the runtime-autoloaded `Netresearch\NrLlm\Testing\` namespace so
 * downstream extensions (search/RAG indexers, controllers) can fake the
 * embedding surface in their unit tests instead of hand-rolling a double that
 * breaks whenever the interface grows. Implementing the real interface means
 * PHPStan keeps this double in sync with the production contract.
 *
 * The five provider-backed methods (`embed*`) return the canned values in the
 * public result properties and record every call in the matching `*Calls`
 * array; set {@see $throwable} to make the next provider-backed call throw.
 * The four pure vector helpers (`cosineSimilarity`, `findMostSimilar`,
 * `pairwiseSimilarities`, `normalize`) return canned values only — a fake has
 * no reason to reimplement deterministic math; use the real `EmbeddingService`
 * when the actual arithmetic is under test.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for consumer test suites,
 * never wire it into production.
 */
final class FakeEmbeddingService implements EmbeddingServiceInterface
{
    /**
     * Canned vector returned by {@see self::embed()} and
     * {@see self::embedForConfiguration()}.
     *
     * @var array<int, float>
     */
    public array $embedResult = [0.1, 0.2, 0.3];

    /**
     * Canned vectors returned by {@see self::embedBatch()} and
     * {@see self::embedBatchForConfiguration()}.
     *
     * @var array<int, array<int, float>>
     */
    public array $embedBatchResult = [[0.1, 0.2, 0.3]];

    /**
     * Canned response for {@see self::embedFull()}; a default wrapping
     * {@see self::$embedResult} is built when left null.
     */
    public ?EmbeddingResponse $embedFullResult = null;

    public float $cosineSimilarityResult = 1.0;

    /** @var array<int, array{index: int, similarity: float}> */
    public array $findMostSimilarResult = [];

    /** @var array<int, array<int, float>> */
    public array $pairwiseSimilaritiesResult = [];

    /**
     * Canned result for {@see self::normalize()}; the input vector is returned
     * unchanged when left null.
     *
     * @var array<int, float>|null
     */
    public ?array $normalizeResult = null;

    /**
     * When set, the next provider-backed call throws this instead of returning
     * a canned value. Cleared before throwing, so subsequent calls return
     * canned values again.
     */
    public ?Throwable $throwable = null;

    /** @var list<array{text: string, options: ?EmbeddingOptions}> */
    public array $embedCalls = [];

    /** @var list<array{text: string, options: ?EmbeddingOptions}> */
    public array $embedFullCalls = [];

    /** @var list<array{texts: array<int, string>, options: ?EmbeddingOptions}> */
    public array $embedBatchCalls = [];

    /** @var list<array{text: string, configuration: LlmConfiguration, options: ?EmbeddingOptions}> */
    public array $embedForConfigurationCalls = [];

    /** @var list<array{texts: array<int, string>, configuration: LlmConfiguration, options: ?EmbeddingOptions}> */
    public array $embedBatchForConfigurationCalls = [];

    public function embed(string $text, ?EmbeddingOptions $options = null): array
    {
        $this->embedCalls[] = ['text' => $text, 'options' => $options];
        $this->guardThrow();

        return $this->embedResult;
    }

    public function embedFull(string $text, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        $this->embedFullCalls[] = ['text' => $text, 'options' => $options];
        $this->guardThrow();

        return $this->embedFullResult ?? new EmbeddingResponse(
            embeddings: [$this->embedResult],
            model: 'fake-embedding-model',
            usage: new UsageStatistics(0, 0, 0),
        );
    }

    public function embedBatch(array $texts, ?EmbeddingOptions $options = null): array
    {
        $this->embedBatchCalls[] = ['texts' => $texts, 'options' => $options];
        $this->guardThrow();

        return $this->embedBatchResult;
    }

    public function embedForConfiguration(string $text, LlmConfiguration $configuration, ?EmbeddingOptions $options = null): array
    {
        $this->embedForConfigurationCalls[] = ['text' => $text, 'configuration' => $configuration, 'options' => $options];
        $this->guardThrow();

        return $this->embedResult;
    }

    public function embedBatchForConfiguration(array $texts, LlmConfiguration $configuration, ?EmbeddingOptions $options = null): array
    {
        $this->embedBatchForConfigurationCalls[] = ['texts' => $texts, 'configuration' => $configuration, 'options' => $options];
        $this->guardThrow();

        return $this->embedBatchResult;
    }

    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        return $this->cosineSimilarityResult;
    }

    public function findMostSimilar(array $queryVector, array $candidateVectors, int $topK = 5): array
    {
        return $this->findMostSimilarResult;
    }

    public function pairwiseSimilarities(array $vectors): array
    {
        return $this->pairwiseSimilaritiesResult;
    }

    public function normalize(array $vector): array
    {
        return $this->normalizeResult ?? $vector;
    }

    private function guardThrow(): void
    {
        if ($this->throwable instanceof Throwable) {
            $throwable = $this->throwable;
            $this->throwable = null;

            throw $throwable;
        }
    }
}
