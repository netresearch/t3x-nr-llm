<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Service\UseCase\PackEditorActionState;
use Netresearch\NrLlm\Service\UseCase\PackToolGroupState;
use Netresearch\NrLlm\Service\UseCase\PackToolReadinessInterface;

/**
 * The tool module's answer to what a use-case pack recommends (ADR-168).
 *
 * Everything here is read off {@see ToolAvailabilityServiceInterface}, which is
 * already the one place that resolves "which tools are registered", "which
 * declare an editor action" and "what is enabled after the group AND tool
 * cascade". No second registry and no second enabled-check: the cascade is a
 * five-part rule with copies already, and this would have been the next one.
 *
 * The per-viewer question — would THIS backend user be offered the action right
 * now — is {@see EditorActionCatalogueInterface::groupsFor()} and is
 * deliberately not asked here. It answers with an absence, and an absence cannot
 * tell a typo from a disabled tool from a missing default configuration. The
 * plan screen is an administrator's, and the three need to be distinguishable.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class PackToolReadiness implements PackToolReadinessInterface
{
    public function __construct(
        private ToolAvailabilityServiceInterface $availability,
    ) {}

    public function editorActionStates(array $toolNames): array
    {
        if ($toolNames === []) {
            return [];
        }

        $declarations = $this->availability->editorActions();

        $states = [];
        foreach ($this->availability->states() as $state) {
            $states[$state['name']] = $state;
        }

        $result = [];
        foreach ($toolNames as $toolName) {
            $declaration = $declarations[$toolName] ?? null;
            $state       = $states[$toolName] ?? null;

            $result[] = new PackEditorActionState(
                toolName: $toolName,
                // A registered tool that declares NO editor action counts as
                // undeclared here: the pack named it as an editor action, and
                // as one it does not exist. A tool whose declaration throws is
                // already absent from editorActions() for the same reason
                // (ADR-152) — the module renders it as an undeclared tool.
                declared: $declaration !== null,
                enabled: $declaration !== null && ($state['enabled'] ?? false),
                // Gated on the declaration for the same reason `enabled` is.
                // `get_page` is registered and carries a group, but it is not
                // an editor action — printing its group next to a "Not
                // available here" badge would point the operator at a switch
                // that exists and would not produce the action.
                group: $declaration !== null ? ($state['group'] ?? '') : '',
                declaration: $declaration,
            );
        }

        return $result;
    }

    public function toolGroupStates(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $known = [];
        foreach ($this->availability->groupStates() as $state) {
            $known[$state['name']] = $state;
        }

        $result = [];
        foreach ($groups as $group) {
            $state = $known[$group] ?? null;

            $result[] = new PackToolGroupState(
                group: $group,
                // groupStates() lists the groups of the currently REGISTERED
                // tools. A group missing from it has no switch in the Tools
                // module, so recommending it points at nothing.
                registered: $state !== null,
                enabled: $state['enabled'] ?? false,
                labelKey: $state['labelKey'] ?? null,
            );
        }

        return $result;
    }
}
