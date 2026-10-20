<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Exception\TransientFailureInterface;

/**
 * Whether a run holds a process pin (ADR-214) could not be decided, because
 * the approval of one of its pins could not be read. Nothing was decided and
 * nothing was changed: the run keeps waiting, and the same decision can be
 * tried again. Thrown where the answer would end the run or turn its decider
 * away, so a failed lookup does neither.
 */
final class ProcessVerdictUnavailableException extends AgentRuntimeException implements TransientFailureInterface
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

    /**
     * The approval of a process pin could not be read while a run started,
     * continued or resumed its process (ADR-228). Nothing was decided; the
     * run is tried again, a queued one by the queue's retry.
     */
    public static function forPin(int $skillUid): self
    {
        return new self(
            '',
            sprintf('The approval of process skill %d could not be read; nothing was decided. Try again.', $skillUid),
        );
    }
}
