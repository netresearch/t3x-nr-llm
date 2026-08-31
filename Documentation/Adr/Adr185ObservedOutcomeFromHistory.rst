.. include:: /Includes.rst.txt

.. _adr-185:

============================================================================
ADR-185: An observed outcome is derived from history, inside a window
============================================================================

:Status: Proposed
:Date: 2026-08-31
:Authors: Netresearch DTT GmbH

.. _adr-185-context:

Context
=======

:ref:`ADR-176 <adr-176>` split the per-call outcome into two sources and
shipped the explicit one. The observed one — did the generated text survive
contact with an editor — was blocked on a fact nobody persisted: which record a
tool write produced. :ref:`ADR-182 <adr-182>` persists it, as a ``tool_write``
step naming a table and a uid.

So the join exists now. What it joins to, and what the answer MEANS, is not
decided, and three things surfaced on the way here that decide it.

**The correlation id is the run's uuid.** ``tx_nrllm_agentrun`` has no
``correlation_id`` column; :php:`AgentRunReference::correlationId()` returns the
run uuid, and every provider round-trip of that run reports under it. An
observed outcome therefore attaches to a RUN, not to one round-trip. ADR-176
says "per-call" and this is narrower than that phrase suggests; a five-round run
that wrote once yields one observed row covering all five.

**There is no UNKNOWN.** :php:`CallOutcome` has five cases and none of them says
"looked, could not tell". The absence of a row cannot stand in for it, because
absence also means "the window has not closed yet" — two facts one storage state
cannot hold, which the engineering constitution's sixth principle forbids.

**The write target names a record, not the fields it wrote.** ADR-182 kept it to
two scalars deliberately. That is enough to ask whether a record changed and not
enough to ask whether OUR text changed.

.. _adr-185-decision:

Decision
========

**An observed outcome is derived once, after a window, from ``sys_history``, and
says UNKNOWN whenever it cannot say better.**

- **A new ``UNKNOWN`` case** on :php:`CallOutcome`, in the observed source. It
  is written, not implied: a derivation that ran and could not decide is a
  different fact from one that has not run.
- **A window, configurable, defaulting to seven days.** Derivation happens after
  it closes, once per write, and the answer is final. Before it closes there is
  no row — which is why UNKNOWN has to be a value rather than an absence.
- **The classification, from history rows AFTER the write's own:**

  .. code-block:: text

     no row, record still present     → ACCEPTED_UNCHANGED
     MODIFY                           → EDITED
     DELETE, or the record is gone    → DISCARDED
     history trimmed past our write   → UNKNOWN

  The last line is the subtle one. History is trimmed oldest-first, so what
  proves our write's row survived is not that SOME row exists at or before it —
  an older survivor answers that for a write whose own row is long gone — but
  that the OLDEST retained row is at or before it.

- **One outcome per RUN, combined from every record it wrote**, by an explicit
  precedence::

     DISCARDED > EDITED > UNKNOWN > ACCEPTED_UNCHANGED

  A run can call more than one write tool and they share a correlation id, so
  judging only the first would let a run whose second write was deleted be
  recorded as accepted. A known negative outranks an unknown; and
  ``ACCEPTED_UNCHANGED`` may only be claimed when every write of the run is
  known to have survived untouched.

- **The already-answered runs are excluded in the QUERY**, not filtered after
  it. A fixed page of the oldest writes would stop advancing the moment a full
  page of them had been answered, and the command would then report no work
  while writes piled up behind it.

- **A CLI command, schedulable**, like the four purge commands this extension
  already ships. Never the request path — ADR-174's rule for the cost signal
  holds here for the same reason.

.. _adr-185-edited:

What EDITED means, exactly, and what it does not
=================================================

**Any modification by a human, in the window, after our write.** Not "a
modification to the text we generated" — that question needs the field list, and
:ref:`ADR-182 <adr-182>` deliberately does not carry one.

The consequence is over-reporting, and it is stated rather than hidden: an
editor who fixes an unrelated field on the same page inside the window makes our
write read as EDITED. On a busy page that is wrong about the model and right
about the record.

The alternative — widen the write target to name the fields — is a real option
and is not taken here, because ADR-182's revisit clause is explicit that the
shape changes when a reader asks, and this reader can begin without it. The
first measured rate of EDITED is what says whether it must.

.. _adr-185-not:

What this does not build
========================

**No promotion rule.** :ref:`ADR-156 <adr-156>`'s second criterion becomes
computable; whether a delta justifies promoting a canary is a decision this
record does not make.

**No editor-level readout.** ADR-176's rule stands: no ``be_user`` on the
outcome row, no per-person view. The question is how the model did, not who
edited.

**Approval is still not quality.** :ref:`ADR-176 <adr-176>` separated them and
:ref:`ADR-184 <adr-184>` added a refusal that is explicitly not a signal. A
stale-refused approval produces no outcome at all, because no write happened.

**No second definition of "changed".** ADR-184 compares preview lines to decide
whether an approval still holds. This compares history rows to decide what
became of a write. Same word, different question, different data — and they must
not be folded together, for the reason ADR-136 gave when it refused to fold the
turn digest into a resource check.

.. _adr-185-consequences:

Consequences
============

- ✅ ``ACCEPTED_UNCHANGED``, ``EDITED`` and ``DISCARDED`` acquire a writer, and
  :php:`CallOutcome::isImplemented()` starts returning true for them.
- ✅ A derivation that cannot decide says so, so "no signal" and "no answer" stay
  apart in the data.
- ⚠️ The outcome is per RUN, because the correlation id is. Any readout that
  calls it per-call is describing something narrower than its name.
- ⚠️ EDITED over-reports by construction until the write target names fields.
  A readout that ignores this will read an editorial habit as a model deficit.
- ⚠️ ``sys_history`` has its own retention, set by the installation and not by
  this extension. A window longer than that retention yields UNKNOWN rather than
  ACCEPTED_UNCHANGED — which is the whole reason UNKNOWN exists.

.. _adr-185-revisit:

Revisit when
============

- **EDITED is measured and high.** That is the trigger for carrying the written
  field names, and the number is what justifies it rather than the suspicion.
- **The precedence proves wrong in practice.** A run whose several writes end
  differently is recorded by the worst of them, which is a choice and not a
  measurement. If the mix turns out to matter, the outcome has to become one row
  per write — and that needs a key the outcome table does not have.
- **A caller needs the outcome per round-trip.** That needs a per-call
  identifier the runtime does not have today, and inventing one is a larger
  change than this record.
