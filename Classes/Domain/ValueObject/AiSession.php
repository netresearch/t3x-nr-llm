<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * A persisted conversation session, read back from `tx_nrllm_ai_session`
 * (ADR-083).
 *
 * The immutable read model of one multi-turn conversation. Messages live in
 * `tx_nrllm_ai_session_message` and are read as {@see AiSessionMessage} value
 * objects; this row carries the session's identity, owner and activity summary.
 */
final readonly class AiSession
{
    public function __construct(
        public int $uid,
        public string $uuid,
        public int $beUser,
        public string $configurationIdentifier,
        public string $title,
        public int $messageCount,
        public int $lastActivity,
        public int $crdate,
    ) {}
}
