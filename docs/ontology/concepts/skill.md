---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Skill"
description: "Instructions for a model, imported from a SKILL.md file of a skill source, in one version."
status: draft
timestamp: 2026-11-27
---

# Definition

A skill is a set of instructions for a model, parsed from one `SKILL.md` file of a skill source. Each skill version has a digest. A configuration or a task attaches skills, and the skills then enter the prompt. A skill carries the trust level of its source and a sensitivity ceiling that an operator declares.

# Not to be confused with

- The skill ID of an A2A profile (`skill_id`): that names a capability an agent offers to remote agents.
- A tool.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `derivedFrom` | [Skill source](skill-source.md) | `ext_tables.sql` | `Parsed SKILL.md records materialized from a skill source` | asserted |
| `constrainedBy` | [Skill approval](skill-approval.md) | `Classes/Service/Skill/SkillInstructionPolicy.php` | `an unrevoked approval names the skill, its current source and the digest` | asserted |
| `implementedBy` | `Classes/Domain/Model/Skill.php` | `Classes/Domain/Model/Skill.php` | `class Skill` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/Model/SkillAllowedToolsTest.php` | `Tests/Unit/Domain/Model/SkillAllowedToolsTest.php` | `class SkillAllowedToolsTest` | asserted |

# Notes

- A skill version enters a prompt as an instruction only if the trust level of its source reaches the configured threshold and an unrevoked approval names that skill, its current source and its digest (`Classes/Service/Skill/SkillInstructionPolicy.php`, ADR-214).
