.. include:: /Includes.rst.txt

.. _adr-173:

============================================================================
ADR-173: A self-approved run says so, wherever the approval is shown
============================================================================

:Status: Accepted
:Date: 2026-08-14
:Authors: Netresearch DTT GmbH

.. _adr-173-context:

Context
=======

An approval event records who decided, and the run records who started it.
Until now nothing put the two side by side, so a run its own initiator released
read exactly like one a second person reviewed. An auditor had to hold two uid
numbers in mind and notice they matched.

Self-approval is not an edge case. :php:`AiActorContext::mayActOnRun()` ends
with ``$this->backendUserUid > 0 && $this->backendUserUid === $run->beUser``, so
for anyone who is not an administrator, not a service account and holds no
``AGENT_APPROVE`` grant it is the fallback path; and on a single-operator
install every approval is a self-approval, whichever branch admits it. That is
the right behaviour — refusing it by default would
strand runs forever on the installs that need the extension most.

:ref:`ADR-172 <adr-172>` added ``require_second_approver``, a per-configuration
switch, default off, which refuses self-approval where an operator turns it on.
That is the enforcement half. This record is the visibility half, and ADR-172
makes it worth more rather than less: where the switch is off — everywhere, by
default — an operator may believe four-eyes applies when it does not.

.. _adr-173-decision:

Decision
========

The READOUT compares the two uids in one place,
:php:`Domain\\Enum\\ApprovalAttribution`, and two readers display its answer.

That is a claim about the readout only. The four-eyes *gate* of
:ref:`ADR-172 <adr-172>` compares the same two uids separately, in
:php:`AiActorContext::isInitiatorOf()`, and the two are not equivalent:
:php:`isInitiatorOf()` additionally excludes service accounts, where this enum
sees two numbers and nothing else. Merging them would make a display rule part
of an authorisation decision.

Two uids give four states, and only one of them is a comparison, so the enum has
four cases:

.. list-table::
   :header-rows: 1

   * - Case
     - When
     - What the label says
   * - :php:`SELF`
     - both uids present and equal
     - approved by the person who started the run
   * - :php:`SECOND_PERSON`
     - both uids present and different
     - approved by someone other than the person who started the run
   * - :php:`INITIATOR_UNKNOWN`
     - a decider, no initiator
     - approved, but the record does not say who started the run
   * - :php:`UNRESOLVED`
     - no decider
     - approved, but the record does not say by whom

:php:`fromDecision()` takes the two uids; :php:`fromDecisions()` collapses a run
that passed several fences, ranked ``SELF > UNRESOLVED > INITIATOR_UNKNOWN >
SECOND_PERSON`` so a collapsed row never understates: one self-released fence
makes the run self-approved however many others a colleague signed.

Four of the six case pairings occur; two cannot. The initiator is one value for
the whole run, so :php:`INITIATOR_UNKNOWN` never meets :php:`SELF` or
:php:`SECOND_PERSON`. A run with an initiator mixes :php:`SELF`,
:php:`SECOND_PERSON` and :php:`UNRESOLVED`; a run without one mixes
:php:`INITIATOR_UNKNOWN` and :php:`UNRESOLVED`.
:php:`ApprovalAttributionTest::theOnlyPairingsARunCanShowAreTheFourItsInitiatorAllows()`
enumerates both halves, so the count is checked rather than asserted in prose.

The rank has an accepted price, taken deliberately. A run with no initiator, one
fence decided by user 5 and one whose decider was not recorded, collapses to
:php:`UNRESOLVED` — the row says the decider is unrecorded while the timeline
prints ``decidedBy=5`` for the other fence. Understating one fence is the
direction this readout is allowed to be wrong in; claiming a second pair of eyes
is not.

**No new column.** Both values are already stored — ``tx_nrllm_agentrun.be_user``
and the APPROVAL event's ``decidedBy`` (:ref:`ADR-101 <adr-101>`). The
distinction is derived at read time, so it is also right for every run recorded
before this change.

**Why an enum and not a template conditional.** Two surfaces show it and a third
could. A uid comparison repeated per template is three chances to write
``===`` where the guard against ``0 === 0`` belongs, and the two pages would be
free to disagree about who released a run. The enum is dependency-free, so both
readers use it without either owning the rule.

Three boundaries make the readout predictable:

**Granted approvals only.** A denial carries no attribution. ADR-172 explicitly
permits an initiator to deny their own run, so marking a self-denial would flag
the one case the design calls correct.

**Absent uids are their own answer, never "self".** ``0 === 0`` must not read as
self-approval, and "nobody can tell" must not read as "a second person looked" —
hence a named case rather than a silent omission.

**And the two absences are two answers, not one.** A run a service account or
any non-backend caller started records ``be_user = 0``
(:php:`AiActorContext::isInitiatorOf()` says why: ``be_user`` identifies a
backend user, so such a run matches nobody). A human approving that run is the
normal case, not an edge case, and the record names them. Labelling it
"approved, but the record does not say by whom" would be false on a row that
prints ``decidedBy=5`` two columns to the left. What the record does not say is
who started the run, so that is what :php:`INITIATOR_UNKNOWN` says.

The mirror — an initiator with no decider — is reachable, and :php:`UNRESOLVED`
covers it: the readers coerce a ``decidedBy`` that is not an int (a truncated,
corrupt or hand-edited payload) to 0 rather than dropping the approval. So the
decider is asked first, and an unnamed decider is the whole answer whatever the
run says.

A sessionless approval is **not** a second way in, although
:php:`currentBackendUserUid()` returning 0 makes it look like one. Both approve
entry points (:php:`AgentRunController::approveAction()`,
:php:`ToolPlaygroundController::resumeAction()`) pair that uid with
:php:`currentActor()`, which answers :php:`AiActorContext::anonymous()` for uid
0 — no admin flag, no service account, no grants. :php:`mayActOnRun()` refuses
that actor in :php:`ResumeCoordinator::approve()` before any approval is
recorded, which
:php:`AiActorContextTest::anAnonymousActorMayNotActOnAnyRun()` pins for every
scope and for a run whose own ``be_user`` is 0. The case guards against a
payload, not against a session.

**A readout, not a control.** Nothing here refuses anything. Whether
self-approval is allowed stays ADR-172's question.

.. _adr-173-surfaces:

Where it appears
================

**The run timeline** (:php:`RunTimelineFactory`, ADR-153). The approval row
carries the derived label next to the ``decidedBy`` uid it already showed. This
is the audit surface: one run, end to end.

**The approvals inbox's recent-runs table.** A run is listed there before anyone
opens it, and this is the answer to "should an auditor scanning a list see it
without clicking into each run" — yes. Scanning twenty rows and opening each to
learn which ones released themselves is how the distinction stays unnoticed,
which is the defect this record closes. It also makes the table state something
it never stated before: which listed runs recorded a GRANTED approval. An empty
cell is not the complement of that — it is "no fence", "denied", and "the
deciders could not be loaded" at once, which is why the column is not a fence
inventory.

The column is also the one datum this list carries that :php:`showAction` would
refuse the same viewer: an ``agent_approve`` grant widens the list but not the
detail page (:ref:`ADR-153 <adr-153>`), and the attribution is derived from
approval events of runs a grant holder may list without opening. Deliberate and
bounded — four labels and an empty cell, naming nobody, the same audit-only
class as the ``decidedBy`` uid the module already treats that way. Restricting
the column to the viewer's own rows would blank it on exactly the rows an
approval grant exists to review.

The cost is one statement per page render, not one per row:
:php:`AgentRunRepositoryInterface::findApprovalDeciders()` reads the approval
events of every listed run in a single ``IN`` query. A per-row
:php:`findEvents()` would have been twenty queries for a marker, which is not a
price a context table may charge.

**The governance readout does not show it**, because it shows no approvals. That
tab is an effective-policy and simulation surface (:ref:`ADR-140 <adr-140>`,
:ref:`ADR-157 <adr-157>`): it answers whether approval *would* be required for a
tool, never who decided one that happened. Adding a decision readout there would
be a second audit surface competing with the run timeline, not a use of this
derivation.

**The waiting card does not show it either.** It is the surface where a decision
is about to be made, not one where an approval is shown; a warning there is
about a future act, which is ADR-172's subject and not this one's.

.. _adr-173-consequences:

Consequences
============

The marker is a badge (``text-bg-warning``) for :php:`SELF` and plain secondary
text for the other three. Self-approval is a fact worth spotting, not a failure
of the system — the same reasoning that keeps ``text-bg-danger`` out of this
project's templates.

All four labels are full sentences rather than a short badge vocabulary. A
column of one-word chips ("Self", "Second person", "Unknown") reads faster and
says less: the two unknowns differ in *which* half of the record is missing, and
that is the distinction an auditor is here for. The cells are wider than the
table's other columns and the label wraps; that is the accepted cost.

Both surfaces render one partial,
``Partials/Backend/AgentRun/ApprovalAttribution.html``, with a branch per case
and an English ``default`` on each. A key assembled from the case value
(``runs.attribution.{attribution}``) would carry no fallback, so a case whose
label is missing would render an empty cell on both pages — the failure a
reviewer cannot see in the diff that adds a case.

A store error while loading the deciders costs the marker and leaves the inbox
standing: :php:`AgentRunPersister::findApprovalDeciders()` degrades to an empty
map, which renders no attribution
(:php:`AgentRunPersisterTest::aDeciderReadThatThrowsDegradesToAnEmptyMap()`,
against a repository that throws). That is the safe direction — the readout can
fail to state that one person decided, but it can never invent a second one.

Runs recorded before ADR-172 shipped will overwhelmingly show :php:`SELF`. That
is not a regression appearing; it is the existing record finally being read.
