<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Where a {@see CallOutcome} came from (ADR-176).
 *
 * The two never average into one figure. They do not cover the same traffic:
 * a task execution renders a result the editor copies by hand, so there is no
 * write target and nothing to observe, while an editorial action writes
 * through the DataHandler against a named record. A mean over both would
 * compare an observable population against an unobservable one, and in a
 * canary the arm difference would carry that asymmetry rather than the models'
 * quality.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
enum CallOutcomeSource: string
{
    case EXPLICIT = 'explicit';
    case OBSERVED = 'observed';
}
