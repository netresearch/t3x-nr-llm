<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp\Conformance;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;

/**
 * One MCP connection the conformance suite holds the client to (ADR-161).
 *
 * A scenario is the pair of things that legitimately differ between two
 * servers this client speaks to: what the OPERATOR configured (identifier, data
 * class, approval) and what the SERVER does with the session. Everything else —
 * the responses, the failures, the sizes — is scripted identically by
 * {@see \Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Conformance\AbstractMcpConformanceTestCase},
 * because a check that varied with the scenario would not be a conformance
 * check.
 *
 * There is deliberately no authenticated profile. Authentication happens in
 * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport::clientFor()},
 * which the PSR-18 test seam bypasses by design, so an "authenticated" scenario
 * would send exactly the same bytes as the anonymous one and assert nothing
 * about the credential. The timeout check reaches that method directly instead.
 */
final readonly class McpConnectionProfile
{
    /**
     * @param string|null $sessionId the id the server issues in its initialize
     *                               reply, or null for a stateless server that issues none
     */
    private function __construct(
        public string $identifier,
        public ?string $sessionId,
        public ToolDataClass $dataClass,
        public bool $requiresApproval,
    ) {}

    /**
     * A server that issues no session: every request stands alone, and the
     * client must not invent a session header the server never gave it.
     */
    public static function statelessHttp(): self
    {
        return new self(
            identifier: 'stateless',
            sessionId: null,
            dataClass: ToolDataClass::PUBLIC_CONTENT,
            requiresApproval: true,
        );
    }

    /**
     * A server that issues a session id and expects it back on every following
     * request of the same operation — including the readiness notification.
     */
    public static function sessionHttp(): self
    {
        return new self(
            identifier: 'sessioned',
            sessionId: 'sess-conformance',
            dataClass: ToolDataClass::INTERNAL_CONFIGURATION,
            requiresApproval: false,
        );
    }

    /**
     * The operator-configured row this connection is reached through.
     */
    public function server(): McpServerRecord
    {
        return McpTestServer::server(
            $this->identifier,
            $this->dataClass->value,
            $this->requiresApproval ? '1' : '0',
        );
    }

    /**
     * A scripted server that will answer the handshake the way this profile
     * says, and nothing beyond it. The caller queues what the operation needs.
     */
    public function scriptedServer(): McpTestServer
    {
        return (new McpTestServer())->willHandshake($this->sessionId);
    }

    /**
     * What the `Mcp-Session-Id` header must read on every request AFTER the
     * handshake. A stateless server issued none, so the header is absent, which
     * the fake reports as an empty string.
     */
    public function expectedSessionHeader(): string
    {
        return $this->sessionId ?? '';
    }
}
