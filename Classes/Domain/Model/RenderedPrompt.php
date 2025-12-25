<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Rendered prompt with variables substituted.
 *
 * Immutable value object representing a fully rendered prompt
 * ready for execution.
 */
final readonly class RenderedPrompt
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $systemPrompt,
        private string $userPrompt,
        private ?string $model = null,
        private float $temperature = 0.7,
        private int $maxTokens = 1000,
        private float $topP = 1.0,
        private array $metadata = [],
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

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get combined prompt length estimate.
     *
     * @return int Approximate character count
     */
    public function estimateLength(): int
    {
        return strlen($this->systemPrompt) + strlen($this->userPrompt);
    }

    /**
     * Get approximate token count (rough estimate: 4 chars = 1 token).
     *
     * @return int Estimated tokens
     */
    public function estimateTokens(): int
    {
        return (int)ceil($this->estimateLength() / 4);
    }

    /**
     * Convert to messages array format.
     *
     * @return array<int, array{role: string, content: string}>
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
