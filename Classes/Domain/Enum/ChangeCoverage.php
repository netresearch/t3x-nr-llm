<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How far a change list reaches (ADR-292).
 *
 * There is no "complete". The list comes from the DataHandler's hooks and its
 * `sys_history` rows, so a write that does not go through a DataHandler — a
 * query a hook of the installation runs, a file written through FAL — is
 * invisible to it, and nothing in the list can say that none happened.
 *
 * @api
 */
enum ChangeCoverage: string
{
    /**
     * The list is what the DataHandler runs of the call reported. Writes past
     * the DataHandler are not in it, and its absence says nothing about them.
     */
    case DATAHANDLER = 'datahandler';

    /**
     * The capture did not observe the call: it failed, or the tool stated a
     * write that no DataHandler run reported. Whatever the list holds is all
     * that was seen; a consumer must read the record itself.
     */
    case NOT_CAPTURED = 'not_captured';
}
