<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Agent\AgentRunCancellationSignalFactory;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolProviderInterface;

/**
 * Turns the imported catalogue into registered tools (ADR-116).
 *
 * The one place {@see McpTool} instances are made. Reads two tables and builds
 * objects; it performs no network I/O, which the provider contract requires —
 * this runs whenever the registry is first used, including on backend pages
 * that have nothing to do with an agent run.
 *
 * Two silent-drop rules, both fail-closed:
 *
 * - A server whose data class the operator has not declared supplies nothing.
 *   {@see McpServerRepository::findUsable()} filters it out, so an undeclared
 *   server is inert rather than defaulted.
 * - A catalogue row whose stored schema no longer decodes to a JSON object
 *   supplies nothing. A tool without a parameter schema cannot be offered to a
 *   provider, and inventing an empty one would advertise a signature the remote
 *   tool does not have.
 */
final readonly class McpToolProvider implements ToolProviderInterface
{
    public function __construct(
        private McpServerRepository $servers,
        private McpToolRepository $tools,
        private McpClient $client,
        private AgentRunCancellationSignalFactory $cancellations,
    ) {}

    /**
     * @return iterable<ToolInterface>
     */
    public function tools(): iterable
    {
        foreach ($this->servers->findUsable() as $server) {
            $dataClass = $server->dataClassEnum();
            if ($dataClass === null) {
                // findUsable() already excluded these; re-checked because the
                // null is what makes the McpTool construction below type-safe,
                // and a filter three calls away is a poor place to rely on it.
                continue;
            }

            foreach ($this->tools->findLiveByServer($server->uid) as $record) {
                $schema = $record->inputSchemaArray();
                if ($schema === null) {
                    continue;
                }

                // The approval flag is resolved here, from the server row this
                // loop already holds, rather than looked up later by the
                // approval scan: the scan runs per tool call and adding a
                // repository to it would put a query on that path.
                yield new McpTool($server, $record, $schema, $dataClass, $server->approvalRequired(), $this->client, $this->cancellations);
            }
        }
    }
}
