<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;

/**
 * Which active configuration excludes which tool (ADR-261), for the Tools
 * module and the governance readout of the overview module.
 *
 * It reads the same field the gate reads, {@see LlmConfiguration::getExcludedToolsList()},
 * and decides nothing: whether a tool is offered is the gate's answer
 * ({@see ToolCallPolicyInterface}), and the simulation on the governance page
 * asks the gate itself. An inactive configuration runs nothing and is left
 * out, so the readout lists only exclusions that can take effect.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ToolExclusionReadout
{
    public function __construct(
        private LlmConfigurationRepository $configurations,
    ) {}

    /**
     * Every active configuration that excludes at least one tool, with the
     * names it excludes in the order they are stored.
     *
     * @return list<array{identifier: string, name: string, tools: list<string>}>
     */
    public function byConfiguration(): array
    {
        $rows = [];
        foreach ($this->configurations->findActive() as $configuration) {
            if (!$configuration instanceof LlmConfiguration) {
                continue;
            }

            $tools = $configuration->getExcludedToolsList();
            if ($tools === []) {
                continue;
            }

            $rows[] = [
                'identifier' => $configuration->getIdentifier(),
                'name'       => $configuration->getName(),
                'tools'      => $tools,
            ];
        }

        return $rows;
    }

    /**
     * The same answer keyed by tool name: the configurations that exclude it.
     *
     * @return array<string, list<array{identifier: string, name: string}>>
     */
    public function byTool(): array
    {
        $byTool = [];
        foreach ($this->byConfiguration() as $row) {
            foreach ($row['tools'] as $tool) {
                $byTool[$tool][] = ['identifier' => $row['identifier'], 'name' => $row['name']];
            }
        }

        return $byTool;
    }
}
