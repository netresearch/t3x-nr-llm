<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Generator;

interface StreamingCapableInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator;

    public function supportsStreaming(): bool;
}
