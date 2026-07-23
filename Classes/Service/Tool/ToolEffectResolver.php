<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;

/**
 * Resolves the side effect of a tool (ADR-111).
 *
 * Two sources, in order:
 *
 * 1. an explicit {@see ToolEffectInterface} declaration on the tool;
 * 2. {@see ToolEffect::READ_ONLY} for any tool that does not declare one —
 *    correct for every builtin shipped today, and the reason a write MUST opt
 *    in rather than be inferred.
 *
 * Resolution BY NAME is stricter: an unknown tool name resolves to
 * {@see ToolEffect::NON_IDEMPOTENT_WRITE} — the class that is both
 * audit-critical AND never auto-retried — so a stale or removed tool referenced
 * in a persisted step is treated as the most dangerous possibility, never
 * waved through as a repeatable read.
 */
final readonly class ToolEffectResolver
{
    public function __construct(
        private ToolRegistry $registry,
    ) {}

    public function effectForTool(ToolInterface $tool): ToolEffect
    {
        if ($tool instanceof ToolEffectInterface) {
            return $tool->getEffect();
        }

        return ToolEffect::READ_ONLY;
    }

    /**
     * The effect of a tool by name; an unknown name resolves fail-closed to the
     * strictest effect so a stale reference cannot dodge the audit/retry guards.
     */
    public function effectFor(string $toolName): ToolEffect
    {
        $tool = $this->registry->get($toolName);

        return $tool === null ? ToolEffect::NON_IDEMPOTENT_WRITE : $this->effectForTool($tool);
    }
}
