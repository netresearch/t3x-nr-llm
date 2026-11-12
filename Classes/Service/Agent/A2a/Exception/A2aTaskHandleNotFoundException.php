<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

/**
 * The calling local actor holds no handle for this remote task (spec 051
 * A2A-C7). Another user's task and a task nobody here created read the same,
 * and neither is asked of the remote.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aTaskHandleNotFoundException extends A2aException
{
    public static function forTask(string $agentIdentifier, string $remoteTaskId): self
    {
        return new self(
            sprintf('No task "%s" of the A2A agent "%s" belongs to you.', $remoteTaskId, $agentIdentifier),
            1799100410,
        );
    }
}
