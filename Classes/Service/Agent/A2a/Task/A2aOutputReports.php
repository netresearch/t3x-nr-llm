<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Netresearch\NrLlm\Service\Editorial\ExternalTextReport;

/**
 * Hands the full report of an end-of-run check, passages included, from the
 * check to the completion of the same run in the same process (ADR-301).
 *
 * The run event keeps the report without any passage, so it may stay at
 * every privacy level. The passages reach the report artifact only through
 * this hand-over, filtered there at the answer level read under the lock; a
 * completion in another process finds nothing here and reports without them.
 *
 * Shared by the container. Each entry is taken once.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aOutputReports
{
    /** @var array<int, ExternalTextReport> */
    private array $reports = [];

    public function put(int $runUid, ExternalTextReport $report): void
    {
        $this->reports[$runUid] = $report;
    }

    public function take(int $runUid): ?ExternalTextReport
    {
        $report = $this->reports[$runUid] ?? null;
        unset($this->reports[$runUid]);

        return $report;
    }
}
