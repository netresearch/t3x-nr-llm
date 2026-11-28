---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "User budget"
description: "Daily and monthly ceilings for the requests, tokens and cost of one backend user."
status: draft
timestamp: 2026-11-27
---

# Definition

A user budget sets daily and monthly ceilings for the requests, the tokens and the cost of one backend user. A request must stay under both windows. The value `0` means no limit on that axis. The budget is a ceiling, not a counter: the usage is summed from the usage records. The check runs before the call. Requests and cost include the planned call; tokens count only the recorded usage, so a call can take the token total past the ceiling, and concurrent calls can pass before their usage is recorded.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["User budget"]
  n1[/"Classes/Service/BudgetService.php"/]
  n2["Usage record"]
  n3[/"Tests/Unit/Service/BudgetServiceTest.php"/]
  n4["Backend user"]
  n5["Middleware pipeline"]
  n6["Streaming dispatcher"]
  c -->|"implementedBy"| n1
  c -->|"uses"| n2
  c -->|"verifiedBy"| n3
  n4 -->|"constrainedBy"| c
  n5 -->|"constrainedBy"| c
  n6 -->|"constrainedBy"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Usage record](usage-record.md) | `Classes/Domain/Model/UserBudget.php` | `demand from tx_nrllm_service_usage` | asserted |
| `implementedBy` | `Classes/Service/BudgetService.php` | `Classes/Service/BudgetService.php` | `private UserBudgetRepository $repository` | asserted |
| `verifiedBy` | `Tests/Unit/Service/BudgetServiceTest.php` | `Tests/Unit/Service/BudgetServiceTest.php` | `class BudgetServiceTest` | asserted |
