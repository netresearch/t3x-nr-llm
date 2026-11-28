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

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Domain/Enum/ToolGroup.php` | `Classes/Domain/Enum/ToolGroup.php` | `A group is an OPEN set` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/Enum/ToolGroupTest.php` | `Tests/Unit/Domain/Enum/ToolGroupTest.php` | `final class ToolGroupTest extends TestCase` | asserted |
