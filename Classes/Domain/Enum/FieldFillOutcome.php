<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What became of the answer of a run that fills an empty field (ADR-237).
 */
enum FieldFillOutcome: string
{
    case WRITTEN = 'written';

    /**
     * Somebody wrote the field while the run was queued; their text stands.
     */
    case NOT_EMPTY = 'not_empty';

    /**
     * Refused: the rights, the opt-in, the length, or the DataHandler.
     */
    case NOT_WRITTEN = 'not_written';
}
