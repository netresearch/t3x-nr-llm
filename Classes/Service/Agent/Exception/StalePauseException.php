<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * A stored decision names a pause generation the run is no longer in
 * (ADR-281, spec 051 A2A-MT2): the run went on and paused again since the
 * decision was made, or the generation was never the run's.
 *
 * Nothing was stored and nothing ran; the decider has to look at the current
 * pause and decide again.
 */
final class StalePauseException extends AgentRuntimeException
{
    public static function forRun(string $runUuid, int $expected, ?int $current): self
    {
        return new self($runUuid, sprintf(
            'The decision was made for pause %d, the run is %s. (run %s)',
            $expected,
            $current === null ? 'at no readable pause' : 'at pause ' . $current,
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }
}
