---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Provider adapter"
description: "The PHP class that speaks one vendor API behind the common provider contract."
status: draft
timestamp: 2026-11-27
---

# Definition

A provider adapter is the PHP class that sends requests to one vendor API and returns typed responses. Every adapter implements the common provider contract and optional capability interfaces (streaming, tools, vision, documents). The adapter type of a provider selects the adapter.

# Not to be confused with

- A provider record. The adapter is code; the provider is configuration in the database.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Provider adapter"]
  n1[/"Classes/Provider/AbstractProvider.php"/]
  n2[/"Classes/Provider/Contract/ProviderInterface.php"/]
  n3["Public API"]
  n4[/"Tests/Unit/Provider/AbstractProviderTest.php"/]
  n5["Middleware pipeline"]
  n6["Provider"]
  n7["Streaming dispatcher"]
  c -->|"implementedBy"| n1
  c -->|"implementedBy"| n2
  c -->|"partOf"| n3
  c -->|"verifiedBy"| n4
  n5 -.->|"uses (hypothesis)"| c
  n6 -->|"uses"| c
  n7 -.->|"uses (hypothesis)"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Provider/AbstractProvider.php` | `Classes/Provider/AbstractProvider.php` | `abstract class AbstractProvider implements ProviderInterface` | asserted |
| `implementedBy` | `Classes/Provider/Contract/ProviderInterface.php` | `Classes/Provider/Contract/ProviderInterface.php` | `interface ProviderInterface` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/AbstractProviderTest.php` | `Tests/Unit/Provider/AbstractProviderTest.php` | `class AbstractProviderTest` | asserted |
| `partOf` | [Public API](public-api.md) | `Tests/Unit/Api/api-surface.txt` | `Netresearch\NrLlm\Provider\Contract\ProviderInterface (interface)` | asserted |

# Notes

- `Classes/Provider/` holds one adapter class per vendor API, for example OpenAI, Azure OpenAI, Claude, Gemini, Groq, Mistral, Ollama and OpenRouter, and the decision adapters (`TypeSafeProvider`, `DecisionSidecarProvider`). `ProviderAdapterRegistry` holds the full list at runtime.
