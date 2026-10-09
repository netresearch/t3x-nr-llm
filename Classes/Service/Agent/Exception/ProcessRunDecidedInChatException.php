<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * A run that holds a process pin is decided only by its initiator, in the chat
 * (ADR-214 item 6). Thrown by the resume path for anyone else — an
 * administrator and a holder of the approve grant included — before anything
 * is claimed, so the run keeps waiting for the person the chat belongs to.
 */
final class ProcessRunDecidedInChatException extends AgentRuntimeException
{
    public static function forActor(AiActorContext $actor, string $runUuid): self
    {
        return new self(
            $runUuid,
            sprintf(
                '%s may not decide run %s: it runs a guided process, which only the person who started it decides, in the chat.',
                ucfirst($actor->describe()),
                $runUuid !== '' ? $runUuid : 'unknown',
            ),
        );
    }
}
