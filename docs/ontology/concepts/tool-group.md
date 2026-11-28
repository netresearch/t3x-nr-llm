---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Tool group"
description: "A named group of tools that a configuration can allow as a whole."
status: draft
timestamp: 2026-11-27
---

# Definition

A tool group is a named group of tools, for example `content`, `editing` or `system`. A configuration allows tools by group. The set of groups is open: an extension that ships a tool can declare its own group. nr-llm ships a fixed list of its own groups.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Tool group"]
  n1[/"Classes/Domain/Enum/ToolGroup.php"/]
  n2[/"Tests/Unit/Domain/Enum/ToolGroupTest.php"/]
  n3["Tool"]
  n4["Configuration"]
  n5["Tool enable state"]
  c -->|"implementedBy"| n1
  c -->|"verifiedBy"| n2
  n3 -->|"partOf"| c
  n4 -->|"uses"| c
  n5 -->|"uses"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Domain/Enum/ToolGroup.php` | `Classes/Domain/Enum/ToolGroup.php` | `A group is an OPEN set` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/Enum/ToolGroupTest.php` | `Tests/Unit/Domain/Enum/ToolGroupTest.php` | `final class ToolGroupTest extends TestCase` | asserted |
