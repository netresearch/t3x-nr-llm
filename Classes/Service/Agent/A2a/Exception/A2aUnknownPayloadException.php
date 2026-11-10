<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Exception;

/**
 * A `SendMessageResponse` whose only member is one the pinned proto does not
 * name, such as a v1.1 payload kind (A2A-P4).
 *
 * It is not an invalid response: the remote may well have created a task,
 * and this client cannot read which. The send path therefore records the
 * outcome as unknown and never sends again.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aUnknownPayloadException extends A2aException
{
    /**
     * @param list<string> $members
     */
    public static function onlyUnknownMembers(string $where, array $members): self
    {
        return new self(
            sprintf('The %s carries only payload kinds this client does not know: %s.', $where, implode(', ', $members)),
            1799100110,
        );
    }
}
