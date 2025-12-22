<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Rendered prompt with variables substituted
 *
 * Immutable value object representing a fully rendered prompt
 * ready for execution.
 */
final class RenderedPrompt
{
    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $userPrompt,
        private readonly ?string $model = null,
        private readonly float $temperature = 0.7,
        private readonly int $maxTokens = 1000,
        private readonly float $topP = 1.0,
        private readonly array $metadata = [],
    ) {}

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getUserPrompt(): string
    {
        return $this->userPrompt;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function getTopP(): float
    {
        return $this->topP;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get combined prompt length estimate
     *
     * @return int Approximate character count
     */
    public function estimateLength(): int
    {
        return strlen($this->systemPrompt) + strlen($this->userPrompt);
    }

    /**
     * Get approximate token count (rough estimate: 4 chars = 1 token)
     *
     * @return int Estimated tokens
     */
    public function estimateTokens(): int
    {
        return (int) ceil($this->estimateLength() / 4);
    }

    /**
     * Convert to messages array format
     *
     * @return array
     */
    public function toMessages(): array
    {
        $messages = [];

        if (!empty($this->systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->systemPrompt,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $this->userPrompt,
        ];

        return $messages;
    }
}
