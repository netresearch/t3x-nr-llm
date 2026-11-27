---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Skill approval"
description: "The record that a person approved one version of one skill from one source."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A skill approval records that a person approved one version of one skill, identified by its digest and its source. A revocation marks every approval of that skill and digest.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `approves` | [Skill](skill.md) | `ext_tables.sql` | `Approvals of skill versions (ADR-214 item 2)` | asserted |
