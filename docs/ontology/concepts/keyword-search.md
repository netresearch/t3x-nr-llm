---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Keyword search"
description: "The public keyword search over the site search: a facade over a cascade of search backends that third parties can add."
status: draft
timestamp: 2026-11-28
---

# Definition

Keyword search is the public facade for a keyword search over the site. It asks the registered search backends in the order of their priority, and the first available backend answers; results are never merged across backends. The hits are filtered to what an anonymous visitor may read. The facade never throws: a failure yields an empty list. A search backend is an extension point: a third party adds one by implementing the search backend interface, which carries the tag `nr_llm.retrieval_backend`.

# Not to be confused with

- The reranker, which scores candidates a consumer already has.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Keyword search"]
  n1[/"Classes/Service/Retrieval/KeywordSearchService.php"/]
  n2[/"Classes/Service/Retrieval/RetrievalService.php"/]
  n3["Public API"]
  n4[/"Tests/Unit/Service/Retrieval/KeywordSearchServiceTest.php"/]
  n5[/"Tests/Unit/Service/Retrieval/RetrievalServiceTest.php"/]
  c -->|"implementedBy"| n1
  c -->|"implementedBy"| n2
  c -->|"partOf"| n3
  c -->|"verifiedBy"| n4
  c -->|"verifiedBy"| n5
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `partOf` | [Public API](public-api.md) | `Tests/Unit/Api/api-surface.txt` | `Netresearch\NrLlm\Service\Retrieval\KeywordSearchInterface (interface)` | asserted |
| `partOf` | [Public API](public-api.md) | `Tests/Unit/Api/api-surface.txt` | `Netresearch\NrLlm\Service\Retrieval\SearchBackendInterface (interface)` | asserted |
| `implementedBy` | `Classes/Service/Retrieval/KeywordSearchService.php` | `Classes/Service/Retrieval/KeywordSearchService.php` | `final class KeywordSearchService implements KeywordSearchInterface` | asserted |
| `implementedBy` | `Classes/Service/Retrieval/RetrievalService.php` | `Classes/Service/Retrieval/KeywordSearchService.php` | `new RetrievalService($this->selectedBackends())` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Retrieval/KeywordSearchServiceTest.php` | `Tests/Unit/Service/Retrieval/KeywordSearchServiceTest.php` | `class KeywordSearchServiceTest` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Retrieval/RetrievalServiceTest.php` | `Tests/Unit/Service/Retrieval/RetrievalServiceTest.php` | `class RetrievalServiceTest` | asserted |

# Notes

- The two `partOf` rows are two parts of the public API: the facade that consumers call and the backend interface that third parties implement (`Documentation/Api/Stability.rst`).
- The container has a second variant without the database LIKE fallback, under the service name `nr_llm.keyword_search.index_backed`.
