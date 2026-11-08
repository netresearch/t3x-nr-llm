<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

use Closure;
use Throwable;

/**
 * Optional diagnostics never replace an authoritative operation result. @internal.
 */
trait OptionalDiagnostics
{
    /**
     * @param Closure(): void $write
     */
    private function recordDiagnostic(Closure $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            /* Diagnostics are optional. */
        }
    }
}
