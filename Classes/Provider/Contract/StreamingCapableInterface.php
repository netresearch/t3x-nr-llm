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
     * `LlmServiceManager` may forward simple `{role, content}` fixtures as
     * typed `ChatMessage` instances; richer provider-specific shapes are
     * passed through as arrays. Implementations called directly may also
     * receive legacy array fixtures. Implementations should therefore
     * normalise mixed input by converting `ChatMessage` entries via
     * `toArray()` and leaving array fixtures untouched.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator;

    public function supportsStreaming(): bool;
}
