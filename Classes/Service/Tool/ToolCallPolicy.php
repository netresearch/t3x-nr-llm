<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\LoadSkillTool;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The composite tool gate (ADR-094).
 *
 * Evaluation is a pure AND; the ORDER only decides which reason is reported,
 * and it runs cheapest and least revealing first. A tool that is both disabled
 * and above the trust-zone ceiling reports TOOL_DISABLED, so a denial message
 * never tells a caller who was already blocked that a trust-zone axis exists.
 *
 * The zone gate is governed by the `tools.dataClassEnforcement` extension
 * setting, which ships as `enforce` (see `ext_conf_template.txt`). Set to
 * `observe`, the decision is computed and reported but the tool is still
 * offered; any other value — including a missing or unreadable one — enforces.
 * The switch is read by
 * {@see DataClassEnforcementResolver}, which the governance readout (ADR-140)
 * asks as well so the view cannot drift from this gate. It is fail-closed
 * (ADR-113): only a deliberate `observe` observes; everything else enforces, so
 * a broken or mistyped setting cannot silently disable the gate. The four pre-existing
 * gates always enforce — the switch governs only the new axis, so turning it on
 * (or failing closed) cannot loosen anything.
 */
final readonly class ToolCallPolicy implements ToolCallPolicyInterface
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolAvailabilityServiceInterface $availability,
        private AllowedToolsResolver $allowedTools,
        private ToolDataClassResolver $dataClasses,
        private TrustZoneResolver $trustZones,
        private DataClassEnforcementResolver $enforcement,
    ) {}

    public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): ToolPolicyDecision
    {
        return $this->decideAgainst(
            $toolName,
            $configuration,
            $user,
            $this->availability->enabledNames(),
            $this->configurationAllowed($configuration, $runAllowList),
            $runAllowList instanceof SkillToolAllowList && $runAllowList->processBound,
            $this->excluded($configuration, $runAllowList),
        );
    }

    public function skillAllowListForRun(LlmConfiguration $configuration, array $forcedSkills = []): SkillToolAllowList
    {
        return $this->allowedTools->resolveForRun($configuration, $forcedSkills);
    }

    /**
     * Gate 4's list: the run's stored skill list with the live group gate on
     * top, or — for a caller with no run — the configuration-only resolution.
     *
     * The run's list is not re-derived from the configuration here. Doing so
     * left out the run's forced skills, so their declarations restricted
     * nothing at run time (ADR-038 item 5).
     *
     * @return list<string>|null
     */
    private function configurationAllowed(LlmConfiguration $configuration, ?SkillToolAllowList $runAllowList): ?array
    {
        return $runAllowList instanceof SkillToolAllowList
            ? $this->allowedTools->applyGroupGateTo($runAllowList, $configuration)
            : $this->allowedTools->resolve($configuration);
    }

    /**
     * The tools this decision refuses by name (ADR-261): what the
     * configuration excludes now, and what it excluded when the run started.
     *
     * The live list makes an exclusion added while a run waits apply at once;
     * the run's stored list keeps one removed meanwhile in force, so a change
     * can take a tool away but never give one back. A caller with no run has
     * the live list only.
     *
     * @return list<string>
     */
    private function excluded(LlmConfiguration $configuration, ?SkillToolAllowList $runAllowList): array
    {
        $excluded = $configuration->getExcludedToolsList();
        if (!$runAllowList instanceof SkillToolAllowList) {
            return $excluded;
        }

        return array_values(array_unique([...$excluded, ...$runAllowList->excludedToolNames]));
    }

    /**
     * The gate for one tool against inputs the caller resolved once.
     *
     * The enabled set and the configuration's allow-list do not depend on the
     * tool, and resolving the enabled set reads two tables; asking for them per
     * tool made explaining N tools cost 2N queries (ADR-201).
     *
     * @param list<string>      $enabledNames
     * @param list<string>|null $configurationAllowed
     * @param bool              $processBound         the run invokes a process skill: no load-tool exemption
     * @param list<string>      $excluded             the names the configuration excludes, live and at run start
     */
    private function decideAgainst(
        string $toolName,
        LlmConfiguration $configuration,
        ?BackendUserAuthentication $user,
        array $enabledNames,
        ?array $configurationAllowed,
        bool $processBound,
        array $excluded,
    ): ToolPolicyDecision {
        $zone    = $this->trustZones->zoneFor($configuration);
        $ceiling = $zone->maxDataClass();

        $tool = $this->registry->get($toolName);
        if (!$tool instanceof ToolInterface) {
            return $this->denial($toolName, ToolDataClass::SECRET_ADJACENT, $zone, $ceiling, ToolDenialReason::NOT_REGISTERED);
        }

        $dataClass = $this->dataClasses->classForTool($tool);

        if (!in_array($toolName, $enabledNames, true)) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::TOOL_DISABLED);
        }

        // Fail-closed: no user is not an admin.
        if ($tool->requiresAdmin() && (!$user instanceof BackendUserAuthentication || !$user->isAdmin())) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::REQUIRES_ADMIN);
        }

        // The skill load tool sits outside the skill allow-list and the group
        // gate (ADR-214 item 5): a skill declaring `allowed-tools: []` blocks
        // every other tool but not loading. Keyed on the builtin class, not the
        // name, so no other tool can claim the exemption. The global state
        // above and the trust zone below still apply. A process-bound run
        // gets no exemption: there the load tool is offered only where the
        // vouched declaration lists it.
        $exempt = $tool instanceof LoadSkillTool && !$processBound;
        if ($configurationAllowed !== null && !$exempt && !in_array($toolName, $configurationAllowed, true)) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::CONFIGURATION_GROUP);
        }

        // A tool the configuration excludes by name (ADR-261). It only takes a
        // tool away, so it comes after every gate that can refuse on its own
        // and before the trust zone, whose observe mode would otherwise offer
        // it. The load tool gets no exemption here: naming it is a decision
        // about that very tool.
        if (in_array($toolName, $excluded, true)) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::CONFIGURATION_EXCLUDED);
        }

        if (!$zone->permits($dataClass)) {
            // A remote tool never benefits from observe mode. Observe exists so
            // an UPGRADE does not silently start dropping tools that already
            // worked (ADR-115); no remote tool worked before, so there is
            // nothing to preserve, and an upgraded install must not end up more
            // permissive than a fresh one.
            $enforcing = $this->enforcement->enforcing() || $tool instanceof RemoteToolInterface;

            return new ToolPolicyDecision(
                $toolName,
                // In observe mode the tool is still offered; the decision is
                // recorded so the operator can see what enforcement would do.
                !$enforcing,
                $dataClass,
                $zone,
                $ceiling,
                ToolDenialReason::TRUST_ZONE,
                !$enforcing,
            );
        }

        return new ToolPolicyDecision($toolName, true, $dataClass, $zone, $ceiling);
    }

    public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array
    {
        $offerable = [];
        foreach ($this->explain($requested, $configuration, $user, $runAllowList) as $decision) {
            if ($decision->allowed) {
                $offerable[] = $decision->toolName;
            }
        }

        return $offerable;
    }

    public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user, ?SkillToolAllowList $runAllowList = null): array
    {
        $enabled              = $this->availability->enabledNames();
        $configurationAllowed = $this->configurationAllowed($configuration, $runAllowList);
        $excluded             = $this->excluded($configuration, $runAllowList);

        return array_values(array_map(
            fn (string $name): ToolPolicyDecision => $this->decideAgainst($name, $configuration, $user, $enabled, $configurationAllowed, $runAllowList instanceof SkillToolAllowList && $runAllowList->processBound, $excluded),
            $requested ?? $enabled,
        ));
    }

    private function denial(
        string $toolName,
        ToolDataClass $dataClass,
        TrustZone $zone,
        ToolDataClass $ceiling,
        ToolDenialReason $reason,
    ): ToolPolicyDecision {
        return new ToolPolicyDecision($toolName, false, $dataClass, $zone, $ceiling, $reason);
    }
}
