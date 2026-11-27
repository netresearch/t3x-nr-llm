---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Usage record"
description: "One row of usage: requests, tokens and cost per service, configuration, model, task, user and day."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A usage record holds the counted usage of one kind of service on one day: requests, tokens, characters, audio seconds, images and the estimated cost. It names the configuration, the model, the task, the backend user and the calling extension.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `records` | [LLM service](llm-service.md) | `Classes/Provider/Middleware/UsageMiddleware.php` | `Records usage to tx_nrllm_service_usage after a successful provider call.` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php` | `class UsageMiddlewareTest` | asserted |
