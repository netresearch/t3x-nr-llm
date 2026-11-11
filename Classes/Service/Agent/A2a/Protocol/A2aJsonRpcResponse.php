<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * A decoded JSON-RPC response whose id matched the request: a `result` or an
 * `error`, exactly one of them.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aJsonRpcResponse
{
    /**
     * @param array<string, mixed>|null $result
     */
    private function __construct(
        public ?array $result,
        public ?A2aRemoteError $error,
    ) {}

    /**
     * @param array<string, mixed> $result
     */
    public static function ofResult(array $result): self
    {
        return new self($result, null);
    }

    public static function ofError(A2aRemoteError $error): self
    {
        return new self(null, $error);
    }

    /**
     * @phpstan-assert-if-true !null $this->error
     * @phpstan-assert-if-true null $this->result
     *
     * @phpstan-assert-if-false null $this->error
     * @phpstan-assert-if-false !null $this->result
     */
    public function isError(): bool
    {
        return $this->error instanceof A2aRemoteError;
    }
}
