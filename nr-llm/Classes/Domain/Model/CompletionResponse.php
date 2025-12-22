<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Response object for text completion requests
 */
final class CompletionResponse
{
    public function __construct(
        public readonly string $text,
        public readonly UsageStatistics $usage,
        public readonly string $finishReason,
        public readonly ?string $model = null,
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
}
