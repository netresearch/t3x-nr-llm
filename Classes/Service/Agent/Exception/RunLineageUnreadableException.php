<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * What a continuation would derive from a run could not be read (ADR-228):
 * the run's events could not be loaded, or its lineage event is not one.
 *
 * Refused rather than read as "no process", which would let the next turn of
 * a guided process go on without it.
 */
final class RunLineageUnreadableException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self(
            $runUuid,
            sprintf(
                'What run %s holds for its conversation could not be read; start a new conversation.',
                $runUuid !== '' ? $runUuid : 'unknown',
            ),
        );
    }
}
