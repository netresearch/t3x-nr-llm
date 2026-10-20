<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * A run that holds a process pin was stopped because its configuration sets
 * `require_second_approver` (ADR-214 item 6). Under four-eyes the initiator
 * may not apply a proposal (ADR-172), and on a process run nobody else may
 * decide one, so the run could never move again. Its exception class is
 * recorded on the stopped run.
 */
final class ProcessRunNeedsSecondApproverException extends AgentRuntimeException
{
    public static function forRun(string $runUuid, string $configurationIdentifier): self
    {
        return new self(
            $runUuid,
            sprintf(
                'Run %s was stopped: it runs a guided process, and configuration "%s" sets require_second_approver, under which nobody could apply its proposals.',
                $runUuid !== '' ? $runUuid : 'unknown',
                $configurationIdentifier,
            ),
        );
    }
}
