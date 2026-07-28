<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Lean test double for {@see ToolCallPolicyInterface} (ADR-118).
 *
 * {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService} requires its composite
 * tool gate since ADR-118 removed the optional-collaborator fallback. This fake
 * replicates that removed fallback byte-for-byte so lean test wirings keep the
 * exact pre-ADR-118 gating: the caller's allow-list intersected with the
 * globally-enabled set, admin-only tools filtered for non-admins (an unknown
 * tool fails closed), and — when a resolver is given — the per-configuration
 * skill/tool-group intersection. It evaluates no trust-zone axis, and
 * {@see self::explain()} reports nothing, exactly as the old fallback logged
 * and persisted nothing.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`, so production can never silently receive a
 * gate without the trust-zone axis. It is a fixture for test suites — usually
 * built by {@see ToolLoopBuilder} — never wire it into production.
 */
final readonly class NullToolCallPolicy implements ToolCallPolicyInterface
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolAvailabilityServiceInterface $availability,
        // Optional, matching the old fallback: a null resolver behaves exactly
        // like a wired resolver that resolves to "no skill-imposed restriction".
        private ?AllowedToolsResolver $allowedTools = null,
    ) {}

    public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision
    {
        // Neutral zone facts: this fake has no trust-zone axis (the old
        // fallback never evaluated one), so decisions carry the widest zone.
        $zone      = TrustZone::LOCAL;
        $ceiling   = $zone->maxDataClass();
        $dataClass = ToolDataClass::PUBLIC_CONTENT;

        $tool = $this->registry->get($toolName);
        if ($tool === null) {
            return new ToolPolicyDecision($toolName, false, $dataClass, $zone, $ceiling, ToolDenialReason::NOT_REGISTERED);
        }
        if (!in_array($toolName, $this->availability->enabledNames(), true)) {
            return new ToolPolicyDecision($toolName, false, $dataClass, $zone, $ceiling, ToolDenialReason::TOOL_DISABLED);
        }
        if ($tool->requiresAdmin() && ($user === null || !$user->isAdmin())) {
            return new ToolPolicyDecision($toolName, false, $dataClass, $zone, $ceiling, ToolDenialReason::REQUIRES_ADMIN);
        }
        $configurationAllowed = $this->allowedTools?->resolve($configuration);
        if ($configurationAllowed !== null && !in_array($toolName, $configurationAllowed, true)) {
            return new ToolPolicyDecision($toolName, false, $dataClass, $zone, $ceiling, ToolDenialReason::CONFIGURATION_GROUP);
        }

        return new ToolPolicyDecision($toolName, true, $dataClass, $zone, $ceiling);
    }

    /**
     * The pre-ADR-118 inline fallback gates of ToolLoopService, verbatim.
     */
    public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        // Fail-closed global gate: the effective allow-set is always intersected
        // with the globally-enabled tools. A null caller list means "no per-run
        // restriction" and collapses to the enabled set (NOT every registered
        // tool).
        $enabled   = $this->availability->enabledNames();
        $effective = $requested === null
            ? $enabled
            : array_values(array_intersect($requested, $enabled));

        // Fail-closed RBAC gate: admin-only tools are never offered unless the
        // acting backend user is an admin; an unknown tool name is treated as
        // admin-only (fail-closed), mirroring ToolExecutionContext::isAdmin().
        if ($user === null || !$user->isAdmin()) {
            $effective = array_values(array_filter(
                $effective,
                fn(string $name): bool => $this->registry->get($name)?->requiresAdmin() === false,
            ));
        }

        // Fail-closed per-configuration gate (ADR-093): the skills' declared
        // allow-list intersected with the configuration's allowed_tool_groups.
        $configurationAllowed = $this->allowedTools?->resolve($configuration);
        if ($configurationAllowed !== null) {
            $effective = array_values(array_intersect($effective, $configurationAllowed));
        }

        return $effective;
    }

    /**
     * No decisions: the old fallback logged and persisted nothing, so the lean
     * wiring must produce no gate log lines and no governance events either.
     */
    public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        return [];
    }
}
