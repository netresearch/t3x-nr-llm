<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Generator;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

interface StreamingCapableInterface
{
    /**
     * `LlmServiceManager` normalises every entry to a `ChatMessage` before
     * forwarding the call. Implementations called directly may also receive
     * legacy `['role' => ..., 'content' => ...]` array fixtures and are
     * responsible for normalising mixed input via `ChatMessage::fromArray()`.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator;

    public function supportsStreaming(): bool;
}
