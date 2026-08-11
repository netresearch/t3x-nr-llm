<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolGroup;
use Psr\Log\LoggerInterface;
use Throwable;

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
 *
 * {@see editorActions()} is the only method that calls into a tool's
 * human-facing declaration (ADR-152), and nothing on the tool-call path calls
 * it. That separation is deliberate: {@see enabledNames()} runs on every tool
 * call, and a third-party declaration is arbitrary foreign code — asking it for
 * an icon while deciding whether a call may proceed would let a malformed
 * catalogue entry abort a run.
 */
final readonly class ToolAvailabilityService implements ToolAvailabilityServiceInterface
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolStateRepository $stateRepository,
        private ToolGroupStateRepository $groupStateRepository,
        private ?LoggerInterface $logger = null,
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
            if (!$tool instanceof ToolInterface) {
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

    public function editorActions(): array
    {
        $actions = [];
        foreach ($this->registry->names() as $name) {
            $tool = $this->registry->get($name);
            if (!$tool instanceof EditorActionInterface) {
                continue;
            }

            try {
                $actions[$name] = $tool->getEditorAction();
            } catch (Throwable $e) {
                // A declaration is presentational, so a broken one costs its row
                // the icon and the translated name and nothing else: the tool
                // still renders under its wire name, still toggles, and still
                // runs. Blanking the whole module — or worse, the run — over a
                // catalogue entry would make the metadata load-bearing, which is
                // exactly what ADR-152 refused.
                $this->logger?->warning('Tool {tool} declares a malformed editor action; rendering it as an undeclared tool.', [
                    'tool'      => $name,
                    'exception' => $e,
                ]);
            }
        }

        return $actions;
    }

    public function groupStates(): array
    {
        $groupOverrides = $this->groupStateRepository->overrides();

        $groups = [];
        foreach ($this->registry->names() as $name) {
            $tool = $this->registry->get($name);
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $group = $tool->getGroup();
            if (isset($groups[$group])) {
                continue;
            }

            $groups[$group] = [
                'name'       => $group,
                // Null for a group outside the curated taxonomy (ADR-152); the
                // module then renders the raw identifier, which is what a
                // third-party group has instead of a name.
                'labelKey'   => ToolGroup::labelKeyFor($group),
                'enabled'    => $groupOverrides[$group] ?? true,
                'overridden' => array_key_exists($group, $groupOverrides),
            ];
        }

        ksort($groups);

        return array_values($groups);
    }
}
