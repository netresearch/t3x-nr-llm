<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Whether a write that named its record did everything the call planned
 * (ADR-214, item 9).
 *
 * Stated by the tool on every return that names a write target, through
 * {@see \Netresearch\NrLlm\Domain\ValueObject\ToolResult::withWriteTarget()},
 * and copied onto the write step of the run trace. A consumer that shows
 * whether an approved proposal was applied reads it there: COMPLETE is
 * applied, PARTIAL is not, and an absent value — a third-party tool that does
 * not state it — means the record has to be checked.
 *
 * It is the tool's statement about its own plan, decided per return, and the
 * only source of it. Nothing infers it from the result text, and a failed hook
 * after the write does not change it: that is reported beside it
 * (`hookFailedAfterWrite`, ADR-206).
 *
 * @api
 */
enum WriteCompleteness: string
{
    /**
     * Every write the call planned took.
     */
    case COMPLETE = 'complete';

    /**
     * The record was written, but part of what the call planned did not take:
     * a field the DataHandler dropped, a translation left behind, a record that
     * should have gone with it and is still there.
     */
    case PARTIAL = 'partial';
}
