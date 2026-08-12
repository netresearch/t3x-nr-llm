<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Conformance;

use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use Netresearch\NrLlm\Service\Tool\Mcp\McpTool;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\Conformance\McpConnectionProfile;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The conformance suite against a server that issues a session id, whose data
 * class sits above the ceiling of the outer trust zones and whose tools the
 * operator released from the approval requirement.
 */
#[CoversClass(McpClient::class)]
#[CoversClass(McpHttpTransport::class)]
#[CoversClass(McpSchemaNormalizer::class)]
#[CoversClass(McpTool::class)]
final class SessionHttpConformanceTest extends AbstractMcpConformanceTestCase
{
    protected function connection(): McpConnectionProfile
    {
        return McpConnectionProfile::sessionHttp();
    }
}
