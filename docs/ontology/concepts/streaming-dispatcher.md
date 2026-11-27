---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Streaming dispatcher"
description: "The path of a streamed call: it runs the budget check before the stream and records usage and telemetry around it, in place of the middleware pipeline."
status: draft
timestamp: 2026-11-27
---

# Definition

The streaming dispatcher carries a streamed call. A streamed provider call cannot pass the middleware pipeline, because a PHP generator is lazy: wrapped as the pipeline terminal, every step would run before the stream starts. The dispatcher therefore runs the budget check before the stream and records usage and telemetry around it, as a wrapping generator (ADR-062). Without a dispatcher, the LLM service manager calls the provider directly.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Provider adapter](provider-adapter.md) | `Classes/Service/Streaming/StreamingDispatcher.php` | `wrapping generator, not a replacement of the provider's:` | asserted |
| `constrainedBy` | [User budget](user-budget.md) | `Classes/Service/Streaming/StreamingDispatcher.php` | `1. **Budget pre-flight (eager).**` | asserted |
| `constrainedBy` | [Configuration](configuration.md) | `Classes/Service/Streaming/StreamingDispatcher.php` | `$this->assertWithinBudget($context, $configuration);` | asserted |
| `implementedBy` | `Classes/Service/Streaming/StreamingDispatcher.php` | `Classes/Service/Streaming/StreamingDispatcher.php` | `final readonly class StreamingDispatcher` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Streaming/StreamingDispatcherTest.php` | `Tests/Unit/Service/Streaming/StreamingDispatcherTest.php` | `class StreamingDispatcherTest` | asserted |

# Notes

- The dispatcher checks the budget against the user budget and the daily limits of the configuration, and it runs the fallback chain of the configuration. Which other middleware steps apply to a stream is not modelled.
