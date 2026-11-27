---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "A model is retired"
description: "Which concepts can lose their model when a model is deactivated or removed?"
x-nr-query: --impact concepts/model
status: draft
timestamp: 2026-11-27
---

# Question

A vendor retires a model, or an administrator deactivates it. Which concepts can lose their model?

# How to answer

Follow the relations backwards from the model. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --impact concepts/model
```

# Answer at the time of writing

```text
concepts/model
  test Tests/Unit/Domain/Model/ModelTest.php
concepts/configuration
  via concepts/configuration uses concepts/model
  test Tests/Unit/Domain/Model/LlmConfigurationTest.php
  test Tests/Unit/Domain/DTO/FallbackChainTest.php
  test Tests/Unit/Service/ModelSelectionServiceTest.php
concepts/agent-run
  via concepts/configuration uses concepts/model
  via concepts/agent-run uses concepts/configuration
concepts/llm-service
  via concepts/configuration uses concepts/model
  via concepts/llm-service uses concepts/configuration
  test Tests/Unit/Service/LlmServiceManagerTest.php
concepts/middleware-pipeline
  via concepts/configuration uses concepts/model
  via concepts/middleware-pipeline constrainedBy concepts/configuration
  test Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php
  test Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php
  test Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php
concepts/streaming-dispatcher
  via concepts/configuration uses concepts/model
  via concepts/streaming-dispatcher constrainedBy concepts/configuration
  test Tests/Unit/Service/Streaming/StreamingDispatcherTest.php
concepts/task
  via concepts/configuration uses concepts/model
  via concepts/task uses concepts/configuration
  test Tests/Unit/Domain/Model/TaskTest.php
concepts/public-api
  via concepts/configuration uses concepts/model
  via concepts/llm-service uses concepts/configuration
  via concepts/llm-service partOf concepts/public-api
  test Tests/Unit/Api/ApiSurfaceSnapshotTest.php
  test Tests/Unit/Api/DeprecationInventoryTest.php
Scope: only the relations this bundle models; coverage.md names what it leaves out.
```

# Limits

For an installation: a configuration in the mode `fixed` with this `model_uid` loses its model. A configuration in the mode `criteria` loses one candidate and keeps working if another model meets its criteria. A configuration whose fallback chain names an affected configuration loses that fallback step.
