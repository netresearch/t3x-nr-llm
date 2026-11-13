<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Queue;

use DateTimeImmutable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sets TYPO3's clock to now before a worker starts a queued run.
 *
 * TYPO3 reads the time once, at boot, into `EXEC_TIME` and the `date` aspect.
 * A worker that waits, or works through a queue for minutes, would write every
 * record, history row and cache entry of its later runs with the time it
 * booted. nr_mcp_agent's chat worker sets the clock before every turn; the
 * queue worker and the Messenger handler do the same before every run.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class WorkerClock
{
    public static function set(): void
    {
        $now = time();
        foreach (['EXEC_TIME', 'SIM_EXEC_TIME'] as $global) {
            $GLOBALS[$global] = $now;
        }

        foreach (['ACCESS_TIME', 'SIM_ACCESS_TIME'] as $global) {
            $GLOBALS[$global] = $now - $now % 60;
        }

        GeneralUtility::makeInstance(Context::class)->setAspect('date', new DateTimeAspect(new DateTimeImmutable('@' . $now)));
    }
}
