.. include:: /Includes.rst.txt

.. _adr-191:

==========================================================
ADR-191: A cancelled tool call is not a failed one
==========================================================

:Status: Accepted
:Date: 2026-09-06
:Extends: :ref:`ADR-190 <adr-190>` (cancellation crosses the transport boundary
    as a signal), :ref:`ADR-182 <adr-182>` (a tool result is transformed, never
    rebuilt)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-190 <adr-190>` made a cancelled run stop the MCP call it has on the
wire. The transport raises its own exception for that, and
:php:`McpTool::execute()` returned it the way it returns every transport fault —
as :php:`ToolResult::error()`.

A tool result carried one boolean, and that boolean is read all the way to the
screen: :php:`RunTrace::recordToolExecution()` writes it as
:php:`RunStep::$toolIsError`, the step's payload persists it,
:php:`RunTimelineFactory::stepOutcome()` maps it to ``failed`` or ``ok``, and the
runs module renders that under *Outcome*. So an operator who cancelled a run saw
**Failed** next to a server that had answered nothing wrong, and anything counted
from those rows counted their own cancel as a fault.

``McpServers.rst`` promises that "a server that is flaky is visible without
reading transcripts". With cancellations landing in the same bucket, that was no
longer true.

Decision
========

1. **A tool result states its outcome, and the boolean stays.**
   :php:`ToolOutcome` has three cases — ``OK``, ``FAILED``, ``CANCELLED`` — and
   :php:`ToolResult` carries one. :php:`ToolResult::$isError` is unchanged and
   remains true for both non-OK cases, so every consumer that reads it keeps the
   meaning it had; the outcome says WHICH of the two.

   Not a second boolean. Two booleans encoding one tri-state is the shape
   :ref:`ADR-187 <adr-187>` rejected for the write target and its kind, for the
   same reason: it makes "cancelled but not an error" representable, and nothing
   would ever produce it.

   :php:`ToolResult::cancelled()` is fail-closed exactly like
   :php:`self::error()` — no artifacts, no write target — because a call that was
   cut off has no more claim to either than a failed one.

2. **The outcome travels through the bounding transformation.**
   :php:`ToolResult::withBoundedChannels()` rebuilds an error result from almost
   nothing, since a failed call may keep neither artifacts nor a write target.
   The outcome is the exception, and it is the member that most needs to
   survive: every tool result in a run passes through that method, so rebuilding
   it as ``FAILED`` would relabel every cancelled call before it reached the
   audit row. :ref:`ADR-182 <adr-182>` names three values already lost to
   exactly that shape.

3. **One string travels, end to end.** :php:`ToolOutcome::CANCELLED`'s value is
   what :php:`RunStep::toArray()` writes, what
   :php:`RunTimelineFactory::stepOutcome()` returns, what
   :php:`RunTimelineEntry::OUTCOME_CANCELLED` holds, and what the template turns
   into the key ``runs.detail.outcome.cancelled``. Renaming one side alone
   renders an empty cell and nothing else would notice, so the equality is
   asserted rather than assumed.

4. **A step cannot disagree with itself.** :php:`RunStep` refuses a
   ``toolIsError`` that contradicts its ``toolOutcome``, and refuses an outcome
   on a step that is not a tool step. Refused in the value object rather than at
   each writer, because that is the object which serialises the pair: one
   definition of the invariant instead of one per entry point.

5. **A row written before this keeps the outcome it had.**
   ``toolIsError`` decides WHETHER a step states an outcome — it is the field
   every tool step has ever carried — and ``toolOutcome`` decides WHICH. Reading
   the boolean first is what makes older rows render as they always did instead
   of losing their outcome to a field they never held.

6. **The transport says which kind of exception it raised.**
   :php:`McpTransportException` is ``final``, so there is no subclass to catch;
   it carries a flag set only by :php:`self::forCancelledCall()`, and
   :php:`self::isCancellation()` reads it. A code comparison at the call site
   would work too, but a code is a value anyone can copy, and then two places
   would decide what "cancelled" means.

Consequences
============

- :php:`ToolOutcome` is ``@api`` and recorded on the frozen surface, as the
  closure rule requires for a type an ``@api`` signature mentions.
  :php:`ToolResult` gains ``cancelled()`` and ``$outcome``. Nothing on the
  surface changes shape: :php:`RunTrace::recordToolExecution()` keeps its
  signature and derives the outcome from the boolean it already took, and the
  typed :php:`RunTrace::recordToolResult()` reads it off the result. Both build
  the step through one private method, where ``toolIsError`` is DERIVED from the
  outcome rather than passed beside it, so the pair cannot disagree there at
  all.
- The runs module shows *cancelled* as its own outcome, in English and German.
- What is NOT decided here: anything about the remote write. Whether a torn-down
  call mutated something is not knowable from this side — see
  :ref:`ADR-190 <adr-190>`, decision 4, which this record does not revisit.
- :php:`ToolInvocation`, which the loop also builds from a result, is left
  alone: it carries the boolean, is not on the frozen surface, and nothing in
  the inspector chain reads it. A second place stating the outcome would be a
  second place to keep in step.
