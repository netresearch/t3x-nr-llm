<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A head editor sees the AI usage of the groups assigned to him

Governing decision: ADR-277. Today only an administrator sees who used how
much AI at what cost: the Analytics module is `access => admin`, and an
editor with `tasks_use` sees only his own usage against his own budget
(ADR-232). An organisation that lets a head editor answer for the AI cost of
his team has to make him an administrator, which opens every configuration,
provider and credential reference with it.

## Requirements

- **R1 Grant.** A new capability grant `usage_view_groups` on backend
  groups, a `BackendUserGrant` case with the same mechanics as `tasks_use`
  (custom permission option, group-scoped, administrators hold it
  implicitly). Without it nothing in this feature is reachable.
- **R2 Assignment.** A field on `be_groups`, "AI usage visible for groups"
  (`tx_nrllm_usage_visible_groups`), selects the backend groups whose members
  a holder may see. The holder's assignment is the union of this field over
  every group the holder resolves to — his own groups and the subgroups they
  include, hidden groups excluded — which is how TYPO3 merges every other
  group permission. `be_groups` is `adminOnly`, so only an administrator can
  set or widen an assignment.
- **R3 Membership.** A user is a member of an assigned group G when TYPO3
  says so: G is one of his groups, or one of his groups includes G as a
  subgroup, recursively (`GroupResolver::findAllUsersInGroups()`, core's
  default restrictions). A user who is only in a subgroup *of* G is not a
  member of G. Disabled, deleted and expired users, hidden or deleted
  groups, administrators and `_cli_*` users are never in the audience
  (the `EditorEntitlementDirectory` precedent).
- **R4 View.** A dedicated module `nrllm_groupusage` in the AI section with
  `access => user`. Reaching it takes the module ticked in the group's
  module list and the grant; the controller checks the grant itself. It
  shows, for the selected range: KPI totals (cost, requests, tokens,
  providers, models), the daily trend, the breakdowns by provider, model,
  service and calling extension, and the per-user table with budgets.
- **R5 Isolation.** Every number and every name in that view comes from
  usage rows whose `be_user` is in the audience. Rows of any other user,
  including `be_user = 0`, contribute to no total, chart, table or embedded
  JSON. An empty audience shows nothing, never everything.
- **R6 No admin parts.** The view computes and assigns no fallback rescues,
  no provider health, no billing report and no export link. It has no
  export and no AJAX endpoint. The Analytics module and its billing and
  export actions keep refusing a non-administrator, now also in the index
  action itself.
- **R7 Administrators.** The Analytics module, billing report and export
  are unchanged for administrators. An administrator who opens the new view
  sees every user.
- **R8 Off by default.** No group holds the grant and no group has an
  assignment after the update; nothing changes until an administrator sets
  both. The documentation states that personal cost data becomes visible to
  a non-administrator and that the operator must clear this with the works
  council and data protection.

## Out of scope

- Group budgets (budgets stay per user).
- Any ranking of groups beyond TYPO3's subgroup inclusion.
- An export of the scoped data, and dashboard widgets.
- Opening the Analytics module itself to non-administrators.

## Verification

| Requirement | Evidence |
| --- | --- |
| R1 grant | `BackendUserGrantTest`; API surface snapshot (additive) |
| R2, R3 assignment and membership | functional `UsageAudienceResolverTest`: parent group visible, subgroup-only not, hidden group, admin, `_cli_`, disabled, union over the holder's groups, no assignment |
| R4, R5 view and isolation | functional `GroupUsageControllerTest`: hidden users' names, models, providers, extensions and costs absent from the body and the decoded chart JSON; KPI cost equals the visible sum; empty audience; no grant gets 403 and no data |
| R5 service | functional `UsageAnalyticsServiceTest`: every method restricted, an empty list returns nothing |
| R6 admin parts | `GroupUsageControllerTest` (no rescue, health, billing or export markup); `AnalyticsControllerTest` (index, billing, export refuse the holder); the AJAX route table names neither controller |
| R7 administrators | `AnalyticsControllerTest` unchanged; `GroupUsageControllerTest` admin case |
| R8 off by default | `UsageAudienceResolverTest` (an empty field assigns nobody, an assignment without the grant is denied); documentation |
| Final checks | `make gate`; GitHub matrix |

The boundary that changes: per-user cost data, until now readable only by
administrators, becomes readable by a non-administrator for exactly the
users an administrator assigned to him. Credentials and provider input and
output are not touched.
