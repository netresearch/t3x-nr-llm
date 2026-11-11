<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

/**
 * The send-intent journal could not do what was asked; nothing was sent.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aJournalException extends A2aException
{
    public static function insideTransaction(): self
    {
        return new self(
            'A send intent must be committed before the request leaves, and a transaction is open; nothing was sent.',
            1799100210,
        );
    }

    public static function noOwner(): self
    {
        return new self('A send intent needs a local actor (a backend user uid above 0); nothing was sent.', 1799100211);
    }

    public static function alreadySettled(int $uid): self
    {
        return new self(sprintf('The send intent %d already has an outcome.', $uid), 1799100212);
    }

    public static function unreadable(int $uid): self
    {
        return new self(sprintf('The send intent %d could not be read back.', $uid), 1799100213);
    }
}
