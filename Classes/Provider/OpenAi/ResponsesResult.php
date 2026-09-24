<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;

/**
 * One parsed `/v1/responses` reply (ADR-203).
 *
 * It exists so that {@see ResponsesResultParser} can hand back six values
 * without an array whose keys nothing checks, and so the provider assembles
 * the `CompletionResponse` in one readable place.
 */
final readonly class ResponsesResult
{
    /**
     * @param list<ToolCall>|null        $toolCalls     null when the model
     *                                                  called nothing; never an
     *                                                  empty list
     * @param list<array<string, mixed>> $providerItems the reply's own `output`
     *                                                  array, kept for replay
     * @param ReasoningEffort|null       $appliedEffort what the provider says it
     *                                                  applied; null when it
     *                                                  reported nothing
     */
    public function __construct(
        public string $content,
        public string $model,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
        public ?array $toolCalls,
        public array $providerItems,
        public ?ReasoningEffort $appliedEffort,
    ) {}
}
