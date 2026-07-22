<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The submitted input did not match the tool's declared schema (ADR-105).
 *
 * Thrown by submitInput() BEFORE the run is claimed, so the run stays
 * WAITING_FOR_INPUT and the user can resubmit — nothing was consumed.
 */
final class InvalidInputSubmissionException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf('%s (run %s)', 'The submitted input did not match the required schema.', $runUuid !== '' ? $runUuid : 'unknown'));
    }
}
