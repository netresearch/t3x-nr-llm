---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Skill source"
description: "A place skills come from: one SKILL.md file, a repository or a marketplace index on GitHub, or the TYPO3 backend for skills written there."
status: draft
timestamp: 2026-11-27
---

# Definition

A skill source is a place from which nr-llm takes skills. Three types import from GitHub: one `SKILL.md` file, a repository, or a marketplace index that lists several skills. The fourth type, `backend`, holds skills that an administrator writes in the TYPO3 backend; nothing is fetched for it, and the version digest comes from the current fields (ADR-214).

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Skill source"]
  n1[/"ext_tables.sql"/]
  n2["Skill"]
  c -->|"implementedBy"| n1
  n2 -->|"derivedFrom"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `ext_tables.sql` | `ext_tables.sql` | `GitHub-hosted skill sources (single SKILL.md, repo, or marketplace index)` | asserted |
