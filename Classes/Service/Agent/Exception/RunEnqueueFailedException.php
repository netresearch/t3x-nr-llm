<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The run could not be enqueued: the queued row could not be stored, or the
 * message could not be dispatched (the stored row is then settled failed, so
 * no orphaned QUEUED run is left behind).
 *
 * Thrown by enqueue(): a queued run without a persisted row and a dispatched
 * wake-up simply does not exist — fail closed rather than promising an
 * execution that will never happen (ADR-102).
 */
final class RunEnqueueFailedException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf(
            'The run could not be enqueued (run %s).',
            $runUuid !== '' ? $runUuid : 'unpersisted',
        ));
    }
}
