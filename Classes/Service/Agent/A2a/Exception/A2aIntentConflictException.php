<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

/**
 * The same owner used a message id again for another request (spec 051
 * A2A-C5). Refused locally, before anything is sent.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aIntentConflictException extends A2aException
{
    public static function differentPayload(string $messageId): self
    {
        return new self(
            sprintf('The message id "%s" was already used for a different request; nothing was sent.', $messageId),
            1799100200,
        );
    }
}
