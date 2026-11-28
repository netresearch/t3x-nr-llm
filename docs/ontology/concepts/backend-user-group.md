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

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Domain/Model/BackendUserGroup.php` | `Classes/Domain/Model/BackendUserGroup.php` | `class BackendUserGroup` | asserted |
