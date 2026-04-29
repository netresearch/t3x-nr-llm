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
     * `LlmServiceManager::chatWithTools()` normalises every `$messages`
     * entry to a `ChatMessage` and every `$tools` entry to a `ToolSpec`
     * before forwarding. Implementations called directly may also receive
     * legacy array fixtures and are responsible for normalising mixed
     * input via `ChatMessage::fromArray()` / `ToolSpec::fromArray()`.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse;

    public function supportsTools(): bool;
}
