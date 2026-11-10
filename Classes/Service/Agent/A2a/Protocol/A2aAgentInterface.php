<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * One `AgentInterface` of a card (proto `AgentInterface`).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAgentInterface
{
    public function __construct(
        public string $url,
        public string $protocolBinding,
        public string $protocolVersion,
        public string $tenant = '',
    ) {}

    /**
     * JSON-RPC binding at a 1.0 protocol version (§8.3.2, A2A-C2).
     */
    public function isUsable(): bool
    {
        return $this->protocolBinding === A2aProtocol::BINDING_JSONRPC
            && A2aProtocol::isSupportedVersion($this->protocolVersion);
    }

    public function describe(): string
    {
        return sprintf('%s %s', $this->protocolBinding, $this->protocolVersion);
    }
}
