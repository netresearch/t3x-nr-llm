<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\DecisionResponse;

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
     * Create from a decision probe (ADR-211): a decision model answers no
     * text, so the caller phrases what it answered.
     */
    public static function fromDecisionResponse(DecisionResponse $response, string $content): self
    {
        return new self(
            success: true,
            content: $content,
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
