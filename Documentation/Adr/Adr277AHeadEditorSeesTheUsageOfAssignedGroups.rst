.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-277:

=====================================================================
ADR-277: A head editor sees the usage of the groups assigned to him
=====================================================================

:Status: Accepted
:Date: 2026-11-10
:Authors: Netresearch DTT GmbH

Context
=======

Who used how much AI at what estimated cost is visible to administrators
only. The Analytics module (:ref:`ADR-029 <adr-029>`) is ``access =>
admin`` and sits in the administrator-only *Operation* container; its
billing report and CSV export (:ref:`ADR-231 <adr-231>`) refuse a
non-administrator in the action itself, because they carry user names and
costs. An editor holding ``tasks_use`` sees his own usage against his own
budget and nobody else's (:ref:`ADR-232 <adr-232>`).

Organisations asked for a head editor (*Hauptredakteur*) who answers for
the AI cost of his team. Today that person must be made an administrator,
which opens every provider, configuration, credential reference and the
usage of every user with it.

TYPO3 has no ranking of backend groups. The only relation between groups
is the subgroup: a group that lists another as a subgroup includes that
group's permissions. Budgets are per user; group budgets do not exist.

Decision
========

1. A grant and an explicit assignment
-------------------------------------

*   A new capability grant ``usage_view_groups``
    (``BackendUserGrant::USAGE_VIEW_GROUPS``), a custom permission option
    on backend groups like the other grants of :ref:`ADR-130 <adr-130>`.
    Administrators hold it implicitly.
*   A new field on ``be_groups``, :guilabel:`AI usage visible for groups`
    (``tx_nrllm_usage_visible_groups``), a comma list of backend groups
    shaped like core's ``subgroup`` field. It names whose usage the
    holders in this group may see.
*   A holder's assignment is the union of that field over every group he
    resolves to — his own groups and the subgroups they include, hidden
    groups left out by core. That is how TYPO3 merges ``custom_options``,
    so the grant and the assignment may sit on different groups. Reading
    the assignment only from the group that carries the grant was the
    alternative; it gives a second, different rule for one feature and buys
    nothing, because ``be_groups`` is ``adminOnly``: only an administrator
    can set or widen an assignment, never the holder.

Neither half works alone. The grant without an assignment shows nobody;
an assignment without the grant is denied.

2. Membership is TYPO3's
------------------------

A user is a member of an assigned group G when G is one of his groups or
one of his groups includes G as a subgroup, recursively. This is
``GroupResolver::findAllUsersInGroups()`` (present in TYPO3 13.4 and 14.3)
with core's default restrictions, which the billing report's
``EditorEntitlementDirectory`` already uses.

The task asked whether members of **subgroups** of an assigned group
count. Under TYPO3's semantics they do not: a subgroup passes its
permissions *up* to the groups that include it, so a member of G inherits
G's subgroups, while a member of a subgroup is not a member of G. Core's
own permission checks agree: a user's resolved group list
(``userGroupsUID``, which the page-permission check reads) holds his groups
and their subgroups, never the groups that include them. The
direction that does count is the reverse one — members of groups that
include G — and that is the reading the operator meets everywhere else in
TYPO3, so the feature follows it rather than inventing a hierarchy.

Consequence for operators: assigning a group that many others include (a
base group carrying shared permissions) shows the members of all of them.
The documentation says so with an example.

Never in an audience: administrators and command-line users (``_cli_*``),
as in ``EditorEntitlementDirectory``; disabled, deleted and expired users
and hidden or deleted groups, by core's restrictions; and ``be_user = 0``,
the usage nobody is attributed to.

3. A module of its own, reusing the analytics code
--------------------------------------------------

A new module :guilabel:`AI usage of my groups` (``nrllm_groupusage``) in
the AI section with ``access => user``, controller
``GroupUsageController``. Reaching it takes the module ticked in the
group's module list and the grant, which the controller checks itself
(the pattern of :ref:`ADR-131 <adr-131>`).

Opening the Analytics module to holders was rejected. It sits in the
administrator-only *Operation* container, which drops it from a holder's
menu, and its page also renders fallback rescues and provider health and
links the billing report and export; each would need its own guard, and a
missed one leaks. The new controller instead *assigns* only what a holder
may see — data that is never computed cannot leak — and reuses everything
else:

*   ``UsageAnalyticsServiceInterface::forUsers()`` returns the same service
    restricted to a list of backend users. Every reader (KPI totals, trend,
    the four breakdowns, the grouped totals, the per-user list) goes through
    one window-and-restriction helper. An empty list matches no row; the
    restriction is never optional on a restricted instance, and only an
    administrator gets the unrestricted service.
*   The KPI tiles, charts and tables moved from the Analytics template into
    shared partials; both pages render the same markup and the same
    ``Analytics.js``.

The group view has no export, no billing report, no fallback rescues, no
provider health and no AJAX endpoint. The Analytics index action now
refuses a non-administrator itself, as its billing and export actions
already did. Administrators keep the full Analytics module unchanged; an
administrator who opens the group view sees every user.

4. Personal data is the operator's decision
-------------------------------------------

The feature makes personal cost data of employees visible to a
non-administrator. Whether that is permitted is a question for the
operator's works council and data protection officer, not for the
extension. The documentation (:ref:`administration-group-usage`) says so
before it explains the setup, and the feature is off until an
administrator sets both the grant and an assignment.

Consequences
============

*   ``BackendUserGrant`` gains a case; the ``@api`` snapshot changes
    additively.
*   ``be_groups`` gains a column; existing groups have it empty, so nothing
    changes on update.
*   ``UsageAnalyticsServiceInterface`` gains ``forUsers()``. The interface
    carries no ``@api`` marker and is not in the API snapshot; an
    implementation outside the extension would have to add the method.
*   A holder sees no usage of a departed (disabled or deleted) editor; an
    administrator still does in the Analytics module.
*   Group budgets remain out of scope.
