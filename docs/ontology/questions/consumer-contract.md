---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "What a consuming extension relies on"
description: "Which concepts form the contract of nr-llm towards consuming extensions?"
x-nr-query: --needs concepts/public-api
status: draft
timestamp: 2026-11-27
---

# Question

An extension calls nr-llm. Which part of nr-llm may it rely on, and which tests protect that part?

# How to answer

Read the public API concept and its tests. A consuming extension relies on the `@api` surface only; everything marked `@internal` can change without notice. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --needs concepts/public-api
```

# Answer at the time of writing

```text
concepts/public-api
  test Tests/Unit/Api/ApiSurfaceSnapshotTest.php
  test Tests/Unit/Api/DeprecationInventoryTest.php
concepts/llm-service
  via concepts/llm-service partOf concepts/public-api
  test Tests/Unit/Service/LlmServiceManagerTest.php
concepts/middleware-pipeline
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/middleware-pipeline
  test Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php
  test Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php
concepts/streaming-dispatcher
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/streaming-dispatcher
  test Tests/Unit/Service/Streaming/StreamingDispatcherTest.php
concepts/configuration
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  test Tests/Unit/Domain/Model/LlmConfigurationTest.php
  test Tests/Unit/Domain/DTO/FallbackChainTest.php
  test Tests/Unit/Service/ModelSelectionServiceTest.php
concepts/glossary
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/glossary
  test Tests/Functional/Domain/GlossaryStorageTest.php
concepts/provider-adapter
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline uses concepts/provider-adapter
  test Tests/Unit/Provider/AbstractProviderTest.php
concepts/user-budget
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/user-budget
  test Tests/Unit/Service/BudgetServiceTest.php
concepts/guardrail
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/guardrail
concepts/model
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/term-list
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/term-list
concepts/backend-user-group
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/backend-user-group
concepts/skill
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  test Tests/Unit/Domain/Model/SkillAllowedToolsTest.php
concepts/tool-group
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
concepts/prompt-snippet
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/prompt-snippet
  test Tests/Unit/Domain/Model/PromptSnippetTest.php
concepts/usage-record
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/user-budget
  via concepts/user-budget uses concepts/usage-record
  test Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php
concepts/provider
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  test Tests/Unit/Domain/Model/ProviderTest.php
concepts/backend-user
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/backend-user-group
  via concepts/backend-user partOf concepts/backend-user-group (hypothesis)
concepts/skill-source
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill derivedFrom concepts/skill-source
concepts/skill-approval
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill constrainedBy concepts/skill-approval
concepts/tool
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
  via concepts/tool partOf concepts/tool-group
url:https://github.com/netresearch/t3x-nr-vault
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  via concepts/provider uses url:https://github.com/netresearch/t3x-nr-vault
concepts/mcp-server
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/tool-group
  via concepts/tool partOf concepts/tool-group
  via concepts/tool derivedFrom concepts/mcp-server
Scope: only the relations this bundle models; coverage.md names what it leaves out.
```

# Limits

The consuming extensions are outside this repository. This bundle does not list them.
