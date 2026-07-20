<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Session;

use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;
use RuntimeException;

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
     * Append one message turn, allocating the next free sequence itself.
     *
     * The concurrency-safe counterpart of {@see self::appendMessage()}: two
     * turns sent into the same session at the same time cannot end up on the
     * same sequence, because the unique key on (session, sequence) decides the
     * race and the loser retries on the next free slot.
     *
     *
     * @throws RuntimeException when no free sequence could be claimed
     *
     * @return int the sequence the turn was stored at
     */
    public function appendMessageAtNextSequence(
        int $sessionUid,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): int;

    /**
     * Update a session's activity summary: the last-activity timestamp always
     * advances, the message count only ever grows — a slower concurrent turn
     * must not report the session back down to its own view of the count.
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
