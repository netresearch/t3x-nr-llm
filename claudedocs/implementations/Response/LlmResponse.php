<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Normalized LLM response
 *
 * @api This class is part of the public API
 */
class LlmResponse
{
    public function __construct(
        private string $content,
        private ?TokenUsage $usage = null,
        private ?array $metadata = null,
        private ?string $finishReason = null
    ) {}

    /**
     * Get response content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get token usage information
     */
    public function getUsage(): ?TokenUsage
    {
        return $this->usage;
    }

    /**
     * Get metadata
     *
     * @param string|null $key Specific metadata key or null for all
     */
    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }

    /**
     * Get finish reason
     */
    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * Check if response is empty
     */
    public function isEmpty(): bool
    {
        return empty(trim($this->content));
    }

    /**
     * Check if usage data is available
     */
    public function hasUsageData(): bool
    {
        return $this->usage !== null;
    }

    /**
     * Get prompt tokens (convenience method)
     */
    public function getPromptTokens(): int
    {
        return $this->usage?->getPromptTokens() ?? 0;
    }

    /**
     * Get completion tokens (convenience method)
     */
    public function getCompletionTokens(): int
    {
        return $this->usage?->getCompletionTokens() ?? 0;
    }

    /**
     * Get total tokens (convenience method)
     */
    public function getTotalTokens(): int
    {
        return $this->usage?->getTotalTokens() ?? 0;
    }

    /**
     * Estimate cost based on model pricing
     *
     * @return float Estimated cost in USD
     */
    public function getCostEstimate(): float
    {
        if ($this->usage === null) {
            return 0.0;
        }

        $model = $this->getMetadata('model');

        // Simple cost estimation (should use ConfigurationManager in real implementation)
        $promptCost = match ($model) {
            'gpt-4' => 0.03 / 1000,
            'gpt-4-turbo' => 0.01 / 1000,
            'gpt-3.5-turbo' => 0.0015 / 1000,
            'claude-3-opus-20240229' => 0.015 / 1000,
            'claude-3-sonnet-20240229' => 0.003 / 1000,
            'claude-3-haiku-20240307' => 0.00025 / 1000,
            default => 0.001 / 1000
        };

        $completionCost = match ($model) {
            'gpt-4' => 0.06 / 1000,
            'gpt-4-turbo' => 0.03 / 1000,
            'gpt-3.5-turbo' => 0.002 / 1000,
            'claude-3-opus-20240229' => 0.075 / 1000,
            'claude-3-sonnet-20240229' => 0.015 / 1000,
            'claude-3-haiku-20240307' => 0.00125 / 1000,
            default => 0.002 / 1000
        };

        return ($this->usage->getPromptTokens() * $promptCost)
               + ($this->usage->getCompletionTokens() * $completionCost);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'usage' => $this->usage?->toArray(),
            'metadata' => $this->metadata,
            'finish_reason' => $this->finishReason,
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
