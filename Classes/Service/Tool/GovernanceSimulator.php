<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceSimulation;
use Netresearch\NrLlm\Domain\ValueObject\SimulationActor;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\InputContextTrustGate;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Runs one tool call past every gate that could stop it, and reports what each
 * one said (ADR-157).
 *
 * Five axes, five real runtime services, no second copy of any rule:
 *
 * - the tool gate — {@see ToolCallPolicyInterface::decide()} (ADR-094);
 * - the input-context gate — {@see InputContextTrustGate::decide()}, the
 *   non-throwing half of the rule `assertPermitted()` enforces (ADR-144);
 * - routing eligibility — {@see ModelSelectionServiceInterface::explainRouting()},
 *   the same {@see \Netresearch\NrLlm\Service\Routing\RoutingDecisionService}
 *   call the runtime makes (ADR-142);
 * - the approval requirement — {@see ToolApprovalRule}, the predicate the loop
 *   itself scans with (ADR-084/134);
 * - configuration access — {@see ConfigurationResolver::actorMayUse()}, the
 *   non-throwing half of the rule `getActiveByIdentifierForActor()` enforces
 *   (ADR-070, ADR-167).
 *
 * **It lives in the tool module, not in `Service\Governance`.** Core may not
 * import the tool module (ADR-090, enforced by `ModuleSeamTest`), and the thing
 * being simulated is a TOOL call — the entry point takes a tool name and half
 * its collaborators are tool-module classes. The two core gates it also asks
 * are a dependency in the allowed direction.
 *
 * **It writes nothing.** No governance event, no session switch, no execution.
 * The runtime records a `GovernanceEvent` when it BLOCKS a call; a simulation
 * blocks nothing, and filling the audit with calls that never happened would
 * cost the audit the only property that makes it worth reading — that every row
 * is something the installation actually did. ADR-157 states the choice and the
 * consequence: a simulation leaves no trace, so "who checked what" is not
 * answerable from the audit.
 *
 * **The actor is resolved, never impersonated.**
 * {@see ActingBackendUserResolverInterface} is the read-only seam a queue worker
 * already uses to authorise for the user who queued the work (ADR-083):
 * uid → fresh database record → permission surface. Privilege comes from that
 * record, so a picker cannot mint rights the user does not have.
 *
 * @internal
 */
final readonly class GovernanceSimulator
{
    use SafeCastTrait;

    /**
     * The operation the routing axis is asked about.
     *
     * A simulation is about a TOOL call, so the run that would carry it is a
     * tool-calling one — and under ADR-138 that is the operation whose
     * capability requirement applies. Asking with no operation would answer for
     * a run this page is not describing.
     */
    private const OPERATION = ProviderOperation::Tools;

    public function __construct(
        private ToolCallPolicyInterface $toolCallPolicy,
        private InputContextTrustGate $inputContextGate,
        private ModelSelectionServiceInterface $modelSelectionService,
        private ToolRegistry $toolRegistry,
        private ActingBackendUserResolverInterface $actingBackendUserResolver,
        private ConfigurationResolver $configurationResolver,
    ) {}

    /**
     * @param int                            $actorUid the backend user to answer for; 0 or less means
     *                                                 the operator reading the page
     * @param BackendUserAuthentication|null $operator the ambient backend user, so this service never
     *                                                 reaches into `$GLOBALS`
     */
    public function simulate(
        string $toolName,
        LlmConfiguration $configuration,
        int $actorUid,
        ?BackendUserAuthentication $operator,
    ): GovernanceSimulation {
        $user  = $actorUid > 0 ? $this->actingBackendUserResolver->resolve(AiActorContext::backendUser($actorUid)) : $operator;
        $actor = $this->actor($actorUid, $user);

        return new GovernanceSimulation(
            $this->toolCallPolicy->decide($toolName, $configuration, $user),
            $this->inputContextGate->decide($configuration),
            $this->modelSelectionService->explainRouting($configuration, self::OPERATION, null),
            ToolApprovalRule::requiresApproval($this->toolRegistry->get($toolName)),
            $this->configurationResolver->actorMayUse($configuration, $this->actorContext($user)),
            $actor,
        );
    }

    /**
     * The actor the configuration-access rule is asked with.
     *
     * Built from the RESOLVED backend user, never from the uid alone.
     * `AiActorContext::backendUser($actorUid)` above carries the defaults
     * `isAdmin=false, backendGroupIds=[]`, which is correct as an argument to
     * {@see ActingBackendUserResolverInterface::resolve()} — that call answers
     * with the real record — and would be a lie here: every restricted
     * configuration would read "Refused" for every actor, administrators
     * included. {@see ToolExecutionContext::fromBackendUser()} is the one place
     * a live user is turned into a context, so the uid/admin/group extraction
     * is not written a second time.
     *
     * No user — a uid that no longer resolves, or no ambient operator — yields
     * the anonymous actor, which is a member of no group and holds no scope. A
     * restricted configuration is therefore refused for it, matching how the
     * tool gate treats the same case.
     */
    private function actorContext(?BackendUserAuthentication $user): AiActorContext
    {
        if (!$user instanceof BackendUserAuthentication) {
            return AiActorContext::anonymous();
        }

        return ToolExecutionContext::fromBackendUser($user)->actor;
    }

    /**
     * Who the answer is about.
     *
     * A uid that resolves to nothing — deleted, disabled, or removed between
     * loading the page and submitting it — yields an UNRESOLVED actor rather
     * than a silent fallback to the operator. The gates were asked with no
     * user, which is how the runtime fails closed (see
     * {@see ActingBackendUserResolver}), and the readout has to be able to say
     * so.
     */
    private function actor(int $actorUid, ?BackendUserAuthentication $user): SimulationActor
    {
        if (!$user instanceof BackendUserAuthentication) {
            return new SimulationActor(max($actorUid, 0), '', false, false);
        }

        return new SimulationActor(
            self::toInt($user->getUserId()),
            self::toStr($user->user['username'] ?? null),
            $user->isAdmin(),
        );
    }
}
