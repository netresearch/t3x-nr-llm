<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;

/**
 * Response DTO for configuration test AJAX action.
 *
 * @internal
 */
final readonly class TestConfigurationResponse implements JsonSerializable
{
    public function __construct(
        public bool $success,
        public string $content,
        public string $model,
        public UsageResponse $usage,
    ) {}

    /**
     * Create from domain CompletionResponse model.
     */
    public static function fromCompletionResponse(CompletionResponse $response): self
    {
        return new self(
            success: true,
            content: $response->content,
            model: $response->model,
            usage: UsageResponse::fromUsageStatistics($response->usage),
        );
    }

    /**
     * @return array{success: bool, content: string, model: string, usage: array{promptTokens: int, completionTokens: int, totalTokens: int}}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'content' => $this->content,
            'model' => $this->model,
            'usage' => $this->usage->jsonSerialize(),
        ];
    }
}
