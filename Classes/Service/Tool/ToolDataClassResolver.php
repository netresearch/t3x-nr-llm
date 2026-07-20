<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;

/**
 * Resolves the data class of a tool (ADR-094).
 *
 * Three sources, in order:
 *
 * 1. an explicit {@see ToolDataClassInterface} declaration on the tool;
 * 2. the default for its group — each group takes the class of its worst
 *    plausible member, so a new tool in a known group is never under-classified
 *    by omission;
 * 3. {@see ToolDataClass::SECRET_ADJACENT} for an unknown tool or an
 *    undeclared group — fail-closed, because an unclassified tool is exactly
 *    the case the classification exists to catch.
 */
final readonly class ToolDataClassResolver
{
    /**
     * Per-group defaults. A group is assigned the class of the most sensitive
     * output any of its members can plausibly return.
     *
     * `rag` is PUBLIC_CONTENT because every retrieval query is access-filtered
     * to publicly visible documents before it returns anything.
     *
     * @var array<string, ToolDataClass>
     */
    private const GROUP_DEFAULTS = [
        'content'       => ToolDataClass::EDITOR_CONTENT,
        'files'         => ToolDataClass::EDITOR_CONTENT,
        'rag'           => ToolDataClass::PUBLIC_CONTENT,
        'structure'     => ToolDataClass::INTERNAL_CONFIGURATION,
        'configuration' => ToolDataClass::INTERNAL_CONFIGURATION,
        'code'          => ToolDataClass::SOURCE_CODE,
        'system'        => ToolDataClass::SYSTEM_DIAGNOSTICS,
        'accounts'      => ToolDataClass::SECRET_ADJACENT,
    ];

    public function __construct(
        private ToolRegistry $registry,
    ) {}

    public function classForTool(ToolInterface $tool): ToolDataClass
    {
        if ($tool instanceof ToolDataClassInterface) {
            return $tool->getDataClass();
        }

        return self::GROUP_DEFAULTS[$tool->getGroup()] ?? ToolDataClass::SECRET_ADJACENT;
    }

    /**
     * The class of a tool by name; an unknown name resolves to the strictest
     * class so a stale allow-list entry cannot widen the gate.
     */
    public function classFor(string $toolName): ToolDataClass
    {
        $tool = $this->registry->get($toolName);

        return $tool === null ? ToolDataClass::SECRET_ADJACENT : $this->classForTool($tool);
    }

    /**
     * The declared default for a group, or null when the group is unknown.
     * Exposed for the coverage test that fails when a tool appears in a group
     * nobody classified.
     */
    public static function defaultForGroup(string $group): ?ToolDataClass
    {
        return self::GROUP_DEFAULTS[$group] ?? null;
    }
}
