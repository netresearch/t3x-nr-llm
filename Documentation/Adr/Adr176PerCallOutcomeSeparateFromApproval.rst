.. include:: /Includes.rst.txt

.. _adr-176:

==============================================================================
ADR-176: A per-call outcome, kept apart from the approval gate
==============================================================================

:Status: Accepted
:Date: 2026-08-18
:Amends: :ref:`ADR-156 <adr-156>` (its second activation criterion cited
    ADR-060 as "the measured quality signal"; that per-model value cannot
    answer it, and this record supplies the one that can)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-156 <adr-156>`'s second activation criterion asks whether quality
degrades on a population served by a cheaper model, and points at
:ref:`ADR-060 <adr-060>` for the measurement. ADR-060 is the golden-set layer:
a value per **model**, graded out of request by a CLI command nothing
schedules. In a canary both arms carry the same model-level value, so the
criterion evaluates to "no difference" by construction, whatever the models
did. The citation has never been able to answer the question it is cited for.

Nothing else fills the gap. Governance events record blocks and never allows,
so there is no denominator. There is no rating, no acceptance signal and no
feedback of any kind, and retries are not counted. A promotion rule phrased as
a quality delta would be a rule over a metric nobody records — the
declaration-nothing-reads failure :ref:`ADR-142 <adr-142>` and ADR-156 exist to
avoid.

Decision
========

**A per-call outcome row, keyed on** ``correlation_id`` — the same key
:ref:`ADR-174 <adr-174>` writes cost and pre-routing facts against, so a
quality figure and a cost figure join without a second identity.

**Approval is not quality, and the two never meet in one code path.** An
approval can be withheld for governance, policy or content reasons that say
nothing about how the model did; :ref:`ADR-172 <adr-172>` even refuses one on
who the approver is. Folding the gate into this metric would measure governance
behaviour and call it quality. No writer of an outcome row reads an approval
state, and the readout has no column for one.

**No person on the row.** No ``be_user`` column, and nr_llm ships no
per-editor readout. What identifies a call is the correlation id; who ran it is
already on the run record, because budgets and
:php:`AiActorContext::mayActOnRun()` need it there. This record does not add a
second copy, and it does not build an anonymisation of its own: erasure rides
on the installation's ``be_users`` lifecycle, which is the framework's
responsibility. Saying "we store no names" would be true and misleading — the
join through the run still exists, and that is stated here rather than
implied.

**Two sources, and they are never averaged into one number.**

.. list-table::
   :header-rows: 1

   * - Source
     - What it means
     - Where it is available
   * - ``explicit``
     - a backend user rated the result
     - every path that shows a result
   * - ``observed``
     - what happened to the text afterwards
     - only where a record was written

That split is not a preference. It was read off the code:
:php:`AiTaskController` renders the result into ``Backend/AiTask/Execute`` and
the editor copies it by hand — there is no write target, so there is nothing to
observe. :php:`EditorActionController` starts tools that write through the
DataHandler against a named ``recordTable`` and ``recordUid``.

So ``observed`` is not a cheaper substitute for ``explicit`` that happens to be
available everywhere. A figure averaging both would compare a population that
can be observed against one that cannot, and the difference between two canary
arms would carry that asymmetry rather than the models' quality. The readout
reports them separately or not at all.

**The observed signal is an event, not a window.** The write tools refuse the
draft workspace and edit live
(:php:`WritesThroughDataHandlerTrait::refuseOutsideLiveWorkspace()`), and
:php:`CreateContentElementDraftTool` creates its element ``hidden``. "Draft"
here is a hidden live record, not a workspace version, so there is no publish
or discard event to hang a measurement on. What a human does next is
observable in ``sys_history``: unhide it, change it first, or delete it. A
record still hidden records no outcome — "undecided" is the honest answer and
not a third quality value.

Sequencing, and the prerequisite it exposes
===========================================

**``explicit`` ships first, and alone.** It needs no new contract: the result
is on screen, the correlation id is in hand, and the row can be written from
the surface that shows the result.

**``observed`` is blocked on something this record did not expect to find.**
Nothing persists *which record* a tool write produced. The uid the model chose
is inside :php:`ToolInvocation::$arguments` as free-form JSON, and
:php:`ToolEffectInterface` declares only :php:`getEffect()` — a classification,
not an identity. Without a queryable write target there is no join to
``sys_history`` and therefore no observed outcome.

That is :ref:`ADR-122 <adr-122>`'s deferred territory. It declined to build a
side-effecting tool contract because no tool had side effects, and its status
already records that premise as expired. Builtins write through the DataHandler
today, and the number is deliberately not repeated here: this record first said
five, and :ref:`ADR-180 <adr-180>` added a sixth within a day. What the argument
needs is that the count is no longer zero, which is what expired ADR-122's
premise. Where a number is wanted,
``grep -l 'use WritesThroughDataHandlerTrait' Classes/Service/Tool/Builtin/*.php``
answers it — the trait every writer uses, rather than a search for the class
name, which also matches the two traits themselves and overcounts by two.

So the prerequisite is not new work invented here; it is the part of ADR-122
whose reason for waiting has gone, and the outcome signal is the first reader
it lacked.

What this does not do
=====================

**It does not route anything.** ADR-156's three activation criteria still
decide, all three, measured over real traffic. This makes the second one
computable; it does not meet it.

**It does not replace ADR-060.** The golden set answers "is model A better than
model B on our prompts", out of request and repeatably. This answers "did this
call go well". Neither substitutes for the other, and ADR-156's criterion 2 now
cites this record for the online half.

**It does not touch the send path.** No writer runs during a send, nothing
blocks, and a call whose outcome is never recorded is the normal case rather
than an error.

**It does not aggregate at write time.** A per-call row is what a canary reads;
collapsing to a per-arm counter at write time would save nothing and destroy
the ability to re-cut the population later.

Revisit when
============

- The task path gains a write target. Then ``observed`` covers it too and the
  population asymmetry above narrows.
- A second reader wants the outcome per person. It is not available, by
  decision — reopen this record rather than adding the column.
- ADR-122's prerequisite lands. The observed source stops being blocked and
  this record's sequencing note becomes history.
