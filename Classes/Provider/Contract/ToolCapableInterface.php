<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;

interface ToolCapableInterface
{
    /**
     * `LlmServiceManager::chatWithTools()` always normalises every `$tools`
     * entry to a typed `ToolSpec` before forwarding (legacy array tool
     * fixtures are accepted at the public API only, never reach this layer).
     * Messages may arrive as a mix of typed `ChatMessage` and richer
     * provider-specific arrays (tool result shapes, multimodal content) so
     * implementations should normalise messages by converting `ChatMessage`
     * entries via `toArray()` and leaving array fixtures untouched.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse;

    public function supportsTools(): bool;
}
