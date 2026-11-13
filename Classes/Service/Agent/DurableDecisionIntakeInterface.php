<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Exception\AgentRuntimeException;

/**
 * Takes a decision on a paused run in, stores it and hands the run to the
 * worker, instead of continuing the run in the deciding request (ADR-281,
 * amending ADR-276; spec 051 A2A-MT2).
 *
 * Before anything is stored, the same checks as
 * {@see AgentRuntimeInterface::submitInput()} and
 * {@see AgentRuntimeInterface::approve()} apply: the pause is still pending
 * and has the generation the decision was made for, the deciding actor may act
 * on the run, the input matches its schema, the decision names the pending
 * turn by its digest, and the decider may run what the turn calls. The run
 * then goes to QUEUED with the decision; the worker checks the decider's
 * rights again before the first effect. The in-request path and this one race
 * for one pause on the same guarded transition: exactly one of them wins.
 *
 * A refusal throws the exception the in-request path throws for it, plus
 * {@see Exception\StalePauseException} for a generation the run is no longer
 * at; nothing is stored then.
 *
 * CONSUMER interface: call it; do not implement or decorate it outside nr_llm.
 *
 * @api
 */
interface DurableDecisionIntakeInterface
{
    /**
     * The pause the run waits in, or null when it waits for no decision (or
     * the actor may not act on it).
     */
    public function pendingPause(AiActorContext $actor, string $runUuid): ?PendingPause;

    /**
     * @throws AgentRuntimeException
     */
    public function storeInput(AiActorContext $actor, string $runUuid, InputSubmission $submission, int $pauseGeneration): DecisionReceipt;

    /**
     * @throws AgentRuntimeException
     */
    public function storeApproval(AiActorContext $actor, string $runUuid, ApprovalDecision $decision, int $pauseGeneration): DecisionReceipt;
}
