<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Throwable;

/**
 * @internal Preserve the operation budget and cancellation across authentication legs.
 */
final class McpAuthOperationGuard
{
    public static function assertAlive(
        string $server,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation,
    ): void {
        try {
            $cancelled = $cancellation?->isCancelled() ?? false;
        } catch (Throwable) {
            throw McpTransportException::forDelegatedAuthFailure($server, 'cancellation_signal_failed');
        }

        if ($cancelled) {
            throw McpTransportException::forCancelledCall($server);
        }

        if ($deadline->isExhausted()) {
            throw McpTransportException::forExhaustedDeadline($server, $deadline->totalSeconds());
        }
    }
}
