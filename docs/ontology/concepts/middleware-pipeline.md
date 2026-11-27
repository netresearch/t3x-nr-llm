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

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Provider adapter](provider-adapter.md) | `Classes/Provider/Middleware/MiddlewarePipeline.php` | `a terminal provider call.` | asserted |
| `constrainedBy` | [User budget](user-budget.md) | `Classes/Provider/Middleware/BudgetMiddleware.php` | `private BudgetServiceInterface $budgetService` | asserted |
| `constrainedBy` | [Configuration](configuration.md) | `Classes/Provider/Middleware/BudgetMiddleware.php` | `they are configuration-scoped, not user-scoped` | asserted |
| `constrainedBy` | [Guardrail](guardrail.md) | `Classes/Provider/Middleware/GuardrailMiddleware.php` | `final readonly class GuardrailMiddleware implements ProviderMiddlewareInterface` | asserted |
| `implementedBy` | `Classes/Provider/Middleware/MiddlewarePipeline.php` | `Classes/Provider/Middleware/MiddlewarePipeline.php` | `class MiddlewarePipeline` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php` | `Tests/Unit/Provider/Middleware/MiddlewarePipelineTest.php` | `class MiddlewarePipelineTest` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/BudgetMiddlewareTest.php` | `class BudgetMiddlewareTest` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/GuardrailMiddlewareTest.php` | `class GuardrailMiddlewareTest` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/FallbackMiddlewareTest.php` | `class FallbackMiddlewareTest` | asserted |

# Notes

- Two backend actions call a provider adapter directly and pass no pipeline step: the model test (`Classes/Controller/Backend/ModelTestController.php`) and the configuration test (`Classes/Controller/Backend/ConfigurationController.php`). They create the adapter with `createAdapterFromModel()`, so no budget, guardrail or usage record applies to them.
