<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The atomic resume claim was lost to a concurrent approval (or the store refused it).
 *
 * Thrown by approve(): fail-closed so the gated tool is never double-executed (ADR-084).
 */
final class RunAlreadyResumingException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The atomic resume claim was lost to a concurrent approval (or the store refused it).', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
