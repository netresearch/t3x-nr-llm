<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What the governance simulation concludes across every axis it asked
 * (ADR-157).
 *
 * Three outcomes, because "allowed" alone would hide the one an operator most
 * needs to plan for: a call that runs only after a human says yes is not the
 * same as one that runs unattended, and reporting both as ALLOW would make the
 * approval axis invisible at exactly the moment it decides.
 *
 * @internal
 */
enum SimulationVerdict: string
{
    /**
     * Every axis permits the call and no human decision is required.
     */
    case ALLOW = 'allow';

    /**
     * Every axis permits the call, and a human must approve before it runs.
     */
    case ALLOW_WITH_APPROVAL = 'allowWithApproval';

    /**
     * At least one axis refuses. The readout names which.
     */
    case BLOCK = 'block';

    /**
     * The label an operator reads instead of the wire value.
     *
     * Named `get…` because Fluid reaches a method only through the get/is/has
     * convention — see {@see RoutingPolicyMode::getLabelKey()}.
     */
    public function getLabelKey(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.simulator.verdict.' . $this->name;
    }

    /**
     * The `f:be.infobox` state this verdict renders as: 0 OK, 1 INFO, 2 ERROR.
     *
     * Computed here rather than as a chain of Fluid conditionals, for the same
     * reason the candidate tables are flattened in the controller.
     */
    public function getInfoboxState(): int
    {
        return match ($this) {
            self::ALLOW               => 0,
            self::ALLOW_WITH_APPROVAL => 1,
            self::BLOCK               => 2,
        };
    }
}
