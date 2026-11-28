---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Skill approval"
description: "The record that a person approved one version of one skill from one source."
status: draft
timestamp: 2026-11-27
---

# Definition

A skill approval records that a person approved one version of one skill, identified by its digest and its source. A revocation marks every approval of that skill and digest.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Skill approval"]
  n1["Skill"]
  c -->|"approves"| n1
  n1 -->|"constrainedBy"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `approves` | [Skill](skill.md) | `ext_tables.sql` | `KEY skill_source_digest (skill_uid, source_uid, version_digest)` | asserted |
