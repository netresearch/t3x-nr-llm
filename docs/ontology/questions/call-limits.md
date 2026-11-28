---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "Limits of one call"
description: "Which limits and checks apply to one call through the LLM service?"
x-nr-query: --needs concepts/llm-service
status: draft
timestamp: 2026-11-27
---

# Question

Which limits and checks apply to one call through the LLM service?

# How to answer

Follow the relations forwards from the LLM service. The limits are the targets of the `constrainedBy` steps, together with the daily limits of the configuration the call uses. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --needs concepts/llm-service
```

# Answer at the time of writing

```text
concepts/llm-service
  test Tests/Unit/Service/LlmServiceManagerTest.php
concepts/middleware-pipeline
  via concepts/llm-service uses concepts/middleware-pipeline
  test Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php
  test Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php
concepts/streaming-dispatcher
  via concepts/llm-service uses concepts/streaming-dispatcher
  test Tests/Unit/Service/Streaming/StreamingDispatcherTest.php
concepts/configuration
  via concepts/llm-service uses concepts/configuration
  test Tests/Unit/Domain/Model/LlmConfigurationTest.php
  test Tests/Unit/Domain/DTO/FallbackChainTest.php
  test Tests/Unit/Service/ModelSelectionServiceTest.php
concepts/glossary
  via concepts/llm-service uses concepts/glossary
  test Tests/Functional/Domain/GlossaryStorageTest.php
  test Tests/Unit/Service/Glossary/GlossaryResolverTest.php
concepts/site-llm-settings
  via concepts/llm-service uses concepts/site-llm-settings
  test Tests/Unit/Service/Site/SiteLlmSettingsTest.php
  test Tests/Unit/Service/ConfigurationResolverSiteTest.php
concepts/provider-adapter
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline uses concepts/provider-adapter (hypothesis)
  test Tests/Unit/Provider/AbstractProviderTest.php
concepts/user-budget
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/user-budget
  test Tests/Unit/Service/BudgetServiceTest.php
concepts/guardrail
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/guardrail
  test Tests/Unit/Service/Guardrail/GuardrailPolicyResolverTest.php
concepts/model
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/term-list
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/term-list
  test Tests/Unit/Service/Guardrail/Term/TermListGuardrailTest.php
concepts/backend-user-group
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/backend-user-group
concepts/skill
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  test Tests/Unit/Domain/Model/SkillAllowedToolsTest.php
concepts/tool-group
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
  test Tests/Unit/Domain/Enum/ToolGroupTest.php
concepts/prompt-snippet
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/prompt-snippet
  test Tests/Unit/Domain/Model/PromptSnippetTest.php
concepts/configuration-preset
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration derivedFrom concepts/configuration-preset
  test Tests/Unit/Service/Preset/ConfigurationPresetImportServiceTest.php
concepts/usage-record
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/usage-record
  test Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php
concepts/provider
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  test Tests/Unit/Domain/Model/ProviderTest.php
concepts/backend-user
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/backend-user-group
  via concepts/backend-user partOf concepts/backend-user-group (hypothesis)
concepts/skill-source
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill derivedFrom concepts/skill-source
concepts/skill-approval
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill constrainedBy concepts/skill-approval
concepts/tool
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
  via concepts/tool partOf concepts/tool-group
url:https://github.com/netresearch/t3x-nr-vault
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  via concepts/provider uses url:https://github.com/netresearch/t3x-nr-vault
concepts/mcp-server
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
  via concepts/tool partOf concepts/tool-group
  via concepts/tool derivedFrom concepts/mcp-server
  test Tests/Functional/Service/Tool/Mcp/McpImportServiceTest.php
Scope: only the relations this bundle models; coverage.md names what it leaves out.
A test line names a test file that covers the concept; it does not show that the test ran.
```

# Limits

The budget check runs twice: against the daily limits of the configuration and against the user budget of the backend user. Both must pass. A call without a backend user skips the user budget and passes the daily limits of its configuration only. The model test and the configuration test of the backend module call the adapter directly and pass neither check. A streamed call takes the streaming dispatcher instead of the middleware pipeline; it runs the budget check and records usage and telemetry, and which other steps apply to a stream is not modelled.
