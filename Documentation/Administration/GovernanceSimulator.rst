.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===============================
Inspecting the effective policy
===============================

.. _administration-governance-simulator:

Would this be allowed?
======================

Pick a configuration, a tool and — optionally — a backend user, then press
:guilabel:`Simulate`. The tab runs that call past the five gates listed below
and reports one verdict plus each gate's own answer
(:ref:`ADR-157 <adr-157>`, :ref:`ADR-167 <adr-167>`).

The verdict is one of three:

``Allowed``
   All five gates permit the call and it would run unattended.

``Allowed, after a human approves``
   All five gates permit the call, and the tool is approval-bound
   (:ref:`ADR-134 <adr-134>`): the run suspends and waits for a decision
   before it executes. Folding this into ``Allowed`` would hide the axis at
   exactly the moment it decides, so it is its own outcome.

``Blocked``
   At least one of the five refuses. The table says which.

Five gates are asked, each through the service the runtime itself calls:

.. list-table::
   :header-rows: 1

   * - Gate
     - What it decides
     - Depends on the actor?
   * - Configuration access (:ref:`ADR-070 <adr-070>`)
     - whether this backend user may use this configuration at all, given the
       backend groups it is restricted to
     - **Yes** — through the user's group membership
   * - Tool gate (:ref:`ADR-094 <adr-094>`)
     - registered, enabled, permitted, within the configuration's tool groups,
       within the provider trust zone's data-class ceiling
     - **Yes** — through the tool's ``requiresAdmin()``
   * - Input-context gate (:ref:`ADR-144 <adr-144>`)
     - whether the snippets and skills this configuration injects may reach the
       trust zone it can send to
     - No
   * - Routing (:ref:`ADR-142 <adr-142>`)
     - whether any model resolves for a tool-calling run at all
     - No
   * - Human approval (:ref:`ADR-134 <adr-134>`)
     - whether the tool is bound to an operator decision
     - No

**Two axes are actor-scoped, and the table says so.** Routing reads the model
catalogue with enable-fields ignored and no user context, the input-context
gate compares a configuration against a trust zone, and the approval
requirement is a property of the tool's own declaration. The two that read the
user are the tool gate, through the tool's ``requiresAdmin()``, and
configuration access, through the backend groups the record carries. A picker
that implied five per-user answers where there are two would be worse than no
picker.

The configuration selector lists every active configuration and applies no
membership filter, so a group-restricted configuration paired with a
non-member is a pairing the picker makes easy to produce. The tab asks
``ConfigurationResolver`` the same question the runtime asks it, so that
pairing reads ``Blocked`` here and names the restriction as the reason
(:ref:`ADR-167 <adr-167>`).

**Two things that can stop a real call are not asked here**, so ``Allowed``
does not promise them: the budget check and the guardrail pipeline. Both
decide on the call itself — the remaining spend, the text of the prompt — and
a picker supplies neither. The budget check has a second reason, stated in
:ref:`ADR-167 <adr-167>`: it reports usage and limit numbers only when it
refuses, so "close to the limit" and "no data" cannot be told apart from
"plenty left", and a row saying otherwise would be a measurement nobody took.

**For a criteria-mode configuration the input-context gate is stricter here
than at run time.** The simulation asks the gate without a serving model, so
the gate takes the zone from the configuration itself, and for a criteria-mode
record that is the fail-closed ``externalGlobal``. A real call has resolved its
model by then and is judged against that model's provider zone
(:ref:`ADR-149 <adr-149>`). When routing picks a local or privately hosted
model, the simulation can therefore read ``Blocked`` for snippets or skills the
real call sends. For the sources the configuration injects, the input-context
row never reads ``Allowed`` where the gate refuses at run time
(:ref:`ADR-157 <adr-157>`). A caller can also attach snippets or skills to one
run (:ref:`ADR-164 <adr-164>`). The simulation does not see those, and they can
make the real call stricter. To check the model routing would pick, read
:ref:`administration-governance-routing` below.

**The actor picker is not impersonation.** The selected backend user is
resolved read-only through the same seam a queue worker uses to authorise for
the user who queued its work (:ref:`ADR-083 <adr-083>`): the uid is looked up,
the fresh database record supplies the permission surface, and the gates are
asked. No session is switched, nothing executes as that user, and nothing is
written. Privilege comes from the record, so the picker cannot grant rights
the account does not have — and a uid that no longer resolves, because the
account was deleted or disabled, produces a stated refusal rather than a
silent fall back to your own rights.

**A simulation is not recorded.** The runtime writes a governance event when
it *blocks* a call; a simulation blocks nothing, so writing one would put rows
into the audit for calls that never happened. The trade is deliberate and it
has a cost: "who checked what, and when" cannot be answered from the audit.
See :ref:`ADR-157 <adr-157>`.

**Observe mode is visible on both gates.** A configuration the input-context
gate refuses while ``tools.dataClassEnforcement`` is ``observe`` is reported
as permitted *and* refused: the send proceeds and the refusal is recorded.
Reading only "no exception" would have called that allowed.
