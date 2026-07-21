<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The LlmConfiguration the suspended run was started with no longer exists.
 *
 * Thrown by approve(): the run cannot be resumed without its configuration.
 */
final class RunConfigurationGoneException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The LlmConfiguration the suspended run was started with no longer exists.', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
