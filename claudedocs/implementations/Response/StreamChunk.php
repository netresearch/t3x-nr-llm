<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Single streaming chunk.
 *
 * @api This class is part of the public API
 */
class StreamChunk
{
    public function __construct(
        private string $content,
        private bool $isComplete = false,
        private ?string $finishReason = null,
    ) {}

    /**
     * Get chunk content.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Check if stream is complete.
     */
    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    /**
     * Get finish reason.
     */
    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }
}
