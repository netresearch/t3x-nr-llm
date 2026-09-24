<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The LlmConfiguration the suspended run was started with has been deactivated.
 *
 * Thrown by approve() and submitInput() before anything is claimed, so the run
 * stays suspended: reactivating the configuration makes it decidable again.
 * A denial is refused as well, because it resumes the loop and so calls the
 * provider through the deactivated configuration.
 */
final class RunConfigurationInactiveException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The LlmConfiguration the suspended run was started with is deactivated.', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
