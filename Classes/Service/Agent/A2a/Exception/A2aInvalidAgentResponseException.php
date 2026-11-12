<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

/**
 * The remote answered with something that is not a valid response:
 * `InvalidAgentResponseError` semantics (`-32006`), never retried (A2A-P5).
 *
 * The message names what is wrong (a missing REQUIRED field, a JSON-RPC id
 * that does not match the request) and never quotes the body.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aInvalidAgentResponseException extends A2aException
{
    /** The field path the refusal names; '' for an envelope error. */
    private string $field = '';

    public static function missingField(string $path): self
    {
        $exception        = new self(sprintf('The agent response is missing the required field "%s".', $path), 1799100100);
        $exception->field = $path;

        return $exception;
    }

    public static function invalidField(string $path, string $why): self
    {
        $exception        = new self(sprintf('The agent response field "%s" is invalid: %s.', $path, $why), 1799100101);
        $exception->field = $path;

        return $exception;
    }

    /**
     * The field the refusal names, for the server's `BadRequest` detail when
     * the same parser reads a client's message (A2A-P5).
     */
    public function field(): string
    {
        return $this->field;
    }

    public static function notJsonRpc(string $why): self
    {
        return new self(sprintf('The agent response is not a JSON-RPC 2.0 response: %s.', $why), 1799100102);
    }

    public static function idMismatch(): self
    {
        return new self("The agent response carries a JSON-RPC id other than the request's.", 1799100103);
    }
}
