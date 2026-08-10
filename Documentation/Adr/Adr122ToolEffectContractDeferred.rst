.. include:: /Includes.rst.txt

.. _adr-122:

============================================================================
ADR-122: The side-effecting tool contract waits for a side-effecting tool
============================================================================

:Status: Accepted (premise expired — see :ref:`ADR-135 <adr-135>` and :ref:`ADR-136 <adr-136>`)
:Date: 2026-07-29
:Amended: 2026-08-09 by :ref:`ADR-135 <adr-135>` and :ref:`ADR-136 <adr-136>`
:Authors: Netresearch DTT GmbH

.. note::

   Two of the three facts this ADR reasons from have expired.

   "All 44 builtin tools read" was true on 2026-07-29 and is not true now:
   :ref:`ADR-135 <adr-135>` shipped ``update_page_metadata`` and a second
   writer followed. "The preview has no caller and no display" was answered by
   :ref:`ADR-136 <adr-136>`, which produces it at suspend time.

   The decision still holds — no framework arrived with the first writer, and
   the idempotency scope still has no reader. Read this record for why the
   contract was not built ahead of a writing tool, not for what ships today.

.. _adr-122-context:

Context
=======

The roadmap asked to promote the tool effect declaration (:ref:`adr-111`) into
a tool-facing interface with an idempotency scope and an optional preview, "so
writing tools can be built against a contract instead of a convention".

Three facts decided this differently.

**No tool writes.** All 44 builtin tools read. None implements
:php:`ToolEffectInterface`; every one takes the ``READ_ONLY`` default. The write
path exists and is correct — the lease-before-op fence, the fail-closed audit,
the retry refusal — but nothing exercises it with a real tool.

**The idempotency scope has no reader.** The only place an effect crosses a
process boundary is the ``pending_effect`` column, and its single consumer
reduces it to one bit: may this run be retried. A scope value would be a field
nothing branches on.

**The preview has no caller and no display.** The one surface that could show it
is the approval card, and that is reachable only for tools implementing a
separate marker interface, which declaring an effect does not imply. It would
also have to run inside the reviewing administrator's request rather than the
run's actor context, which :ref:`adr-083` forbids reading around.

Promoting :php:`getEffect()` onto :php:`ToolInterface` would additionally break
every builtin and every third-party tool built against the public DI tag, for no
behavioural gain.

.. _adr-122-decision:

Decision
========

Do not build the interface, the scope or the preview yet. A contract designed
before the first writing tool guesses at the shape that tool needs, and this
codebase has just spent three changes removing exactly that kind of guess: an
argument that looked like enforcement and was read by nothing.

Do the three things that are real today.

**Clear the write fence on requeue.** ``applyRequeueSet`` cleared the claim and
the lease but left ``pending_effect`` standing. A requeued run has not started
its next attempt, so the fence describes a write that is no longer in flight —
and a standing ``NON_IDEMPOTENT_WRITE`` dead-letters the run whatever the retry
budget says. Narrow but reachable: a step naming a tool the registry no longer
knows resolves fail-closed to ``NON_IDEMPOTENT_WRITE``, so a tool removed or
renamed between attempts stamps the fence for real.

**Pin which tools write.** A new coverage test asserts the set of tools
resolving to a write effect, currently empty. The declaration is opt-in, so
forgetting it is silent and costs the tool its fence and its audit. The test
turns that silence into a failing assertion the first time someone adds a
writer.

**Correct the roadmap.** It claimed "at-least-once queue delivery with
idempotent tool effects" as shipped. What shipped is a declared effect
classification and a fail-closed write audit, with no writing tool to exercise
either.

.. _adr-122-consequences:

Consequences
============

Nothing changes for the 44 existing tools.

The first writing tool will find the machinery waiting for it and the coverage
test asking it to declare itself. Whether it then needs an idempotency scope, a
preview, or something neither of those describes is a question that tool can
answer and this one cannot.

.. _adr-122-revisit:

Revisit when
============

A tool that mutates is proposed. At that point the three deferred pieces should
be reconsidered against what it actually needs — starting from the tool, not
from this ADR.

One design constraint surfaced while investigating and is worth recording so it
is not rediscovered: making a completed non-idempotent write safely replayable
would require a tool-result dedup store, and that in turn changes the stale-run
reaper's unconditional dead-letter policy. Those two move together or not at
all.
