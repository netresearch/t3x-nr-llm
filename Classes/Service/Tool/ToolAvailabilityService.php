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
        $overrides = $this->stateRepository->overrides();

        $states = [];
        foreach ($this->registry->names() as $name) {
            $tool = $this->registry->get($name);
            if ($tool === null) {
                continue;
            }

            $default    = $tool->isEnabledByDefault();
            $overridden = array_key_exists($name, $overrides);

            $states[] = [
                'name'           => $name,
                'description'    => $tool->getSpec()->description,
                'enabled'        => $overridden ? $overrides[$name] : $default,
                'defaultEnabled' => $default,
                'overridden'     => $overridden,
            ];
        }

        return $states;
    }
}
