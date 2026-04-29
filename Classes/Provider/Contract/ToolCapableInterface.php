<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;

interface ToolCapableInterface
{
    /**
     * Implementations may assume every `$tools` entry is a `ToolSpec` —
     * `LlmServiceManager::chatWithTools()` normalises any legacy
     * array-shaped fixture via `ToolSpec::fromArray()` before forwarding.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param list<ToolSpec>                   $tools
     * @param array<string, mixed>             $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse;

    public function supportsTools(): bool;
}
