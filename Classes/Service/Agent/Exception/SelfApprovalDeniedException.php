<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * The actor started this run and its configuration requires a second approver
 * (ADR-172).
 *
 * Distinct from {@see RunAccessDeniedException}, which says the actor may not
 * act on the run at all. Here the actor may act — the same person could deny
 * the turn, or approve a run somebody else started — but must not release a
 * write they themselves asked for. The message names the configuration so an
 * operator who did not expect the refusal can see where the setting lives.
 */
final class SelfApprovalDeniedException extends AgentRuntimeException
{
    public static function forActor(AiActorContext $actor, string $runUuid, string $configurationIdentifier): self
    {
        return new self(
            $runUuid,
            sprintf(
                '%s started run %s and configuration "%s" requires a second approver.',
                ucfirst($actor->describe()),
                $runUuid !== '' ? $runUuid : 'unknown',
                $configurationIdentifier,
            ),
        );
    }
}
