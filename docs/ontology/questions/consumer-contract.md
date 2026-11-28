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

Read the public API concept and its tests. A consuming extension relies on the `@api` surface only; everything marked `@internal` can change without notice. The contract is the public API and the concepts with a `partOf` row to it: the LLM service and the extension points for tools, guardrails, provider adapters and middleware. The rest of the answer lists what the public API depends on internally; its tests guard the implementation, not the contract. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --needs concepts/public-api
```

# Answer at the time of writing

```text
concepts/public-api
  test Tests/Unit/Api/ApiSurfaceSnapshotTest.php
  test Tests/Unit/Api/DeprecationInventoryTest.php
concepts/ai-written-record
  via concepts/ai-written-record partOf concepts/public-api
  test Tests/Functional/EventListener/MarkAiWrittenRecordTest.php
concepts/autotranslate-integration
  via concepts/autotranslate-integration partOf concepts/public-api
  test Tests/Unit/Integration/Autotranslate/AutotranslateTranslatorTest.php
concepts/configuration-preset
  via concepts/configuration-preset partOf concepts/public-api
  test Tests/Unit/Service/Preset/ConfigurationPresetImportServiceTest.php
concepts/guardrail
  via concepts/guardrail partOf concepts/public-api
  test Tests/Unit/Service/Guardrail/GuardrailPolicyResolverTest.php
concepts/keyword-search
  via concepts/keyword-search partOf concepts/public-api
  test Tests/Unit/Service/Retrieval/KeywordSearchServiceTest.php
  test Tests/Unit/Service/Retrieval/RetrievalServiceTest.php
concepts/llm-service
  via concepts/llm-service partOf concepts/public-api
  test Tests/Unit/Service/LlmServiceManagerTest.php
concepts/middleware-pipeline
  via concepts/middleware-pipeline partOf concepts/public-api
  test Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php
  test Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php
concepts/provider-adapter
  via concepts/provider-adapter partOf concepts/public-api
  test Tests/Unit/Provider/AbstractProviderTest.php
concepts/reranker
  via concepts/reranker partOf concepts/public-api
  test Tests/Unit/Service/Rerank/HttpRerankerTest.php
concepts/tool
  via concepts/tool partOf concepts/public-api
concepts/term-list
  via concepts/guardrail partOf concepts/public-api
  via concepts/term-list partOf concepts/guardrail
  test Tests/Unit/Service/Guardrail/Term/TermListGuardrailTest.php
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
  test Tests/Unit/Service/Glossary/GlossaryResolverTest.php
concepts/site-llm-settings
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/site-llm-settings
  test Tests/Unit/Service/Site/SiteLlmSettingsTest.php
  test Tests/Unit/Service/ConfigurationResolverSiteTest.php
concepts/user-budget
  via concepts/middleware-pipeline partOf concepts/public-api
  via concepts/middleware-pipeline constrainedBy concepts/user-budget
  test Tests/Unit/Service/BudgetServiceTest.php
concepts/mcp-server
  via concepts/tool partOf concepts/public-api
  via concepts/tool derivedFrom concepts/mcp-server
  test Tests/Functional/Service/Tool/Mcp/McpImportServiceTest.php
concepts/model
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
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
  test Tests/Unit/Domain/Enum/ToolGroupTest.php
concepts/prompt-snippet
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/prompt-snippet
  test Tests/Unit/Domain/Model/PromptSnippetTest.php
concepts/usage-record
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/usage-record
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
url:https://github.com/netresearch/t3x-nr-vault
  via concepts/llm-service partOf concepts/public-api
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  via concepts/provider uses url:https://github.com/netresearch/t3x-nr-vault
Scope: only the relations this bundle models; coverage.md names what it leaves out.
A test line names a test file that covers the concept; it does not show that the test ran.
```

# Limits

The consuming extensions are outside this repository. This bundle does not list them.
