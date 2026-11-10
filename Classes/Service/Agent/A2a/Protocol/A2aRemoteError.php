<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * A JSON-RPC error object as the remote sent it (A2A-P6).
 *
 * `$reason`, `$domain` and `$errorInfoMetadata` come from the first
 * `google.rpc.ErrorInfo` entry of `error.data`; `$details` keeps every entry of
 * that array, each a ProtoJSON `Any` object with its type URL. A `data` member
 * that is not an
 * array (the shape §9.5 forbids, A2A-D6) contributes no details.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRemoteError
{
    /**
     * @param array<string, string>      $errorInfoMetadata
     * @param list<array<string, mixed>> $details
     */
    public function __construct(
        public int $code,
        public string $message,
        public string $reason = '',
        public string $domain = '',
        public array $errorInfoMetadata = [],
        public array $details = [],
    ) {}

    /**
     * The named code, null for one this client does not know.
     */
    public function errorCode(): ?A2aErrorCode
    {
        return A2aErrorCode::tryFrom($this->code);
    }

    public function is(A2aErrorCode $code): bool
    {
        return $this->code === $code->value;
    }

    /**
     * The reason to show and store: the ErrorInfo reason when one was sent,
     * otherwise the one the protocol pairs with the code, otherwise ''.
     */
    public function effectiveReason(): string
    {
        if ($this->reason !== '') {
            return $this->reason;
        }

        return $this->errorCode()?->reason() ?? '';
    }
}
