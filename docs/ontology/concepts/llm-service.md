---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "LLM service"
description: "The public call surface: the LLM service manager and the feature services for completion, conversation, embedding, tools, translation and vision."
status: draft
timestamp: 2026-11-27
---

# Definition

The LLM service is the surface that consuming code calls. Its entry point is the LLM service manager. Behind it, one feature service exists for each kind of call: completion, conversation, embedding, tool calling, translation and vision. A call that returns its answer at once runs through the middleware pipeline; a streamed call runs through the streaming dispatcher instead.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["LLM service"]
  n1[/"Classes/Service/LlmServiceManager.php"/]
  n2["Public API"]
  n3["Configuration"]
  n4["Glossary"]
  n5["Middleware pipeline"]
  n6["Streaming dispatcher"]
  n7[/"Tests/Unit/Service/LlmServiceManagerTest.php"/]
  n8["Usage record"]
  n9["Agent run"]
  n10["Task"]
  c -->|"implementedBy"| n1
  c -->|"partOf"| n2
  c -->|"uses"| n3
  c -->|"uses"| n4
  c -->|"uses"| n5
  c -->|"uses"| n6
  c -->|"verifiedBy"| n7
  n8 -->|"records"| c
  n9 -->|"uses"| c
  n10 -->|"uses"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Middleware pipeline](middleware-pipeline.md) | `Classes/Service/LlmServiceManager.php` | `private MiddlewarePipeline $pipeline` | asserted |
| `uses` | [Streaming dispatcher](streaming-dispatcher.md) | `Classes/Service/LlmServiceManager.php` | `private ?StreamingDispatcher $streaming = null` | asserted |
| `uses` | [Configuration](configuration.md) | `Classes/Service/LlmServiceManager.php` | `public function chatWithConfiguration(` | asserted |
| `uses` | [Glossary](glossary.md) | `Classes/Service/Feature/TranslationService.php` | `GlossaryResolverInterface $glossaryResolver` | asserted |
| `partOf` | [Public API](public-api.md) | `Documentation/Api/Stability.rst` | `Classes and interfaces you *call*: the feature services` | asserted |
| `implementedBy` | `Classes/Service/LlmServiceManager.php` | `Classes/Service/LlmServiceManager.php` | `class LlmServiceManager` | asserted |
| `verifiedBy` | `Tests/Unit/Service/LlmServiceManagerTest.php` | `Tests/Unit/Service/LlmServiceManagerTest.php` | `class LlmServiceManagerTest` | asserted |

# Notes

- The glossary row holds for translation calls only.
