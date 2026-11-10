<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * A validated `AgentCard` (proto `AgentCard`, A2A-P5).
 *
 * Only what the client reads is modelled; the card as received is kept by
 * the importer as JSON, unknown members included.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAgentCard
{
    /**
     * @param list<A2aAgentInterface> $supportedInterfaces
     * @param list<string>            $defaultInputModes
     * @param list<string>            $defaultOutputModes
     * @param list<A2aAgentSkill>     $skills
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $version,
        public array $supportedInterfaces,
        public A2aAgentCapabilities $capabilities,
        public array $defaultInputModes,
        public array $defaultOutputModes,
        public array $skills,
    ) {}

    /**
     * The first JSON-RPC 1.0 interface in the card's order (§8.3.2: the
     * first entry is preferred), or null when the card offers none (A2A-C2).
     */
    public function selectInterface(): ?A2aAgentInterface
    {
        foreach ($this->supportedInterfaces as $interface) {
            if ($interface->isUsable()) {
                return $interface;
            }
        }

        return null;
    }

    /**
     * "JSONRPC 0.3, GRPC 1.0": what the card offers, for a refusal.
     */
    public function describeInterfaces(): string
    {
        return implode(', ', array_map(
            static fn (A2aAgentInterface $interface): string => $interface->describe(),
            $this->supportedInterfaces,
        ));
    }
}
