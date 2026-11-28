---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Backend user group"
description: "A TYPO3 backend group; a configuration names the groups that may use it."
status: draft
timestamp: 2026-11-27
---

# Definition

A backend user group is a group of the TYPO3 backend (table `be_groups` of TYPO3). A configuration names the groups whose members may use it.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Backend user group"]
  n1[/"Classes/Domain/Model/BackendUserGroup.php"/]
  n2["Configuration"]
  n3["Backend user"]
  c -->|"implementedBy"| n1
  n2 -->|"constrainedBy"| c
  n3 -.->|"partOf (hypothesis)"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Domain/Model/BackendUserGroup.php` | `Classes/Domain/Model/BackendUserGroup.php` | `class BackendUserGroup` | asserted |
