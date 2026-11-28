---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "MCP server"
description: "A registered remote Model Context Protocol server whose tools nr-llm imports."
status: draft
timestamp: 2026-11-27
---

# Definition

An MCP server is a remote server that speaks the Model Context Protocol (MCP). An administrator registers its URL and authentication. nr-llm imports the tools the server offers, and an agent run can then call them.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Service/Tool/Mcp/McpImportService.php` | `Classes/Service/Tool/Mcp/McpImportService.php` | `class McpImportService` | asserted |
| `implementedBy` | `Configuration/TCA/tx_nrllm_mcp_server.php` | `Configuration/TCA/tx_nrllm_mcp_server.php` | `'requires_approval' =>` | asserted |
| `verifiedBy` | `Tests/Functional/Service/Tool/Mcp/McpImportServiceTest.php` | `Tests/Functional/Service/Tool/Mcp/McpImportServiceTest.php` | `final class McpImportServiceTest extends AbstractFunctionalTestCase` | asserted |
