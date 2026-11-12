<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;

/**
 * The wait of a blocking `SendMessage` (spec 051 A2A-SV8, ADR-283).
 *
 * It reads the committed task view, owner-scoped, until the task is in a
 * terminal or an interrupted state. It never answers early with a task that
 * is still SUBMITTED or WORKING: when its deadline passes it says so, and the
 * caller answers a system error that carries the task id. Every read is a
 * statement of its own, outside a transaction, so it sees what committed
 * since the last read.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskWaiter
{
    private const POLL_MICROSECONDS = 100000;

    /**
     * @param (Closure(int): void)|null $sleep sleeps the given microseconds; a test steps time with it
     * @param (Closure(): float)|null   $clock seconds since the epoch
     */
    public function __construct(
        private A2aTaskView $view,
        private ?Closure $sleep = null,
        private ?Closure $clock = null,
    ) {}

    /**
     * @return array<string, mixed>|null the row in a terminal or interrupted state, null when the deadline passed first
     */
    public function wait(int $ownerClientUid, string $taskId, float $deadline): ?array
    {
        while (true) {
            $row   = $this->view->find($ownerClientUid, $taskId);
            $state = is_array($row) && is_string($row['state'] ?? null) ? A2aTaskState::tryFrom($row['state']) : null;
            if ($state instanceof A2aTaskState && ($state->isTerminal() || $state->isInterrupted())) {
                return $row;
            }

            if ($this->now() >= $deadline) {
                return null;
            }

            ($this->sleep ?? usleep(...))(self::POLL_MICROSECONDS);
        }
    }

    private function now(): float
    {
        return $this->clock instanceof Closure ? ($this->clock)() : microtime(true);
    }
}
