---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "MCP server"
description: "A registered remote Model Context Protocol server whose tools nr-llm imports."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

An MCP server is a remote server that speaks the Model Context Protocol (MCP). An administrator registers its URL and authentication. nr-llm imports the tools the server offers, and an agent run can then call them.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Service/Tool/Mcp/McpImportService.php` | `Classes/Service/Tool/Mcp/McpImportService.php` | `class McpImportService` | asserted |
| `implementedBy` | `Configuration/TCA/tx_nrllm_mcp_server.php` | `Configuration/TCA/tx_nrllm_mcp_server.php` | `'requires_approval' =>` | asserted |
