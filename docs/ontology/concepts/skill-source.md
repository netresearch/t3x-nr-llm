---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Skill source"
description: "A GitHub location from which nr-llm imports skills: one SKILL.md file, a repository or a marketplace index."
status: draft
timestamp: 2026-11-27
---

# Definition

A skill source is a location on GitHub from which nr-llm imports skills. It is one `SKILL.md` file, a repository, or a marketplace index that lists several skills.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `ext_tables.sql` | `ext_tables.sql` | `GitHub-hosted skill sources (single SKILL.md, repo, or marketplace index)` | asserted |
