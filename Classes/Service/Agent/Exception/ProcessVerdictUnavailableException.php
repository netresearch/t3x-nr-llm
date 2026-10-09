<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * Whether a run holds a process pin (ADR-214) could not be decided, because
 * the approval of one of its pins could not be read. Nothing was decided and
 * nothing was changed: the run keeps waiting, and the same decision can be
 * tried again. Thrown where the answer would end the run or turn its decider
 * away, so a failed lookup does neither.
 */
final class ProcessVerdictUnavailableException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self(
            $runUuid,
            sprintf(
                'Run %s was not decided: whether it runs a guided process could not be checked. It is still waiting; try again.',
                $runUuid !== '' ? $runUuid : 'unknown',
            ),
        );
    }
}
