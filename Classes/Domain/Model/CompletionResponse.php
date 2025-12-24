<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Response object for text completion requests
 */
final class CompletionResponse
{
    /**
     * @param array<int, array<string, mixed>>|null $toolCalls
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly UsageStatistics $usage,
        public readonly string $finishReason = 'stop',
        public readonly string $provider = '',
        public readonly ?array $toolCalls = null,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * Check if the completion was truncated due to length
     */
    public function wasTruncated(): bool
    {
        return $this->finishReason === 'length';
    }

    /**
     * Check if content was filtered
     */
    public function wasFiltered(): bool
    {
        return $this->finishReason === 'content_filter';
    }

    /**
     * Check if completion finished normally
     */
    public function isComplete(): bool
    {
        return $this->finishReason === 'stop';
    }

    /**
     * Check if the response contains tool calls
     */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== null && $this->toolCalls !== [];
    }

    /**
     * Get the text content (alias for content property)
     */
    public function getText(): string
    {
        return $this->content;
    }
}
