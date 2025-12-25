<?php

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
}
