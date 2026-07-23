<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Exception;

use RuntimeException;
use Throwable;

/**
 * An agent-run state payload could not be decrypted (ADR-114).
 *
 * Thrown by {@see \Netresearch\NrLlm\Service\Tool\AgentStateCodec::decode()} when
 * a version-tagged value fails authentication (tampered, truncated, moved to the
 * wrong column, or written under a different key) or is structurally malformed.
 * The message never contains the ciphertext or key. The persister treats it like
 * any other read failure — a run whose state cannot be decrypted is left where it
 * is (fail-soft on read), never resumed from a forged plaintext.
 */
final class AgentStateDecryptionException extends RuntimeException
{
    public static function authenticationFailed(?Throwable $previous = null): self
    {
        return new self('The stored agent run state failed authentication and was not decrypted.', 1785200001, $previous);
    }

    public static function corrupted(): self
    {
        return new self('The stored agent run state is malformed and could not be decoded.', 1785200002);
    }
}
