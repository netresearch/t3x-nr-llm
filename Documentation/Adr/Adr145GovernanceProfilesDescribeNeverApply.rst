.. _adr-145:

=================================================================
ADR-145: Governance profiles describe a posture, they never apply it
=================================================================

:Status: Accepted (both open items are closed — see :ref:`ADR-148 <adr-148>`
         and :ref:`ADR-157 <adr-157>`)
:Date: 2026-08-10
:Amends: :ref:`ADR-140 <adr-140>` (the readout gains consumers, not an apply path)
:Amended: 2026-08-11 by :ref:`ADR-148 <adr-148>`;
          2026-08-11 by :ref:`ADR-157 <adr-157>`
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-140 <adr-140>` gave operators a read-only view of the effective
governance and argued the apply path down: writing extension configuration
rewrites the whole merged array and would materialise every shipped default.

The next questions an operator asks are not "what is set" but "is this right"
and "would this be allowed". Both are answerable without an apply path, and
answering them is what this record decides.

Decision
========

**A profile is a definition.** :php:`GovernanceProfile` is an enum of named
postures — local-only, controlled-cloud, enterprise-strict, development — and
each one is a map of expected values. It enforces nothing, resolves nothing, and
is never consulted at runtime. A profile that could enforce would be a second
policy engine beside the one ADR-140's readout exists to make visible, and two
engines that can disagree are worse than one nobody can read.

**The comparison consumes the readout's output.**
:php:`GovernanceProfileEvaluator::deviations()` takes the rows the readout
produced as an ARGUMENT rather than fetching them. It therefore compares exactly
what the operator is looking at, and has no way to read the resolvers a second
time and disagree with the table above it.

**Silence is a position.** A profile makes no statement about keys it does not
name, and the evaluator reports nothing for them. A profile with an opinion on
every key would force operators to disagree with it about things it was never
meant to describe.

**A deviation carries where to fix it.** There is no apply path — that is
ADR-140's decision, unchanged — so a deviation that only said "wrong" would be
half an answer. Each one names the place the value is set.

**The simulator calls the real gate.**
:php:`ToolCallPolicy::decide()` is the call the runtime makes; the simulator
runs it and renders the answer. It is not a reimplementation of the policy —
a simulator with its own copy of the rules is worse than none, because the two
can disagree and only one of them runs.

The values are judgement
------------------------

The numbers in each profile describe a recognisable posture an operator can aim
at. They are not derived from anything: nothing measured that 30 days is right
for a controlled cloud. A deviation is therefore a question worth asking, never
a defect, and the UI says so.

Consequences
============

✓ An operator can answer "which governance applies", "how does it compare to the
posture we intended", "where do I change it", and "would this specific call be
allowed" — all from one page, all through the runtime's own resolvers.

✓ No second policy engine. The profile is data; the evaluator is a comparison;
the simulator is a call to the existing gate.

✓ Drift is shown, never corrected. ADR-140's reasoning against automatic
mutation is untouched.

◐ The simulator answers for the operator running it, using their own
permissions. That is a real answer to a real question and it is honest about
whose rights it used — but "would this be allowed for an editor" needs a user
picker, which is a separate surface. :ref:`ADR-157 <adr-157>` built it, as a
read-only resolution rather than an impersonation.

◐ The simulator covers the tool gate. The routing decision
(:ref:`ADR-142 <adr-142>`) was wired in the same way by
:ref:`ADR-148 <adr-148>`; the input-context gate
(:ref:`ADR-144 <adr-144>`) was wired in by :ref:`ADR-157 <adr-157>`, which
also folds all four axes into one verdict.

✕ A profile does not describe everything an operator might mean by a posture.
It compares the four keys the readout reports, because those are the ones with a
resolver to ask.

Revisit when
============

The simulator needs an actor other than the operator, or a second gate. Both are
additive: the shape — call the real resolver, render its answer — does not
change.
