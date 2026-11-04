<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Exception\RuntimeException;

/**
 * The OAuth connect flow of an MCP server stopped (ADR-259).
 *
 * The reason is a fixed code chosen here, never text an authorization server
 * or an MCP server wrote: the message reaches the backend module and the log,
 * and a remote party must not be able to write into either. The code is kept
 * separately so the module can translate it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthException extends RuntimeException
{
    private string $reason = 'failed';

    public static function because(string $reason): self
    {
        $code = preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) === 1 ? $reason : 'failed';

        $exception         = new self(sprintf('The OAuth connection failed (%s).', $code), 1799990223);
        $exception->reason = $code;

        return $exception;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
