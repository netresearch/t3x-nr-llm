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
        private ?bool $promptTokensReported = null,
        private ?bool $completionTokensReported = null,
    ) {}

    /**
     * Whether the provider reported an input token count, including an explicit
     * zero. Without an explicit flag an object counts as reported only when one
     * of its counts is non-zero: a legacy all-zero object stays unmeasured.
     */
    public function isPromptTokensReported(): bool
    {
        return $this->promptTokens >= 0 && ($this->promptTokensReported ?? $this->hasNonZeroCount());
    }

    /**
     * Whether the provider reported an output token count, with the same
     * legacy rule as {@see isPromptTokensReported()}.
     */
    public function isCompletionTokensReported(): bool
    {
        return $this->completionTokens >= 0 && ($this->completionTokensReported ?? $this->hasNonZeroCount());
    }

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
     * The usage of two calls together. A count is reported only when both
     * operands report it, and the cost is known only when both are.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
            $this->totalTokens + $other->totalTokens,
            $this->estimatedCost !== null && $other->estimatedCost !== null ? $this->estimatedCost + $other->estimatedCost : null,
            $this->isPromptTokensReported() && $other->isPromptTokensReported(),
            $this->isCompletionTokensReported() && $other->isCompletionTokensReported(),
        );
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: ?float, promptTokensReported: bool, completionTokensReported: bool}
     */
    public function toArray(): array
    {
        return [
            'promptTokens'             => $this->promptTokens,
            'completionTokens'         => $this->completionTokens,
            'totalTokens'              => $this->totalTokens,
            'estimatedCost'            => $this->estimatedCost,
            'promptTokensReported'     => $this->isPromptTokensReported(),
            'completionTokensReported' => $this->isCompletionTokensReported(),
        ];
    }

    private function hasNonZeroCount(): bool
    {
        return $this->promptTokens !== 0 || $this->completionTokens !== 0 || $this->totalTokens !== 0;
    }

    /**
     * Restore from a previously serialized array shape.
     *
     * Fields default to 0 / null when absent so cached payloads from older
     * versions (before estimatedCost was added, for example) still load. A
     * valid count without a presence flag counts as reported, which is how a
     * caller states an explicit zero measurement (ADR-272).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $estimatedCost   = $data['estimatedCost'] ?? null;
        $promptValid     = is_int($data['promptTokens'] ?? null) && $data['promptTokens'] >= 0;
        $completionValid = is_int($data['completionTokens'] ?? null) && $data['completionTokens'] >= 0;

        return new self(
            promptTokens: is_int($data['promptTokens'] ?? null) && $data['promptTokens'] >= 0 ? max(0, $data['promptTokens']) : 0,
            completionTokens: is_int($data['completionTokens'] ?? null) && $data['completionTokens'] >= 0 ? max(0, $data['completionTokens']) : 0,
            totalTokens: is_int($data['totalTokens'] ?? null) ? max(0, $data['totalTokens']) : 0,
            estimatedCost: is_float($estimatedCost) || is_int($estimatedCost) ? (float) $estimatedCost : null,
            promptTokensReported: self::presence($promptValid, $data['promptTokensReported'] ?? null),
            completionTokensReported: self::presence($completionValid, $data['completionTokensReported'] ?? null),
        );
    }

    /**
     * An invalid count is unknown; a valid one is reported unless its stored
     * flag says otherwise.
     */
    private static function presence(bool $valid, mixed $flag): bool
    {
        return $valid && $flag !== false;
    }
}
