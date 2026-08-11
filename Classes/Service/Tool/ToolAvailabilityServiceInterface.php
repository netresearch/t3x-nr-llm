<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolGroup;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;

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
     * Per-tool state rows for the management UI: name, the MODEL-facing
     * description, group, the effective enabled flag (group AND tool cascade),
     * the tool-level flag, the group-level flag, the tool default and whether
     * an explicit admin override is in effect.
     *
     * Deliberately WITHOUT the human-facing declaration: {@see enabledNames()}
     * is derived from these rows and runs on every tool call, so this method
     * must not call into a tool beyond the constant flags above. The
     * declaration has its own method, {@see editorActions()}.
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
     * The human-facing {@see EditorAction} declarations (ADR-152), keyed by
     * tool name — one entry per registered tool that implements
     * {@see EditorActionInterface} and produces a valid declaration.
     *
     * A separate method rather than a column of {@see states()}, because this
     * is the only place foreign declaration code is executed and no runtime
     * gate may depend on it. A tool whose declaration throws is simply absent
     * from the result, so the module renders it like an undeclared tool.
     *
     * @return array<string, EditorAction>
     */
    public function editorActions(): array;

    /**
     * Per-group state rows for the management UI, one per group of the
     * currently registered tools: name, the `LLL:` key of its human label
     * (null outside the curated {@see ToolGroup} taxonomy), the effective
     * enabled flag (an unknown / never-toggled group is enabled) and whether
     * an explicit admin override row exists.
     *
     * @return list<array{name: string, labelKey: string|null, enabled: bool, overridden: bool}>
     */
    public function groupStates(): array;
}
