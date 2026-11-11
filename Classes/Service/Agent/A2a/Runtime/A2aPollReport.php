<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

/**
 * What one poller tick did (spec 051 A2A-R10): runs resumed with a remote
 * result, resumed at their deadline, still waiting after a look, not yet due,
 * and failed because their checkpoint could not be read.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aPollReport
{
    public int $resumed = 0;

    public int $timedOut = 0;

    public int $waiting = 0;

    public int $notDue = 0;

    public int $failed = 0;
}
