<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Computes the effective global enable state of every registered tool.
 *
 * The effective state of a tool is its admin override when one exists
 * ({@see ToolStateRepository::overrides()}), otherwise its own
 * {@see ToolInterface::isEnabledByDefault()}. This is the authoritative
 * "what may run at all" set: {@see ToolLoopService} intersects every per-run
 * allow-list with {@see enabledNames()} so a globally-disabled tool can never
 * be called, and {@see ToolPlaygroundController} renders {@see states()} as the
 * module's toggle list.
 */
final readonly class ToolAvailabilityService implements ToolAvailabilityServiceInterface
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolStateRepository $stateRepository,
        private ToolGroupStateRepository $groupStateRepository,
    ) {}

    public function enabledNames(): array
    {
        $names = [];
        foreach ($this->states() as $state) {
            if ($state['enabled']) {
                $names[] = $state['name'];
            }
        }

        return $names;
    }

    public function states(): array
    {
        $overrides      = $this->stateRepository->overrides();
        $groupOverrides = $this->groupStateRepository->overrides();

        $states = [];
        foreach ($this->registry->names() as $name) {
            $tool = $this->registry->get($name);
            if ($tool === null) {
                continue;
            }

            $default    = $tool->isEnabledByDefault();
            $overridden = array_key_exists($name, $overrides);
            $group      = $tool->getGroup();
            // Unknown / never-toggled group => enabled (only an explicit
            // admin override disables a group).
            $groupEnabled = $groupOverrides[$group] ?? true;
            $toolEnabled  = $overridden ? $overrides[$name] : $default;

            $states[] = [
                'name'           => $name,
                'description'    => $tool->getSpec()->description,
                'group'          => $group,
                // Fail-closed cascade: a per-tool override can never
                // re-enable a tool inside a disabled group.
                'enabled'        => $groupEnabled && $toolEnabled,
                'toolEnabled'    => $toolEnabled,
                'groupEnabled'   => $groupEnabled,
                'defaultEnabled' => $default,
                'overridden'     => $overridden,
            ];
        }

        return $states;
    }

    public function groupStates(): array
    {
        $groupOverrides = $this->groupStateRepository->overrides();

        $groups = [];
        foreach ($this->registry->names() as $name) {
            $tool = $this->registry->get($name);
            if ($tool === null) {
                continue;
            }
            $group = $tool->getGroup();
            if (isset($groups[$group])) {
                continue;
            }
            $groups[$group] = [
                'name'       => $group,
                'enabled'    => $groupOverrides[$group] ?? true,
                'overridden' => array_key_exists($group, $groupOverrides),
            ];
        }

        ksort($groups);

        return array_values($groups);
    }
}
