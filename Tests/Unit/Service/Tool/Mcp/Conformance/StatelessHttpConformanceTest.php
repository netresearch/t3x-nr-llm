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
 * The conformance suite against a server that issues no session, whose data
 * class is the least sensitive one and whose tools need an approval.
 */
#[CoversClass(McpClient::class)]
#[CoversClass(McpHttpTransport::class)]
#[CoversClass(McpSchemaNormalizer::class)]
#[CoversClass(McpTool::class)]
final class StatelessHttpConformanceTest extends AbstractMcpConformanceTestCase
{
    protected function connection(): McpConnectionProfile
    {
        return McpConnectionProfile::statelessHttp();
    }
}
