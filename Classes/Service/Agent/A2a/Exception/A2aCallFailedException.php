<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;

/**
 * A GetTask, ListTasks or CancelTask call, or a card import, did not produce a
 * result: the remote answered an error (`$remoteError`), the response was
 * invalid, or the transport did not get one. `$reason` is machine-readable.
 *
 * These calls create no remote state, so nothing is journalled for them; a
 * caller may simply ask again.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aCallFailedException extends A2aException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?A2aRemoteError $remoteError = null,
    ) {
        parent::__construct($message, 1799100420);
    }

    public static function remote(string $method, A2aRemoteError $error): self
    {
        return new self(
            sprintf('%s was refused by the remote agent: JSON-RPC %d %s', $method, $error->code, $error->effectiveReason() !== '' ? $error->effectiveReason() : $error->message),
            $error->effectiveReason(),
            $error,
        );
    }

    public static function local(string $method, string $reason, string $detail): self
    {
        return new self(sprintf('%s failed: %s (%s)', $method, $detail, $reason), $reason);
    }
}
