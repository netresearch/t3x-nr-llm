<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Resolves the globally-enabled tool set from each tool's default and the
 * admin overrides ({@see ToolStateRepository}).
 *
 * Extracted as an interface so the fail-closed gate in {@see ToolLoopService}
 * is unit-testable without a database, while the backend module consumes the
 * concrete {@see ToolAvailabilityService} (which needs the ConnectionPool).
 */
interface ToolAvailabilityServiceInterface
{
    /**
     * Names of the tools that are globally enabled right now (override, else
     * the tool's {@see ToolInterface::isEnabledByDefault()}).
     *
     * @return list<string>
     */
    public function enabledNames(): array;

    /**
     * Per-tool state rows for the management UI: name, description, group,
     * the effective enabled flag (group AND tool cascade), the tool-level
     * flag, the group-level flag, the tool default and whether an explicit
     * admin override is in effect.
     *
     * @return list<array{
     *     name: string,
     *     description: string,
     *     group: string,
     *     enabled: bool,
     *     toolEnabled: bool,
     *     groupEnabled: bool,
     *     defaultEnabled: bool,
     *     overridden: bool,
     * }>
     */
    public function states(): array;

    /**
     * Per-group state rows for the management UI, one per group of the
     * currently registered tools: name, the effective enabled flag (an
     * unknown / never-toggled group is enabled) and whether an explicit
     * admin override row exists.
     *
     * @return list<array{name: string, enabled: bool, overridden: bool}>
     */
    public function groupStates(): array;
}
