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
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The composite tool gate (ADR-094).
 *
 * Evaluation is a pure AND; the ORDER only decides which reason is reported,
 * and it runs cheapest and least revealing first. A tool that is both disabled
 * and above the trust-zone ceiling reports TOOL_DISABLED, so a denial message
 * never tells a caller who was already blocked that a trust-zone axis exists.
 *
 * The zone gate ships in **observe** mode via the `tools.dataClassEnforcement`
 * extension setting: the decision is computed and reported, but the tool is
 * still offered. An operator sets it to any non-`observe` value (or the setting
 * is missing/unreadable) to enforce. The switch is read by
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

    public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision
    {
        $zone    = $this->trustZones->zoneFor($configuration);
        $ceiling = $zone->maxDataClass();

        $tool = $this->registry->get($toolName);
        if (!$tool instanceof ToolInterface) {
            return $this->denial($toolName, ToolDataClass::SECRET_ADJACENT, $zone, $ceiling, ToolDenialReason::NOT_REGISTERED);
        }

        $dataClass = $this->dataClasses->classForTool($tool);

        if (!in_array($toolName, $this->availability->enabledNames(), true)) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::TOOL_DISABLED);
        }

        // Fail-closed: no user is not an admin.
        if ($tool->requiresAdmin() && (!$user instanceof BackendUserAuthentication || !$user->isAdmin())) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::REQUIRES_ADMIN);
        }

        $configurationAllowed = $this->allowedTools->resolve($configuration);
        if ($configurationAllowed !== null && !in_array($toolName, $configurationAllowed, true)) {
            return $this->denial($toolName, $dataClass, $zone, $ceiling, ToolDenialReason::CONFIGURATION_GROUP);
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

    public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        $candidates = $requested ?? $this->availability->enabledNames();

        $offerable = [];
        foreach ($candidates as $name) {
            if ($this->decide($name, $configuration, $user)->allowed) {
                $offerable[] = $name;
            }
        }

        return $offerable;
    }

    public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        $candidates = $requested ?? $this->availability->enabledNames();

        return array_values(array_map(
            fn(string $name): ToolPolicyDecision => $this->decide($name, $configuration, $user),
            $candidates,
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
