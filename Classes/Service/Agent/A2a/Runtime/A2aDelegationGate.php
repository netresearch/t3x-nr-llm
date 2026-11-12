<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Closure;
use Throwable;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Whether this installation may offer delegation tools at all (spec 051
 * A2A-R2, ADR-281).
 *
 * nr_mcp_agent reads the run status, sets no `origin` on the runs of its chat
 * and, at its `main` 8e301436, marks a conversation failed for any status it
 * does not know. Its chat runs cannot be told apart from other runs, so the
 * rule is per installation: while nr_mcp_agent is loaded in a version below
 * {@see self::FIRST_COMPATIBLE_MCP_AGENT}, no run is offered a delegation
 * tool.
 *
 * No nr_mcp_agent release handles WAITING_FOR_DEPENDENCY yet, so the constant
 * is null and every loaded version withholds the tools. The release that
 * ships the handling sets it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDelegationGate
{
    public const MCP_AGENT_EXTENSION = 'nr_mcp_agent';

    /**
     * The first nr_mcp_agent version that understands WAITING_FOR_DEPENDENCY;
     * null while no such release exists.
     */
    public const FIRST_COMPATIBLE_MCP_AGENT = null;

    /** @var Closure(): ?string */
    private Closure $mcpAgentVersion;

    /**
     * @param (Closure(): ?string)|null $mcpAgentVersion the loaded nr_mcp_agent version, null when it is not loaded; tests pass one
     */
    public function __construct(?PackageManager $packages = null, ?Closure $mcpAgentVersion = null)
    {
        $this->mcpAgentVersion = $mcpAgentVersion ?? static function () use ($packages): ?string {
            try {
                if (!$packages instanceof PackageManager || !$packages->isPackageActive(self::MCP_AGENT_EXTENSION)) {
                    return null;
                }

                return (string) $packages->getPackage(self::MCP_AGENT_EXTENSION)->getPackageMetaData()->getVersion();
            } catch (Throwable) {
                // A package that cannot be read is treated as loaded in an
                // unknown version: the gate stays closed.
                return '';
            }
        };
    }

    /**
     * Why no delegation tool is offered, or null when they may be.
     */
    public function refusal(): ?string
    {
        $version = ($this->mcpAgentVersion)();

        return self::refusalFor($version, self::FIRST_COMPATIBLE_MCP_AGENT);
    }

    /**
     * The rule itself, without the package lookup.
     */
    public static function refusalFor(?string $loadedMcpAgentVersion, ?string $firstCompatible): ?string
    {
        if ($loadedMcpAgentVersion === null) {
            return null;
        }

        if ($firstCompatible !== null && $loadedMcpAgentVersion !== '' && version_compare($loadedMcpAgentVersion, $firstCompatible, '>=')) {
            return null;
        }

        return sprintf(
            'nr_mcp_agent %s is installed and does not yet understand a run that waits for a remote agent; %s',
            $loadedMcpAgentVersion !== '' ? $loadedMcpAgentVersion : '(unknown version)',
            $firstCompatible !== null
                ? sprintf('delegation tools are offered from nr_mcp_agent %s on', $firstCompatible)
                : 'no nr_mcp_agent release does yet, so delegation tools are not offered on this installation',
        );
    }
}
