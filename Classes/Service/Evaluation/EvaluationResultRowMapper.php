<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use InvalidArgumentException;
use JsonException;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;

/**
 * Decodes persisted evidence without owning query or retention policy. @internal.
 */
final readonly class EvaluationResultRowMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public function mapRow(array $row): EvaluationResultSummary
    {
        return new EvaluationResultSummary(
            $this->toString($row['set_identifier'] ?? ''),
            $this->toString($row['model_id'] ?? ''),
            $this->toString($row['grader'] ?? ''),
            $this->toInt($row['prompt_count'] ?? 0),
            $this->toInt($row['passed_count'] ?? 0),
            $this->toFloat($row['pass_rate'] ?? 0),
            $this->toFloat($row['mean_score'] ?? 0),
            $this->toInt($row['run_date'] ?? 0),
            $this->toInt($row['uid'] ?? 0),
            $this->readFingerprint($row['benchmark_fingerprint'] ?? null),
            $this->readFingerprint($row['variant_fingerprint'] ?? null),
            $this->readProvenance($row['retrieval_provenance'] ?? null),
            $this->readGeneratorProvenance($row),
        );
    }

    private function readFingerprint(mixed $value): string
    {
        return is_string($value) && preg_match('/^v1:[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    private function readProvenance(mixed $value): ?RetrievalProvenance
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $this->decodeProvenance($decoded) : null;
        } catch (JsonException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Validate the stored field types before constructing the bounded revision declarations.
     *
     * @param array<array-key, mixed> $decoded
     */
    private function decodeProvenance(array $decoded): ?RetrievalProvenance
    {
        foreach ([
            'corpusRevision',
            'modelRevision',
            'chunkingIdentity',
            'pipelineIdentity',
        ] as $key) {
            if (!is_string($decoded[$key] ?? null)) {
                return null;
            }
        }

        $execution = $decoded['executionRevision'] ?? null;
        if ($execution !== null && !is_string($execution)) {
            return null;
        }

        return new RetrievalProvenance(
            $decoded['corpusRevision'],
            $decoded['modelRevision'],
            $decoded['chunkingIdentity'],
            $decoded['pipelineIdentity'],
            $execution,
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function readGeneratorProvenance(array $row): ?GeneratorProvenance
    {
        $value = $row['generator_provenance'] ?? null;
        if (!is_string($value) || $value === '' || $this->toInt($row['prompt_count'] ?? 0) < 1) {
            return null;
        }

        try {
            $record = GeneratorProvenance::fromArray(
                json_decode($value, true, 8, JSON_THROW_ON_ERROR),
            );
        } catch (JsonException) {
            return null;
        }

        return $record instanceof GeneratorProvenance && $record->modelId === ($row['model_id'] ?? null) ? $record : null;
    }

    /**
     * SQL collations may produce a broader candidate set; eligibility uses exact identities.
     *
     * @param array<string,mixed> $row
     */
    public function generatorForScope(
        array $row,
        string $modelId,
        string $grader,
    ): ?GeneratorProvenance {
        $record = $this->readGeneratorProvenance($row);

        return $record?->modelId === $modelId && ($row['grader'] ?? null) === $grader ? $record : null;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
