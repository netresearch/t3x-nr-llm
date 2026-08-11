<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Whether a human must approve this tool before it executes (ADR-084/134).
 *
 * The rule had two copies. {@see ToolLoopService} owned it as a private
 * predicate, {@see ToolRegistry} restated it as an inline `instanceof` chain in
 * its boot validation, and the comment there had to ASK a future reader to
 * narrow both together. The copies had already drifted: the registry exempted
 * every {@see RemoteToolInterface}, including one carrying a
 * {@see RemoteApprovalInterface} declaration that the loop honours — so a
 * remote tool the loop would suspend for approval was still registrable
 * alongside {@see RequiresInputInterface}, which is the deadlock ADR-134's
 * check exists to prevent.
 *
 * One rule, three callers: the loop's approval scan, the registry's boot
 * validation, and the Governance simulator (ADR-157), which reports the
 * approval requirement as its own axis.
 *
 * Pure and stateless — a static predicate over the tool the caller already
 * holds, no resolver, no repository, no dependency. Deliberately NOT
 * {@see ToolEffectResolver}: that one answers registry-wide by name and falls
 * back to NON_IDEMPOTENT_WRITE for an unknown, which would turn every
 * unregistered name into a suspend.
 *
 * @internal
 */
final class ToolApprovalRule
{
    /**
     * Two ways in, and the second is what ADR-134 adds: the explicit
     * {@see RequiresApprovalInterface} marker, or a declared write effect. A
     * builtin whose {@see \Netresearch\NrLlm\Domain\Enum\ToolEffect} is a write
     * is describing a change to the installation that the operator cannot undo
     * by reading the transcript, so it must not run unattended merely because
     * nobody remembered the marker. The declaration is a property of the code
     * (ADR-111) and cannot be relabelled by configuration, which is what makes
     * it usable as an authorisation input.
     *
     * A remote tool is NOT judged on its effect, and that exemption is
     * load-bearing rather than convenient.
     * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpTool} returns
     * NON_IDEMPOTENT_WRITE for EVERY imported tool, a pure search included: a
     * remote body is not ours to inspect, so the value is a fail-closed
     * assumption about an unknown, not the tool's statement about itself.
     * Treating it as one would suspend every MCP tool on every call and leave
     * the shipped client unusable.
     *
     * What a remote tool IS judged on is {@see RemoteApprovalInterface}: the
     * operator's declaration on the server row, carried in by the provider that
     * built the tool. It is asked before the exemption, so the exemption now
     * covers only a remote tool that carries NO declaration — a third-party
     * {@see RemoteToolInterface} outside the MCP client, which is exactly where
     * this codebase still knows nothing. The declaration will never come from
     * the server: the `readOnlyHint` annotation is recorded verbatim and read by
     * no resolver, because a remote server must not influence its own
     * authorisation
     * (see {@see \Netresearch\NrLlm\Domain\ValueObject\McpToolRecord}).
     */
    public static function requiresApproval(?ToolInterface $tool): bool
    {
        if ($tool instanceof RequiresApprovalInterface) {
            return true;
        }

        if ($tool instanceof RemoteApprovalInterface) {
            return $tool->requiresApproval();
        }

        if ($tool instanceof RemoteToolInterface) {
            return false;
        }

        return $tool instanceof ToolEffectInterface && $tool->getEffect()->isWrite();
    }
}
