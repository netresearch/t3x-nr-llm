.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-group-usage:

============================
AI usage of assigned groups
============================

A head editor can see the AI usage and estimated cost of the editors in
the backend groups assigned to him, without being an administrator
(:ref:`ADR-277 <adr-277>`). He sees these users and nobody else. The
:ref:`Analytics module <administration-analytics>`, its billing report and
its export stay administrator-only.

..  _administration-group-usage-privacy:

Before you switch it on: personal data
======================================

..  warning::
    This feature shows **personal data of other users** to a
    non-administrator: who used AI how often, with which models, and at
    what estimated cost. Depending on your jurisdiction and your
    organisation, that can be a performance or behaviour monitoring of
    employees.

    nr_llm does not decide whether this is permitted. Before you grant the
    permission, clear it with your **works council** (where one exists) and
    your **data protection officer**, and record the purpose. Tell the
    editors whose usage becomes visible, and to whom.

The feature is off until an administrator acts: no group holds the
permission and no group has an assignment after the update.

..  _administration-group-usage-setup:

Switching it on
===============

Two settings, both on the head editor's backend group, and both only
editable by an administrator (backend groups are administrator-only
records):

#.  Tick the permission :guilabel:`See the AI usage of assigned groups`
    (``usage_view_groups``) under :guilabel:`AI (nr_llm) permissions`, and
    the module :guilabel:`AI usage of my groups` in the group's module list.
    The module switch alone shows nobody's usage.
#.  In :guilabel:`AI usage visible for groups`, select the backend groups
    whose members the holder may see.

A user whose groups carry several assignments sees the members of all of
them: the assignment is the union over every group the user belongs to,
including groups he gets through a subgroup — the same way TYPO3 merges
every other group permission. The permission and the assignment can come
from different groups.

..  figure:: /Images/backend-permissions-grants.png
    :alt: The backend group "Head editors" with the permission "See the AI
        usage of assigned groups" ticked under AI (nr_llm) permissions, and
        the groups "News desk" and "Sports desk" selected in "AI usage
        visible for groups"
    :class: with-border with-shadow
    :zoom: lightbox

    Both settings in the backend group record, on the
    :guilabel:`Module Permissions` tab.

..  _administration-group-usage-members:

Who counts as a member
======================

Membership is the one TYPO3 itself uses: a user is a member of a selected
group G when G is one of his groups, or when one of his groups includes G
as a subgroup (recursively).

*   A member of a group that **includes** G counts. Example: the group
    *News seniors* has *News team* as a subgroup; selecting *News team*
    shows the members of *News seniors* too.
*   A user who is only in a **subgroup of** G does not count. Example:
    *News team* has *Shared base* as a subgroup; selecting *News team* does
    not show a user who is only in *Shared base*. Subgroups pass permissions
    down to the groups that include them, not the other way round.

..  important::
    Selecting a group that many other groups include — a typical base
    group carrying shared permissions — shows the members of every one of
    those groups. Select the team groups, not the base groups.

Never shown, even as members:

*   administrators,
*   command-line users (``_cli_*``),
*   disabled, deleted or expired users,
*   members through a hidden or deleted group.

Usage without a backend user (``be_user = 0``, the *system* row of the
Analytics module) is never shown.

..  _administration-group-usage-view:

What the holder sees
====================

The module :guilabel:`AI > AI usage of my groups` lists the selected
groups and the number of users counted, and for the chosen date range:

*   the KPI tiles (estimated cost, requests, tokens, providers, models),
*   the cost and request trend,
*   the breakdowns by provider, model, service and calling extension,
*   the per-user table with each user's monthly budget bar.

Every figure is computed from the members' usage rows only; totals and
charts contain nobody else. The page has no export, no billing report, no
fallback rescues and no provider health, and no AJAX endpoint serves its
data. An administrator who opens the module sees every user.

..  figure:: /Images/backend-group-usage.png
    :alt: The module "AI usage of my groups" for a head editor assigned the
        groups "News desk" and "Sports desk": two users counted, KPI tiles,
        the cost and request trend, the breakdowns by provider, model,
        service and extension, and a per-user table with the two editors
    :class: with-border with-shadow
    :zoom: lightbox

    The module as a head editor sees it: only the members of the two
    assigned groups, not the rest of the installation's usage.
