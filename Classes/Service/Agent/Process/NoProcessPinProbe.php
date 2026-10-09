<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;

/**
 * No run holds a process pin. For wiring that has no skill approvals to ask,
 * such as tests; production aliases {@see ApprovedProcessPinProbe}.
 *
 * @internal
 */
final readonly class NoProcessPinProbe implements ProcessPinProbe
{
    public function holdsProcessPin(AgentRun $run): bool
    {
        return false;
    }

    public function anyProcessPin(array $pins): bool
    {
        return false;
    }
}
