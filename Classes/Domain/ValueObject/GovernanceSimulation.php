<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SimulationVerdict;

/**
 * What the whole run would do, axis by axis, and the one verdict that follows
 * (ADR-157).
 *
 * ADR-145's simulator answered for the tool gate alone, which is one of five
 * things that can stop a tool-calling run. A page that says "Allowed" while the
 * input-context gate would refuse the send, while routing resolves no model at
 * all, or while the actor may not use the configuration in the first place
 * (ADR-167), is not a partial answer — it is a wrong one.
 *
 * Every axis keeps its own answer. The verdict is a fold over them, never a
 * substitute for them: an operator needs to see WHICH axis decided, because the
 * fix differs per axis (a tool group, a data class, a model catalogue, an
 * approval workflow).
 *
 * **Not every axis is actor-scoped, and the readout says so.** Routing answers
 * the same for every actor —
 * {@see \Netresearch\NrLlm\Domain\Repository\ModelRepository::findActive()}
 * ignores enable-fields and reads no user — and so does the input-context gate,
 * which compares a configuration's declared classes against the zone it can
 * reach. Two axes read the actor: the tool gate through `requiresAdmin()`, and
 * configuration access through the actor's backend groups (ADR-070, ADR-167). A
 * simulator that silently answered identically on the other two would imply a
 * dimension that is not there.
 *
 * @internal
 */
final readonly class GovernanceSimulation
{
    /**
     * @param ToolPolicyDecision   $tool                 the ADR-094 gate — actor-scoped
     * @param InputContextDecision $context              the ADR-144 gate, per configuration
     * @param RoutingReadout       $routing              the ADR-142 decision, per configuration
     * @param bool                 $approvalRequired     whether {@see \Netresearch\NrLlm\Service\Tool\ToolApprovalRule}
     *                                                   binds the tool to a human decision (ADR-084/134)
     * @param bool                 $configurationAllowed whether the actor may use this configuration at all —
     *                                                   {@see \Netresearch\NrLlm\Service\ConfigurationResolver::actorMayUse()},
     *                                                   the second actor-scoped axis (ADR-070/167)
     * @param SimulationActor      $actor                whose permissions the actor-scoped axes were asked with
     */
    public function __construct(
        public ToolPolicyDecision $tool,
        public InputContextDecision $context,
        public RoutingReadout $routing,
        public bool $approvalRequired,
        public bool $configurationAllowed,
        public SimulationActor $actor,
    ) {}

    /**
     * The fold. Any refusing axis blocks; otherwise approval is what separates
     * a call that runs from one that waits for a human.
     *
     * Named `get…` so Fluid reaches it as `{simulation.verdict}`.
     */
    public function getVerdict(): SimulationVerdict
    {
        if (!$this->configurationAllowed
            || !$this->tool->allowed
            || !$this->context->isPermitted()
            || !$this->routing->hasSelection()
        ) {
            return SimulationVerdict::BLOCK;
        }

        return $this->approvalRequired ? SimulationVerdict::ALLOW_WITH_APPROVAL : SimulationVerdict::ALLOW;
    }

    /**
     * Whether routing produced a model to send to.
     *
     * Its own accessor because the verdict alone cannot say which axis refused,
     * and `{simulation.routing.selection}` would read as the model rather than
     * as the axis's answer.
     */
    public function hasServingModel(): bool
    {
        return $this->routing->hasSelection();
    }
}
