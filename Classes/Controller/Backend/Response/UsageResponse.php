<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;

/**
 * Response DTO for usage statistics in AJAX responses.
 *
 * @internal
 */
final readonly class UsageResponse implements JsonSerializable
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
    ) {}

    /**
     * Create from domain UsageStatistics model.
     */
    public static function fromUsageStatistics(UsageStatistics $usage): self
    {
        return new self(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            totalTokens: $usage->totalTokens,
        );
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens' => $this->totalTokens,
        ];
    }
}
