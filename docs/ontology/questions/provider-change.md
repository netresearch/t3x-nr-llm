---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "A provider changes"
description: "Which concepts can a change to a provider affect, and which tests run again?"
x-nr-query: --impact concepts/provider
status: draft
timestamp: 2026-11-27
---

# Question

A provider changes: a new endpoint, a new API key, another adapter type, or an outage. Which concepts can that affect, and which tests must run again?

# How to answer

Follow the relations backwards from the provider: every concept that uses, falls back to or is constrained by an affected concept is affected too. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --impact concepts/provider
```

# Answer at the time of writing

```text
concepts/provider
  test Tests/Unit/Domain/Model/ProviderTest.php
concepts/model
  via concepts/model uses concepts/provider
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/configuration
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/LlmConfigurationTest.php
  test Tests/Unit/Domain/DTO/FallbackChainTest.php
  test Tests/Unit/Service/ModelSelectionServiceTest.php
concepts/agent-run
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/agent-run uses concepts/configuration
concepts/llm-service
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/llm-service uses concepts/configuration
  test Tests/Unit/Service/LlmServiceManagerTest.php
concepts/middleware-pipeline
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/middleware-pipeline constrainedBy concepts/configuration
  test Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php
  test Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php
concepts/site-llm-settings
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/site-llm-settings uses concepts/configuration
  test Tests/Unit/Service/Site/SiteLlmSettingsTest.php
  test Tests/Unit/Service/ConfigurationResolverSiteTest.php
concepts/streaming-dispatcher
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/streaming-dispatcher constrainedBy concepts/configuration
  test Tests/Unit/Service/Streaming/StreamingDispatcherTest.php
concepts/task
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/task uses concepts/configuration
  test Tests/Unit/Domain/Model/TaskTest.php
concepts/autotranslate-integration
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/llm-service uses concepts/configuration
  via concepts/autotranslate-integration uses concepts/llm-service
  test Tests/Unit/Integration/Autotranslate/AutotranslateTranslatorTest.php
concepts/public-api
  via concepts/model uses concepts/provider
  via concepts/configuration uses concepts/model
  via concepts/llm-service uses concepts/configuration
  via concepts/llm-service partOf concepts/public-api
  test Tests/Unit/Api/ApiSurfaceSnapshotTest.php
  test Tests/Unit/Api/DeprecationInventoryTest.php
Scope: only the relations this bundle models; coverage.md names what it leaves out.
A test line names a test file that covers the concept; it does not show that the test ran.
```

# Limits

The bundle answers at the level of concepts. To list the concrete records of an installation, read its database: the models with `provider_uid` of that provider, the configurations with `model_uid` of those models, the configurations whose `fallback_chain` names one of those configurations, the configurations whose `rule_judge_configuration` names one of those configurations (in block mode their outputs are blocked), the tasks with their `configuration_uid`, and the agent runs with their `configuration_uid`. A configuration in the mode `criteria` is affected only if the changed provider serves one of its candidate models. The public API appears in this answer because the LLM service is part of it; its snapshot tests compare `@api` signatures and do not need to run again for a change of records.
