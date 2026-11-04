<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * How many model rounds an agent run may take (ADR-101, amendment of
 * 2026-11-04).
 *
 * Both numbers are extension settings, read on every call, so a changed
 * value applies to the next run and to the next segment of a run that
 * resumes after an approval or an input. A round cap applies per segment:
 * a run that resumes gets the cap again, not what was left of it.
 *
 * @api
 */
interface AgentRoundLimitsInterface
{
    /**
     * The rounds a run gets when its caller asks for none. Never above
     * {@see self::ceiling()}.
     */
    public function defaultRounds(): int;

    /**
     * The most rounds any run may take, whatever its caller asks for.
     */
    public function ceiling(): int;

    /**
     * The rounds a run gets that asked for $requested: the request clamped to
     * the ceiling, or the default when it asked for none or for less than one.
     * The runtime applies exactly this rule, unless a service definition gives
     * the tool loop a default of its own, which then replaces the default here.
     */
    public function roundsFor(?int $requested): int;
}
