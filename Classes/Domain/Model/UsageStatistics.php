<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Token usage statistics from LLM requests.
 */
final readonly class UsageStatistics
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public ?float $estimatedCost = null,
    ) {}

    /**
     * Get total token count.
     */
    public function getTotal(): int
    {
        return $this->totalTokens;
    }

    /**
     * Get cost estimate if available.
     */
    public function getCost(): ?float
    {
        return $this->estimatedCost;
    }

    /**
     * Create from token counts.
     */
    public static function fromTokens(
        int $promptTokens,
        int $completionTokens,
        ?float $estimatedCost = null,
    ): self {
        return new self(
            $promptTokens,
            $completionTokens,
            $promptTokens + $completionTokens,
            $estimatedCost,
        );
    }

    /**
     * Serialize to an array shape suitable for cache storage.
     *
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: ?float}
     */
    public function toArray(): array
    {
        return [
            'promptTokens'     => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens'      => $this->totalTokens,
            'estimatedCost'    => $this->estimatedCost,
        ];
    }

    /**
     * Restore from a previously serialized array shape.
     *
     * Fields default to 0 / null when absent so cached payloads from older
     * versions (before estimatedCost was added, for example) still load.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $estimatedCost = $data['estimatedCost'] ?? null;

        return new self(
            promptTokens: \is_int($data['promptTokens'] ?? null) ? $data['promptTokens'] : 0,
            completionTokens: \is_int($data['completionTokens'] ?? null) ? $data['completionTokens'] : 0,
            totalTokens: \is_int($data['totalTokens'] ?? null) ? $data['totalTokens'] : 0,
            estimatedCost: \is_float($estimatedCost) || \is_int($estimatedCost) ? (float)$estimatedCost : null,
        );
    }
}
