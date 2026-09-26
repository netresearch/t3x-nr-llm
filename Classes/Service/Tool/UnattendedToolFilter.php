<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Asks {@see ToolApprovalRule} about the tool the registry holds under each
 * name (ADR-210). The rule stays the only place that decides approval; this
 * class only applies it to a list.
 *
 * An unknown name is left out: it cannot be shown to be free of approval, and
 * offering it would offer the model a call that suspends.
 */
final readonly class UnattendedToolFilter implements UnattendedToolFilterInterface
{
    public function __construct(
        private ToolRegistry $registry,
    ) {}

    public function unattended(array $toolNames): array
    {
        $unattended = [];
        foreach ($toolNames as $name) {
            $tool = $this->registry->get($name);
            // An input-gated tool suspends the run as surely as an approval does.
            if ($tool instanceof ToolInterface
                && !$tool instanceof RequiresInputInterface
                && !ToolApprovalRule::requiresApproval($tool)
            ) {
                $unattended[] = $name;
            }
        }

        return $unattended;
    }
}
