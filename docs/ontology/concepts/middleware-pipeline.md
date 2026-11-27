---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Middleware pipeline"
description: "The chain of steps every provider call passes: fallback, budget, cache, guardrail, circuit breaker, usage and telemetry."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

The middleware pipeline is the chain of steps that every call to a provider adapter passes. The steps check the budget, apply guardrails, answer from the cache, switch to the fallback chain on a failure, open a circuit breaker after repeated failures, and record usage and telemetry.

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
