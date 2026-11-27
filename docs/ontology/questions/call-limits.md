---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "Limits of one call"
description: "Which limits and checks apply to one call through the LLM service?"
x-nr-query: --needs concepts/llm-service
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Question

Which limits and checks apply to one call through the LLM service?

# How to answer

Follow the relations forwards from the LLM service and keep the `constrainedBy` steps. Run:

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
concepts/configuration
  via concepts/llm-service uses concepts/configuration
  test Tests/Unit/Domain/Model/LlmConfigurationTest.php
  test Tests/Unit/Domain/DTO/FallbackChainTest.php
  test Tests/Unit/Service/ModelSelectionServiceTest.php
concepts/glossary
  via concepts/llm-service uses concepts/glossary
  test Tests/Functional/Domain/GlossaryStorageTest.php
concepts/provider-adapter
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline uses concepts/provider-adapter
  test Tests/Unit/Provider/AbstractProviderTest.php
concepts/user-budget
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/user-budget
  test Tests/Unit/Service/BudgetServiceTest.php
concepts/guardrail
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/guardrail
concepts/model
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/term-list
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration constrainedBy concepts/term-list
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
concepts/prompt-snippet
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/prompt-snippet
  test Tests/Unit/Domain/Model/PromptSnippetTest.php
concepts/usage-record
  via concepts/llm-service uses concepts/middleware-pipeline
  via concepts/middleware-pipeline constrainedBy concepts/user-budget
  via concepts/user-budget uses concepts/usage-record
  test Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php
concepts/provider
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  test Tests/Unit/Domain/Model/ProviderTest.php
concepts/skill-source
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill derivedFrom concepts/skill-source
concepts/skill-approval
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/skill
  via concepts/skill constrainedBy concepts/skill-approval
url:https://github.com/netresearch/t3x-nr-vault
  via concepts/llm-service uses concepts/configuration
  via concepts/configuration uses concepts/model
  via concepts/model uses concepts/provider
  via concepts/provider uses url:https://github.com/netresearch/t3x-nr-vault
Scope: only the relations this bundle models; coverage.md names what it leaves out.
```

# Limits

The budget check runs twice: against the daily limits of the configuration and against the user budget of the backend user. Both must pass.
