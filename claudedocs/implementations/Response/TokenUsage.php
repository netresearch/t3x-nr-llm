<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Token usage information.
 *
 * @api This class is part of the public API
 */
class TokenUsage
{
    public function __construct(
        private int $promptTokens,
        private int $completionTokens,
        private int $totalTokens,
    ) {}

    /**
     * Get prompt tokens.
     */
    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    /**
     * Get completion tokens.
     */
    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    /**
     * Get total tokens.
     */
    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
