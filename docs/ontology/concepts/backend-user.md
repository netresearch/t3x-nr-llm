---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Backend user"
description: "A TYPO3 backend account; nr-llm budgets, permits and acts on behalf of it."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A backend user is an account of the TYPO3 backend (table `be_users` of TYPO3). nr-llm sets a user budget per backend user, and an agent run acts on behalf of one backend user.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `constrainedBy` | [User budget](user-budget.md) | `Configuration/TCA/tx_nrllm_user_budget.php` | `'foreign_table' => 'be_users'` | asserted |
| `partOf` | [Backend user group](backend-user-group.md) | `Classes/Domain/Model/BackendUserGroup.php` | `BackendUserGroup` | hypothesis |

# Notes

- The `partOf` row is a hypothesis: the group membership is a TYPO3 core relation, and this bundle names no evidence in nr-llm for it.
