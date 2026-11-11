<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use DateTimeImmutable;

/**
 * `TaskStatus` (proto `TaskStatus`).
 *
 * `$state` is null when the remote sent a state the pinned proto does not
 * name; `$rawState` keeps what it sent. Such a status is neither terminal nor
 * interrupted (A2A-P4). `$timestamp` is optional: a2a-python 1.3.0 sends a
 * SUBMITTED status without it, and a missing one stays null, never "now".
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskStatus
{
    public function __construct(
        public ?A2aTaskState $state,
        public string $rawState,
        public ?A2aMessage $message = null,
        public ?DateTimeImmutable $timestamp = null,
    ) {}

    public static function of(A2aTaskState $state): self
    {
        return new self($state, $state->value);
    }

    public function isTerminal(): bool
    {
        return $this->state?->isTerminal() ?? false;
    }

    public function isInterrupted(): bool
    {
        return $this->state?->isInterrupted() ?? false;
    }

    /**
     * Whether the state is one the pinned proto names.
     */
    public function isKnown(): bool
    {
        return $this->state instanceof A2aTaskState;
    }
}
