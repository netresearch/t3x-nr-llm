<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Session;

use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;

/**
 * Persistence contract for conversation sessions and their message turns
 * (ADR-083).
 *
 * A UI-less log with a read path, mirroring how {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepository}
 * writes raw rows — no Extbase. Split out as an interface so the conversation
 * service can be unit-tested against a double.
 */
interface AiSessionRepositoryInterface
{
    /**
     * Insert a new session and return its primary key.
     */
    public function startSession(string $uuid, int $beUser, string $configurationIdentifier, string $title): int;

    /**
     * Append one message turn to a session.
     */
    public function appendMessage(
        int $sessionUid,
        int $sequence,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): void;

    /**
     * Update a session's activity summary (last-activity timestamp + message count).
     */
    public function touch(int $sessionUid, int $messageCount): void;

    public function findByUuid(string $uuid): ?AiSession;

    /**
     * @return list<AiSessionMessage> The session's turns, ordered by sequence ascending.
     */
    public function findMessages(int $sessionUid): array;

    /**
     * Delete sessions whose last activity predates the given timestamp, together
     * with their messages.
     *
     * @return int Number of session rows deleted.
     */
    public function purgeInactiveSince(int $timestamp): int;
}
