<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Service\Task\TaskExecutionResult;

/**
 * Response DTO for `TaskController::executeAction()` success replies.
 *
 * Adapter between the service-layer `TaskExecutionResult` and the
 * wire shape the frontend expects. The `usage` field is flattened
 * to the prompt/completion/total counts the existing
 * `Backend/TaskExecute.js` consumes — wrapping the
 * `UsageStatistics` value object lets the public API stay stable
 * while internal types tighten over time.
 *
 * @internal
 */
final readonly class TaskExecutionResponse implements JsonSerializable
{
    public function __construct(
        public string $content,
        public string $model,
        public string $outputFormat,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public bool $success = true,
    ) {}

    public static function fromResult(TaskExecutionResult $result): self
    {
        return new self(
            content: $result->content,
            model: $result->model,
            outputFormat: $result->outputFormat,
            promptTokens: $result->usage->promptTokens,
            completionTokens: $result->usage->completionTokens,
            totalTokens: $result->usage->totalTokens,
        );
    }

    /**
     * @return array{
     *   success: bool,
     *   content: string,
     *   model: string,
     *   outputFormat: string,
     *   usage: array{promptTokens: int, completionTokens: int, totalTokens: int}
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'success'      => $this->success,
            'content'      => $this->content,
            'model'        => $this->model,
            'outputFormat' => $this->outputFormat,
            'usage'        => [
                'promptTokens'     => $this->promptTokens,
                'completionTokens' => $this->completionTokens,
                'totalTokens'      => $this->totalTokens,
            ],
        ];
    }
}
