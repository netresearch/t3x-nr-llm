<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Lifecycle status of a persisted agent run (ADR-081).
 *
 * A run starts {@see self::RUNNING} (or {@see self::QUEUED} once a batch engine
 * exists), then settles into one of the terminal states. The suspend states
 * ({@see self::WAITING_FOR_APPROVAL}, {@see self::WAITING_FOR_INPUT}) are part
 * of the vocabulary from the outset so the human-in-the-loop and batch epics can
 * reuse this enum without a migration; the persistence layer added here only
 * ever writes RUNNING → COMPLETED/FAILED.
 */
enum AgentRunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case WAITING_FOR_APPROVAL = 'waiting_for_approval';
    case WAITING_FOR_INPUT = 'waiting_for_input';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * A terminal status is one the run can never leave: the loop has finished,
     * failed, or been cancelled. Suspend states are NOT terminal — a waiting run
     * resumes.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * The terminal statuses as raw values, for an SQL predicate.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            static fn(self $case): string => $case->value,
            array_filter(self::cases(), static fn(self $case): bool => $case->isTerminal()),
        ));
    }

    /**
     * The non-terminal statuses as raw values: a run that is still queued,
     * running, or waiting for a human. Retention treats these separately —
     * deleting one destroys work in flight.
     *
     * @return list<string>
     */
    public static function nonTerminalValues(): array
    {
        return array_values(array_map(
            static fn(self $case): string => $case->value,
            array_filter(self::cases(), static fn(self $case): bool => !$case->isTerminal()),
        ));
    }

    /**
     * The statuses that need a human decision, as raw values for an SQL
     * predicate: a run suspended waiting for tool-call approval (ADR-084) or for
     * typed user input (ADR-105). The Agent Runs approvals inbox lists exactly
     * these.
     *
     * @return list<string>
     */
    public static function awaitingValues(): array
    {
        return [self::WAITING_FOR_APPROVAL->value, self::WAITING_FOR_INPUT->value];
    }
}
