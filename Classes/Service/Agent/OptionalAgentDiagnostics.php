<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Closure;
use Throwable;

/** Optional diagnostics preserve the authoritative run result (ADR-225).
 * @internal
 */
trait OptionalAgentDiagnostics
{
    /**
     * @param Closure(): void $write
     */
    private function recordDiagnostic(Closure $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // Reporting failure cannot decide run fate or retry reporting.
        }
    }
}
