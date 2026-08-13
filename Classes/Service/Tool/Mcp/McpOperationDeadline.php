<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

/**
 * One budget for one MCP operation, spent across its legs (ADR-170).
 *
 * An MCP operation is several HTTP round trips: the handshake, its
 * confirmation, and the request that was actually wanted. Each of those used to
 * carry a full timeout of its own, so a server answering just inside its own
 * limit three times over stalled a tool call for three times the number the
 * constant named. The budget belongs to the OPERATION, so it is carried by an
 * object the operation owns and each leg is granted what is left of it.
 *
 * It is a value passed down rather than state on the transport for the same
 * reason the transport builds a fresh client per call: the transport is a DI
 * singleton on a long-lived worker, and a remaining-budget field on it would be
 * shared between two operations against two servers.
 *
 * Time is read through {@see McpClockInterface} so the arithmetic is assertable
 * without sleeping.
 */
final readonly class McpOperationDeadline
{
    /**
     * The smallest timeout a leg may be given.
     *
     * Not cosmetic. The transport hands this number to
     * {@see \Netresearch\NrVault\Http\VaultHttpClientInterface::withTimeout()},
     * which treats a non-positive value as "no override" and rebuilds the
     * client from `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout']` — whose
     * TYPO3 default is `0`, and Guzzle reads `0` as *wait forever*. A leg
     * granted zero seconds would therefore be the one leg with no bound at all,
     * which is the exact opposite of a deadline. Below this floor the operation
     * refuses to send instead ({@see self::isExhausted()}).
     */
    public const MINIMUM_LEG_SECONDS = 1;

    private function __construct(
        private McpClockInterface $clock,
        private int $totalSeconds,
        private int $startedAtNanoseconds,
    ) {}

    /**
     * Open a budget of `$totalSeconds` and start spending it now.
     *
     * A total below the leg floor would be exhausted before the first request,
     * which is a misconfiguration rather than a policy, so it is raised to the
     * floor. {@see McpDeadlineFactory} is what turns operator configuration
     * into this number.
     */
    public static function start(McpClockInterface $clock, int $totalSeconds): self
    {
        return new self(
            $clock,
            max(self::MINIMUM_LEG_SECONDS, $totalSeconds),
            $clock->monotonicNanoseconds(),
        );
    }

    /**
     * The whole budget, as granted — for the message an exhausted operation
     * writes, which has to name the number an operator can change.
     */
    public function totalSeconds(): int
    {
        return $this->totalSeconds;
    }

    /**
     * What is left, in seconds; negative once the budget is overspent.
     */
    public function remainingSeconds(): float
    {
        $elapsed = ($this->clock->monotonicNanoseconds() - $this->startedAtNanoseconds) / 1_000_000_000;

        return $this->totalSeconds - $elapsed;
    }

    /**
     * Whether the budget is gone and the next leg must not be sent.
     */
    public function isExhausted(): bool
    {
        return $this->remainingSeconds() <= 0.0;
    }

    /**
     * The timeout the next leg is granted: what remains, never below the floor.
     *
     * Rounded UP, so a fraction of a second left is a leg that may run for one
     * — the overrun is bounded by a second and the alternative is the unbounded
     * request the floor exists to prevent.
     */
    public function legTimeoutSeconds(): int
    {
        return max(self::MINIMUM_LEG_SECONDS, (int)ceil($this->remainingSeconds()));
    }
}
