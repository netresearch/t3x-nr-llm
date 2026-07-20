<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;

/**
 * In-memory conversation-session repository for command tests: captures the
 * cutoff a purge was asked to run so the retention flow can be exercised
 * without a database.
 */
final class InMemoryAiSessionRepository implements AiSessionRepositoryInterface
{
    /** The cutoff timestamp the last purgeInactiveSince() was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** The row count purgeInactiveSince() reports as deleted. */
    public int $purgeReturns = 0;

    public function startSession(string $uuid, int $beUser, string $configurationIdentifier, string $title): int
    {
        return 0;
    }

    public function appendMessage(
        int $sessionUid,
        int $sequence,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): void {
        // Not needed by the command tests.
    }

    public function appendMessageAtNextSequence(
        int $sessionUid,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): int {
        return 0;
    }

    public function touch(int $sessionUid, int $messageCount): void
    {
        // Not needed by the command tests.
    }

    public function findByUuid(string $uuid): ?AiSession
    {
        return null;
    }

    public function findMessages(int $sessionUid): array
    {
        return [];
    }

    public function purgeInactiveSince(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }
}
