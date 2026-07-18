<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One persisted turn of a conversation session, read back from
 * `tx_nrllm_ai_session_message` (ADR-083).
 *
 * Ordered by {@see self::$sequence} within a session. The token columns are
 * populated for assistant turns (from the completion's usage) and zero for user
 * turns.
 */
final readonly class AiSessionMessage
{
    public function __construct(
        public int $uid,
        public int $session,
        public int $sequence,
        public string $role,
        public string $content,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public int $crdate,
    ) {}

    /**
     * Rehydrate this turn into a {@see ChatMessage} for replay into the provider.
     */
    public function toChatMessage(): ChatMessage
    {
        return new ChatMessage($this->role, $this->content);
    }
}
