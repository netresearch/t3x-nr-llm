<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * The acting caller may not act on this run (ADR-083): approving, submitting
 * input to, or cancelling a run belongs to its initiator, an administrator, or a
 * service account. A run UUID alone is never sufficient authorization — this is
 * thrown so a stranger who guesses a uuid cannot drive somebody else's run.
 */
final class RunAccessDeniedException extends AgentRuntimeException
{
    public static function forActor(AiActorContext $actor, string $runUuid): self
    {
        return new self(
            $runUuid,
            sprintf('%s may not act on run %s.', ucfirst($actor->describe()), $runUuid !== '' ? $runUuid : 'unknown'),
        );
    }
}
