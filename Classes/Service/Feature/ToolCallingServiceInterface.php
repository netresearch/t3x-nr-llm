<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Option\ToolOptions;

/**
 * Public surface of the high-level tool-calling chat service.
 *
 * Consumers (backend agents, RAG query flows, tests, downstream
 * extensions) should depend on this interface rather than
 * `LlmServiceManagerInterface`: it exposes exactly the tool-calling
 * capability, so a consumer's test double covers two methods instead
 * of the whole manager surface and does not break when the manager
 * interface grows unrelated methods (ADR-051).
 */
interface ToolCallingServiceInterface
{
    /**
     * Chat completion with tool calling.
     *
     * Legacy array-shaped message and tool fixtures are accepted for
     * back-compat and normalised via `ChatMessage::fromArray()` /
     * `ToolSpec::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     */
    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse;

    /**
     * Chat completion with tool calling against a specific LLM configuration.
     *
     * Unlike {@see self::chatWithTools()} — which resolves a provider from
     * ExtensionConfiguration against a model-less transient configuration —
     * this entry point resolves the adapter from the configuration's model
     * (vault key + model + pricing) so the middleware pipeline records real
     * cost and budget.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     */
    public function chatWithToolsForConfiguration(array $messages, array $tools, LlmConfiguration $configuration, ?ToolOptions $options = null): CompletionResponse;
}
