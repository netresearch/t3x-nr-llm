.. _adr-157:

=========================================================================
ADR-157: The simulation covers the run, and answers for an actor
=========================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-145 <adr-145>` (the simulator gains the remaining gate, an
         actor, and an audit decision)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-145 <adr-145>` built a simulator on the Governance tab: pick a
configuration and a tool, and the page runs :php:`ToolCallPolicy::decide()` —
the call the runtime makes — and renders the answer.

It closed with two open items, both stated as consequences rather than as
defects:

- the simulator covers the tool gate, and the input-context gate
  (:ref:`ADR-144 <adr-144>`) "is answerable the same way and is not wired in
  yet". :ref:`ADR-148 <adr-148>` wired in the routing decision as a separate
  readout section, which answers "why this model" but does not participate in
  the simulator's verdict;
- the simulator answers for the operator running it, and "would this be
  allowed for an editor" needs a user picker, "which is a separate surface".

Both matter because a run is stopped by whichever gate refuses FIRST, and a
page that says :guilabel:`Allowed` while the input-context gate would refuse
the send, or while routing resolves no model at all, is not a partial answer.
It is a wrong one, given to an operator who came to the page precisely to
avoid guessing.

Decision
========

**One verdict, four axes, every axis visible.** The simulation asks the tool
gate, the input-context gate, routing eligibility and the approval requirement,
and folds them: ``ALLOW`` when every axis permits and no human decision is
needed, ``ALLOW + APPROVAL`` when a human decision is, ``BLOCK`` when any axis
refuses. Each axis keeps its own row, because the fix differs per axis — a tool
group, a data class, a model catalogue, an approval workflow — and a verdict
that could not say which gate decided would send the operator looking in four
places.

``ALLOW + APPROVAL`` is a third outcome rather than a footnote on ``ALLOW``. A
call that runs only after a human says yes is not the same as one that runs
unattended, and collapsing the two would make the approval axis invisible at
exactly the moment it decides.

**The input-context gate gets a decision method, and the enforcement path uses
it.** :php:`InputContextTrustGate::decide()` resolves, compares and returns an
:php:`InputContextDecision`; :php:`assertPermitted()` calls it, records the
governance event and throws. One rule, two callers.

Catching the exception in the simulator was the obvious cheap alternative and
is WRONG. Observe mode does not throw at all: for a configuration the runtime
records as ``context_blocked`` and lets through, a catch sees nothing and
reports "allowed". The decision object carries `zoneRefused` and `enforcing`
separately, so "the gate refused" and "the send proceeded" are both sayable.

An undeclared configuration reports a null zone and a null ceiling rather than
the values it would have resolved. Nothing was compared, so nothing is claimed
— the same discipline :php:`RoutingReadout` applies to fixed mode. It also
keeps the gate's early return: resolving a zone walks the fallback chain
through the repository, and the hot path must not pay for a comparison it
never makes.

**The approval predicate becomes one resolver with three callers.**
:php:`ToolApprovalRule::requiresApproval()` is what :php:`ToolLoopService`'s
approval scan, :php:`ToolRegistry`'s boot validation and the simulator all ask.
The two copies it replaced had already drifted: the registry exempted every
:php:`RemoteToolInterface`, including one carrying the
:php:`RemoteApprovalInterface` declaration the loop honours — so a remote tool
the loop would suspend for approval was still registrable alongside
:php:`RequiresInputInterface`, which is exactly the deadlock that check exists
to prevent. The shared rule closes it. No shipped tool implements the
combination, so nothing that registers today stops registering.

**The actor is a backend user, resolved read-only.** The picker offers the
backend users the rest of the backend offers, and the selection is resolved
through :php:`ActingBackendUserResolverInterface` — the seam a queue worker
already uses to authorise for the user who queued its work
(:ref:`ADR-083 <adr-083>`). Privilege is read from the fresh database record,
so the picker can lower privilege but never mint it. There is no session
switch, no execution as the user and no write of any kind.

:php:`ToolCallPolicyInterface::decide()` is unchanged. It is ``@api`` and takes
a raw :php:`BackendUserAuthentication`; widening it to take an
:php:`AiActorContext` would be a breaking change to make a readout convenient,
and the resolver already produces exactly what the gate takes.

**Three of the four axes are global, and the page says so.** Routing reads the
model catalogue through :php:`ModelRepository::findActive()`, which ignores
enable-fields and takes no user; the input-context gate compares a
configuration's declared classes against the trust zone it can reach; the
approval requirement is a property of the tool's declaration. Only the tool
gate reads the actor, through ``requiresAdmin()``. The readout carries a scope
column stating this per axis. A simulator that answered identically for every
actor on three axes without saying so would imply a dimension that is not
there, which is worse than not offering the picker at all.

**The picker offers users, not usergroups.** A group is not resolvable to an
acting backend user, and no axis in this simulation reads group membership on
its own: the tool gate reads ``isAdmin()``, and a real user brings their groups
with them from the database. A group entry would have been a control with no
reader.

.. _adr-157-audit:

**A simulation is not audited.** This is the decision the record owes an
explicit answer, because either choice is a behavioural difference. The
runtime writes a :php:`GovernanceEvent` when it BLOCKS a call. A simulation
blocks nothing, so recording one would put rows into the audit for calls that
never happened, and the audit's only load-bearing property is that every row
is something the installation actually did. An operator reading
``context_blocked`` rows to decide whether ``enforce`` is safe (the workflow
:ref:`ADR-113 <adr-113>` and the administration guide describe) would be
counting their own experiments.

The cost is real and is not hidden: "who checked what, and when" cannot be
answered from the audit. The module is admin-only, the simulation performs no
inference and spends no tokens, and every gate it asks is read-only — so the
action being unlogged grants nothing. If a future requirement needs it, the
answer is a separate simulation log with its own retention, not rows in the
governance stream that the enforcement workflow reads.

**The simulator lives in the tool module.** Core may not import
``Service\Tool`` (:ref:`ADR-090 <adr-090>`, enforced by ``ModuleSeamTest``),
and the thing being simulated is a tool call: the entry point takes a tool
name and half its collaborators are tool-module classes. The two core gates it
also asks are a dependency in the allowed direction.

Consequences
============

✓ An operator can answer "would this specific call pass the tool gate, the
input-context gate, routing and the approval check, for this specific person"
in one place, and see which gate decided.

✓ The approval requirement has one definition. Narrowing the remote exemption
is one edit instead of three kept in step by a comment.

✓ The input-context gate can be asked without being triggered, and observe
mode is reportable rather than invisible.

✓ No second policy engine, and no widened ``@api`` signature. Every axis is the
runtime's own service.

◐ The simulation does not resolve a serving model, so it asks
:php:`InputContextTrustGate::decide()` without one and gets the zone the
configuration's own relation gives. For a criteria-mode record that is the
fail-closed ``EXTERNAL_GLOBAL`` (:ref:`ADR-149 <adr-149>`), while the runtime —
which has resolved a model by then — may read a weaker declaration as permitted.
The page is therefore stricter than the send it describes, never laxer: it can
warn about a refusal that will not happen, and cannot miss one that will.

◐ The verdict is a fold of four axes, evaluated together. The runtime evaluates
them at different moments in a run, so a real call that fails the tool gate
never reaches routing. The page reports all four regardless, which is more than
the runtime would have said — deliberately, because an operator fixing one
refusal wants to know whether the next one is waiting behind it.

◐ Routing is asked for a tool-calling operation, because that is the run a tool
simulation describes. A configuration used for something else may route
differently, and the readout below the simulator is where that is asked.

✕ Configuration access is not one of the four, and it is actor-scoped today.
:php:`ConfigurationResolver::actorMayUse()` (:ref:`ADR-070 <adr-070>`) reads
``backendGroupIds`` and refuses a group-restricted configuration for a
non-member; the picker's configuration list is unfiltered. That pairing
therefore reads ``Allowed`` here and is refused at runtime. Budget and
guardrails are outside the four as well, but neither is a pairing the picker
can produce. The docs page states the limitation next to the picker.

✕ Simulations leave no trace. See :ref:`the audit decision <adr-157-audit>`.

✕ The picker cannot answer for a usergroup, a service account, or a frontend
user. Only a backend user resolves to the identity the tool gate takes.

Revisit when
============

An axis becomes actor-scoped that is not today — a per-user model catalogue, or
a configuration-access check folded into the verdict — or a requirement appears
for a record of who simulated what. The first widens the scope column; the
second is a new log, not a change to the governance stream.
