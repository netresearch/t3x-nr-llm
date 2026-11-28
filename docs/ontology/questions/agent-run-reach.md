---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "What an agent run depends on"
description: "Which concepts decide what an agent run can use and may do?"
x-nr-query: --needs concepts/agent-run
status: draft
timestamp: 2026-11-27
---

# Question

What decides what an agent run can use and may do?

# How to answer

Follow the relations forwards from the agent run: what it uses, and what constrains it. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --needs concepts/agent-run
```

# Answer at the time of writing

```text
concepts/agent-run
concepts/configuration
  via concepts/agent-run uses concepts/configuration
  test Tests/Unit/Domain/Model/LlmConfigurationTest.php
  test Tests/Unit/Domain/DTO/FallbackChainTest.php
  test Tests/Unit/Service/ModelSelectionServiceTest.php
concepts/backend-user
  via concepts/agent-run constrainedBy concepts/backend-user
concepts/llm-service
  via concepts/agent-run uses concepts/llm-service
  test Tests/Unit/Service/LlmServiceManagerTest.php
concepts/tool
  via concepts/agent-run uses concepts/tool
concepts/tool-enable-state
  via concepts/agent-run constrainedBy concepts/tool-enable-state
  test Tests/Functional/Service/Tool/ToolAvailabilityServiceTest.php
concepts/skill
  via concepts/agent-run constrainedBy concepts/skill
  test Tests/Unit/Domain/Model/SkillAllowedToolsTest.php
concepts/model
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/guardrail
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration constrainedBy concepts/guardrail
  test Tests/Unit/Service/Guardrail/GuardrailPolicyResolverTest.php
concepts/term-list
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration constrainedBy concepts/term-list
  test Tests/Unit/Service/Guardrail/Term/TermListGuardrailTest.php
concepts/backend-user-group
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration constrainedBy concepts/backend-user-group
concepts/tool-group
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
  test Tests/Unit/Domain/Enum/ToolGroupTest.php
concepts/prompt-snippet
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/prompt-snippet
  test Tests/Unit/Domain/Model/PromptSnippetTest.php
concepts/configuration-preset
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration derivedFrom concepts/configuration-preset
  test Tests/Unit/Service/Preset/ConfigurationPresetImportServiceTest.php
concepts/usage-record
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/usage-record
  test Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php
concepts/user-budget
  via concepts/agent-run constrainedBy concepts/backend-user
  via concepts/backend-user constrainedBy concepts/user-budget
  test Tests/Unit/Service/BudgetServiceTest.php
concepts/middleware-pipeline
  via concepts/agent-run uses concepts/llm-service
  via concepts/llm-service uses concepts/middleware-pipeline
  test Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php
  test Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php
concepts/streaming-dispatcher
  via concepts/agent-run uses concepts/llm-service
  via concepts/llm-service uses concepts/streaming-dispatcher
  test Tests/Unit/Service/Streaming/StreamingDispatcherTest.php
concepts/glossary
  via concepts/agent-run uses concepts/llm-service
  via concepts/llm-service uses concepts/glossary
  test Tests/Functional/Domain/GlossaryStorageTest.php
  test Tests/Unit/Service/Glossary/GlossaryResolverTest.php
concepts/site-llm-settings
  via concepts/agent-run uses concepts/llm-service
  via concepts/llm-service uses concepts/site-llm-settings
  test Tests/Unit/Service/Site/SiteLlmSettingsTest.php
  test Tests/Unit/Service/ConfigurationResolverSiteTest.php
concepts/mcp-server
  via concepts/agent-run uses concepts/tool
  via concepts/tool derivedFrom concepts/mcp-server
  test Tests/Functional/Service/Tool/Mcp/McpImportServiceTest.php
concepts/skill-source
  via concepts/agent-run constrainedBy concepts/skill
  via concepts/skill derivedFrom concepts/skill-source
concepts/skill-approval
  via concepts/agent-run constrainedBy concepts/skill
  via concepts/skill constrainedBy concepts/skill-approval
concepts/provider
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  test Tests/Unit/Domain/Model/ProviderTest.php
concepts/provider-adapter
  via concepts/agent-run uses concepts/llm-service
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline uses concepts/provider-adapter (hypothesis)
  test Tests/Unit/Provider/AbstractProviderTest.php
url:https://github.com/netresearch/t3x-nr-vault
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  via concepts/provider uses url:https://github.com/netresearch/t3x-nr-vault
Scope: only the relations this bundle models; coverage.md names what it leaves out.
A test line names a test file that covers the concept; it does not show that the test ran.
```

# Limits

Not modelled: the approval of a single tool call during a run, the workspace a run writes into, and the A2A protocol. [Coverage](../coverage.md) lists them.
