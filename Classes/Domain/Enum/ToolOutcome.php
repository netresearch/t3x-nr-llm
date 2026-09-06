<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How a tool execution ended (ADR-191, #774).
 *
 * A tool result carried one boolean, `isError`, and the run inspector rendered
 * it as OK or Failed. Since ADR-190 a third thing can happen: the operator
 * cancels the run and the call it has on the wire is torn down. That is not a
 * failure -- the server may have been perfectly healthy -- but it arrived as
 * one, so a cancelled call appeared in the run timeline beside genuine faults
 * and inflated every count taken from them.
 *
 * `isError` stays and stays true for both non-OK cases, so every existing
 * consumer keeps its meaning; this says WHICH of the two it was.
 *
 * @api
 */
enum ToolOutcome: string
{
    case OK = 'ok';

    /**
     * The tool, or the far side it called, did not do what was asked.
     */
    case FAILED = 'failed';

    /**
     * The run was cancelled while this call was open.
     *
     * Says nothing about the tool or the server. Whether a remote write landed
     * is not knowable from here -- see ADR-190.
     */
    case CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
