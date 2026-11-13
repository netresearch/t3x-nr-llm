.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-permissions:

========================
Backend user permissions
========================

nr_llm is administrator-only by default: every module and every AJAX
action denies non-admins, and nothing changes at update time. Access for
non-admin users is opened **per capability grant** (:ref:`ADR-130
<adr-130>`), never wholesale.

Assigning grants
================

Grants are ordinary TYPO3 custom permission options. In the
**backend group** record (:guilabel:`Module permissions` tab, section
:guilabel:`AI (nr_llm) permissions`), tick the grants the group should
hold. Notes:

- Grants are **group-scoped** — a backend user without groups cannot hold
  a grant.
- Administrators hold every grant implicitly.
- Revoking a grant takes effect with the user's next request.

.. figure:: /Images/backend-permissions-grants.png
   :alt: The AI (nr_llm) custom permission options for a backend group, with
      "See the AI usage of assigned groups" ticked, and the field "AI usage
      visible for groups" below them
   :class: with-border with-shadow
   :zoom: lightbox

   The custom grants for a backend group, here a head editors' group holding
   ``usage_view_groups`` with two assigned groups. In the default TYPO3 13.4
   and 14.3 group form, find custom options under :guilabel:`Module
   permissions`. The screenshot illustrates the permission section;
   customized forms can place it elsewhere.

Available grants
================

Execute AI tasks (``tasks_use``)
    Run existing AI tasks and refresh their input data. What a task may
    read and which model and configuration it uses is defined by whoever
    manages the task — that is the trust boundary: task managers define,
    grant holders execute. Every run is pre-flighted against the user's
    own :ref:`usage budget <administration-user-budgets>` and attributed
    to them. The grant also shows the user their own usage against that
    budget and the cost estimate of a run
    (:ref:`administration-tasks-editor-usage`).

    Since :ref:`ADR-158 <adr-158>` the same grant opens the **Editor Action
    Center** in that module. It does not decide which actions appear there:
    that is the tool gate's answer plus access to the default LLM
    configuration — a user outside its :guilabel:`Allowed backend groups` is
    offered nothing and can start nothing — and every writing tool is disabled
    until an administrator enables it. Starting an action always suspends for
    approval before anything is written.

Approve suspended AI runs (``agent_approve``)
    Approve, deny or answer agent runs suspended for a human decision —
    including runs started by **other** users. Without this grant a user
    can only ever decide their own runs. Deliberately part of no
    recommended preset: granting it is an explicit trust decision.

See the AI usage of assigned groups (``usage_view_groups``)
    See the AI usage and estimated cost of every member of the backend
    groups selected in the group record's :guilabel:`AI usage visible for
    groups` field, in the module :guilabel:`AI > AI usage of my groups`
    (:ref:`ADR-277 <adr-277>`). Without an assignment the grant shows
    nobody. Who counts as a member, and what is never shown, is described
    in :ref:`administration-group-usage`.

    ..  warning::
        This grant makes **personal data of other users** visible to a
        non-administrator. Clear it with your works council and your data
        protection officer before you grant it
        (:ref:`administration-group-usage-privacy`). Part of no preset.

Issue A2A tokens for my own account (``a2a_token_self_service``)
    Create A2A tokens that act as the holder's own backend account, and see
    and revoke them, in the module :guilabel:`AI > A2A tokens`
    (:ref:`ADR-294 <adr-294>`). The limits are fields on the same group
    (workspace, validity, profiles) and every one starts at its strict
    value, so the grant alone allows a token that works in a draft workspace,
    expires within 90 days and calls no profile. Administrators do not use
    it: an administrator cannot be the acting user of a client.

    ..  warning::
        A token **bypasses the interactive login**, including multi-factor
        authentication and passkeys. Read
        :ref:`administration-a2a-tokens-security` before you grant it. Part
        of no preset.

Recommended presets
===================

Roles are named grant bundles, not code:

===============  ======================================================
Preset           Grants
===============  ======================================================
AI editor        ``tasks_use``
AI operator      ``tasks_use`` — identical to *AI editor* on purpose: a
                 management grant still awaits a management surface
                 (ADR-131 adds none)
===============  ======================================================

Approval (``agent_approve``) sits in no preset — add it deliberately
where the organisation wants non-admin approvers. The same holds for
``usage_view_groups``: add it only for head editors, together with an
assignment.

Current reach
=============

Grant holders work in the dedicated **AI Tasks** module under the **AI** group
(:ref:`ADR-131 <adr-131>`). Reaching it takes BOTH switches: the module
must be ticked in the group's module list (``access => user``) AND the
grant must be held — the module switch alone never grants execution.
Tasks with the ``table`` input type are not offered to editors (their
record picker has no read boundary yet and stays admin-only).

Holders of ``usage_view_groups`` use the module **AI usage of my groups**
under the same **AI** group, with the same two switches: the module ticked
in the group's module list AND the grant held. Holders of
``a2a_token_self_service`` use the module **A2A tokens** the same way.

Everything else — configuration, providers, models, the playground,
record browsing, the Analytics module with its billing report and export —
remains administrator-only regardless of grants.
