<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use function is_float;
use function is_int;

/**
 * Token usage statistics from LLM requests.
 *
 * @api
 */
final readonly class UsageStatistics
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public ?float $estimatedCost = null,
        public ?bool $promptTokensReported = null,
        public ?bool $completionTokensReported = null,
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
            true,
            true,
        );
    }

    public function plus(self $other): self
    {
        $left  = $this->toArray();
        $right = $other->toArray();

        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
            $this->totalTokens + $other->totalTokens,
            $this->estimatedCost !== null && $other->estimatedCost !== null ? $this->estimatedCost + $other->estimatedCost : null,
            $left['promptTokensReported'] && $right['promptTokensReported'],
            $left['completionTokensReported'] && $right['completionTokensReported'],
        );
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: ?float, promptTokensReported: bool, completionTokensReported: bool}
     */
    public function toArray(): array
    {
        $legacy = $this->promptTokens !== 0 || $this->completionTokens !== 0 || $this->totalTokens !== 0;

        return [
            'promptTokens'             => $this->promptTokens,
            'completionTokens'         => $this->completionTokens,
            'totalTokens'              => $this->totalTokens,
            'estimatedCost'            => $this->estimatedCost,
            'promptTokensReported'     => $this->promptTokens >= 0 && ($this->promptTokensReported ?? $legacy),
            'completionTokensReported' => $this->completionTokens >= 0 && ($this->completionTokensReported ?? $legacy),
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
            promptTokens: is_int($data['promptTokens'] ?? null) && $data['promptTokens'] >= 0 ? max(0, $data['promptTokens']) : 0,
            completionTokens: is_int($data['completionTokens'] ?? null) && $data['completionTokens'] >= 0 ? max(0, $data['completionTokens']) : 0,
            totalTokens: is_int($data['totalTokens'] ?? null) ? max(0, $data['totalTokens']) : 0,
            estimatedCost: is_float($estimatedCost) || is_int($estimatedCost) ? (float) $estimatedCost : null,
            promptTokensReported: is_int($data['promptTokens'] ?? null) && $data['promptTokens'] >= 0 && ($data['promptTokensReported'] ?? true) !== false,
            completionTokensReported: is_int($data['completionTokens'] ?? null) && $data['completionTokens'] >= 0 && ($data['completionTokensReported'] ?? true) !== false,
        );
    }
}
