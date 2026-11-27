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
concepts/model
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/guardrail
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration constrainedBy concepts/guardrail
concepts/term-list
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration constrainedBy concepts/term-list
concepts/backend-user-group
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration constrainedBy concepts/backend-user-group
concepts/skill
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/skill
  test Tests/Unit/Domain/Model/SkillAllowedToolsTest.php
concepts/tool-group
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
concepts/prompt-snippet
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/prompt-snippet
  test Tests/Unit/Domain/Model/PromptSnippetTest.php
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
concepts/mcp-server
  via concepts/agent-run uses concepts/tool
  via concepts/tool derivedFrom concepts/mcp-server
concepts/provider
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  test Tests/Unit/Domain/Model/ProviderTest.php
concepts/skill-source
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill derivedFrom concepts/skill-source
concepts/skill-approval
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill constrainedBy concepts/skill-approval
concepts/usage-record
  via concepts/agent-run constrainedBy concepts/backend-user
  via concepts/backend-user constrainedBy concepts/user-budget
  via concepts/user-budget uses concepts/usage-record
  test Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php
concepts/provider-adapter
  via concepts/agent-run uses concepts/llm-service
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline uses concepts/provider-adapter
  test Tests/Unit/Provider/AbstractProviderTest.php
url:https://github.com/netresearch/t3x-nr-vault
  via concepts/agent-run uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  via concepts/provider uses url:https://github.com/netresearch/t3x-nr-vault
Scope: only the relations this bundle models; coverage.md names what it leaves out.
```

# Limits

Not modelled: the approval of a single tool call during a run, the workspace a run writes into, and the A2A protocol. [Coverage](../coverage.md) lists them.
