---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "User budget"
description: "Daily and monthly ceilings for the requests, tokens and cost of one backend user."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A user budget sets daily and monthly ceilings for the requests, the tokens and the cost of one backend user. A request must stay under both windows. The value `0` means no limit on that axis. The budget is a ceiling, not a counter: the usage is summed from the usage records.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Usage record](usage-record.md) | `Classes/Domain/Model/UserBudget.php` | `demand from tx_nrllm_service_usage` | asserted |
| `implementedBy` | `Classes/Service/BudgetService.php` | `Classes/Service/BudgetService.php` | `private UserBudgetRepository $repository` | asserted |
| `verifiedBy` | `Tests/Unit/Service/BudgetServiceTest.php` | `Tests/Unit/Service/BudgetServiceTest.php` | `class BudgetServiceTest` | asserted |
