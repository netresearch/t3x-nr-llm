---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Middleware pipeline"
description: "The chain of steps a non-streamed call through the LLM service passes: budget, cache, circuit breaker, empty answer, fallback, tracing, guardrail, idempotency, telemetry and usage."
status: draft
timestamp: 2026-11-27
---

# Definition

The middleware pipeline is the chain of steps that a non-streamed call through the LLM service passes on its way to a provider adapter. `Classes/Provider/Middleware/` holds one class per step: budget, cache, circuit breaker, empty answer, fallback, GenAI tracing, guardrail, idempotency, telemetry and usage. A streamed call cannot pass the pipeline, because a PHP generator is lazy; the streaming dispatcher takes its place.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Middleware pipeline"]
  n1["Configuration"]
  n2["Guardrail"]
  n3["User budget"]
  n4[/"Classes/Provider/Middleware/MiddlewarePipeline.php"/]
  n5["Public API"]
  n6["Provider adapter"]
  n7[/"Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php"/]
  n8[/"Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php"/]
  n9[/"Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php"/]
  n10[/"Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php"/]
  n11["LLM service"]
  c -->|"constrainedBy"| n1
  c -->|"constrainedBy"| n2
  c -->|"constrainedBy"| n3
  c -->|"implementedBy"| n4
  c -->|"partOf"| n5
  c -->|"uses"| n6
  c -->|"verifiedBy"| n7
  c -->|"verifiedBy"| n8
  c -->|"verifiedBy"| n9
  c -->|"verifiedBy"| n10
  n11 -->|"uses"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Provider adapter](provider-adapter.md) | `Classes/Service/LlmServiceManager.php` | `return $provider->embeddings($input, $optionsArray)->toArray();` | asserted |
| `constrainedBy` | [User budget](user-budget.md) | `Classes/Provider/Middleware/BudgetMiddleware.php` | `private BudgetServiceInterface $budgetService` | asserted |
| `constrainedBy` | [Configuration](configuration.md) | `Classes/Provider/Middleware/BudgetMiddleware.php` | `$result = $this->budgetService->check($beUserUid, $plannedCost, $context->configuration);` | asserted |
| `constrainedBy` | [Guardrail](guardrail.md) | `Classes/Provider/Middleware/GuardrailMiddleware.php` | `final readonly class GuardrailMiddleware implements ProviderMiddlewareInterface` | asserted |
| `implementedBy` | `Classes/Provider/Middleware/MiddlewarePipeline.php` | `Classes/Provider/Middleware/MiddlewarePipeline.php` | `class MiddlewarePipeline` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php` | `Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php` | `class MiddlewarePipelineTest` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php` | `class BudgetMiddlewareTest` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php` | `class GuardrailMiddlewareTest` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php` | `class FallbackMiddlewareTest` | asserted |
| `partOf` | [Public API](public-api.md) | `Tests/Unit/Api/api-surface.txt` | `Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface (interface)` | asserted |

# Notes

- Two backend actions call a provider adapter directly and pass no pipeline step: the model test (`Classes/Controller/Backend/ModelTestController.php`) and the configuration test (`Classes/Controller/Backend/ConfigurationController.php`). They create the adapter with `createAdapterFromModel()`, so no budget, guardrail or usage record applies to them.
- The pipeline itself takes the provider call as a terminal callable. The `uses` row to Provider adapter therefore cites the terminal the LLM service manager passes in (`Classes/Service/LlmServiceManager.php`); the other operations pass terminals of the same kind.
