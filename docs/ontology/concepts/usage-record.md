---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Usage record"
description: "One row of usage: requests, tokens and cost per service, configuration, model, task, user and day."
status: draft
timestamp: 2026-11-27
---

# Definition

A usage record holds the counted usage of one kind of service on one day: requests, tokens, characters, audio seconds, images and the estimated cost. It names the configuration, the model, the task, the backend user and the calling extension.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Usage record"]
  n1["LLM service"]
  n2[/"Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php"/]
  n3["User budget"]
  c -->|"records"| n1
  c -->|"verifiedBy"| n2
  n3 -->|"uses"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `records` | [LLM service](llm-service.md) | `Classes/Provider/Middleware/UsageMiddleware.php` | `Records usage to tx_nrllm_service_usage after a successful provider call.` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php` | `Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php` | `class UsageMiddlewareTest` | asserted |
