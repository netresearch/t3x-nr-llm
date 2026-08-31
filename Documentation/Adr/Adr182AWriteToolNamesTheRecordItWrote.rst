.. include:: /Includes.rst.txt

.. _adr-182:

============================================================================
ADR-182: A write tool names the record it wrote
============================================================================

:Status: Accepted
:Date: 2026-08-21
:Amends: :ref:`ADR-122 <adr-122>` (the deferral whose premise expired)
:Authors: Netresearch DTT GmbH

.. _adr-182-context:

Context
=======

:ref:`ADR-176 <adr-176>` made a per-call outcome recordable and shipped the
explicit half: a person rates a result, the row is written against the call's
``correlation_id``. It also found the observed half blocked on something it did
not expect to look for — **nothing persists which record a tool write
produced**. The uid the model chose sits inside
:php:`ToolInvocation::$arguments` as free-form JSON, and
:php:`ToolEffectInterface` declares only :php:`getEffect()`, a classification
rather than an identity. Without a
queryable write target there is no join to ``sys_history``, so
``ACCEPTED_UNCHANGED``, ``EDITED`` and ``DISCARDED`` cannot be derived.

:php:`CallOutcome::isImplemented()` says so in code rather than in prose, and
returns false for exactly those three. `#772` is that gap.

:ref:`ADR-122 <adr-122>` is where the missing fact was deferred. It declined to
build a side-effecting tool contract with a plain reason: "A contract designed
before the first writing tool guesses at the shape that tool needs, and this
codebase has just spent three changes removing exactly that kind of guess." Its
``:Status:`` already records that premise as expired.

**Seven writers exist now** — ``grep -rl 'use WritesThroughDataHandlerTrait'
Classes/Service/Tool/Builtin/`` — and the outcome signal is the first reader the
contract lacked. The shape is no longer a guess; it can be read off what those
seven already do.

.. _adr-182-decision:

Decision
========

**A tool that creates or changes a record says which one, and nothing else.**

- A new ``final readonly`` value object carries a table name and a uid. Two
  fields, both scalar, because that is what the join needs.
- :php:`ToolResult` gains it as an optional property with a null default. That
  class is on the frozen surface (``Tests/Unit/Api/api-surface.txt``), so this
  is an additive change: regenerate the file and announce it under
  ``### Added``.
- :php:`RunTrace` persists it as its own step kind, the way
  :ref:`ADR-179 <adr-179>` persists a dropped forced source. The run already
  carries the ``correlation_id`` the outcome row is keyed by, so no new
  identifier is invented.
- The observed outcome reads that step and joins ``sys_history``.

**Only a writer sets it.** A read-only tool returning a write target is a
defect, not a curiosity, and ``ToolEffectCoverageTest`` already knows which
tools declare a write effect — the same list gates this.

.. _adr-182-rebuild:

The one place this will be lost, named before it is
====================================================

:php:`ToolLoopService` does not return the tool's result. It **rebuilds** it::

    return $result->isError
        ? ToolResult::error($this->bounder->content($result->content))
        : ToolResult::text(
            $this->bounder->content($result->content),
            ...$this->bounder->artifacts($result->artifacts),
        );

A field added to :php:`ToolResult` without touching those five lines would be
dropped on every path, and every test that asserts on the tool's own return
value would still pass. The tool would declare a write target, the loop would
answer with one that has none, and the outcome signal would find nothing —
a surface that holds nothing, arrived at by omission.

This is worth naming because it is the **third** time this shape has cost
something here. `#845` and `#846` lost values to an options rebuild; `#844`
found :php:`withCallerSource()` callable on the specialized services and
dropped by :php:`fromArray()`. In each case the field existed, the reader
existed, and the rebuild in between answered without it.

So the acceptance for this record includes a test that runs a write tool
**through the loop** and asserts the target survives — not one that calls
:php:`execute()` directly, which is the assertion that would have passed in all
three earlier cases.

.. _adr-182-not:

What this does not build
========================

ADR-122 deferred three things. This record takes one of them and leaves the
other two deferred, with their reasons intact.

**No idempotency scope.** ADR-122 declined it for want of a reader, and it still
has none: :php:`ToolEffect` already tells the reaper what may be retried
(:ref:`ADR-122 <adr-122>`, :ref:`ADR-141 <adr-141>`), and no caller asks a finer
question. A scope key would be a second answer to a question already answered.

**No preview contract.** :ref:`ADR-136 <adr-136>` shipped
:php:`ToolPreviewInterface` for the approval card, driven by the arguments
rather than by a declared effect shape. That is the preview this codebase has a
consumer for.

**It does not make the outcome signal true.** It makes it computable. Whether an
edit within some window means the model did badly is a question for `#772` and
for :ref:`ADR-156 <adr-156>`'s second criterion; this record only removes the
reason it cannot be asked.

**It does not touch approval.** ADR-176 separated the two deliberately: an
approval withheld for governance reasons says nothing about quality, and joining
them would poison the metric with policy behaviour.

.. _adr-182-consequences:

Consequences
============

- ✅ ``ACCEPTED_UNCHANGED``, ``EDITED`` and ``DISCARDED`` become derivable, and
  :php:`CallOutcome::isImplemented()` can start returning true for them —
  a change that test will notice.
- ✅ ADR-122's expired premise is discharged for the part that has a reader,
  and its other two deferrals keep their reasons rather than being quietly
  swept in alongside.
- ⚠️ :php:`ToolResult` is on the frozen surface. Additive, but the file must be
  regenerated in the same change or the surface test fails — which is the
  intended behaviour, not an obstacle.
- ⚠️ Seven tools gain one line each. A writer that forgets it reports no
  outcome and fails no test unless the coverage test above is written to
  require one from every declared writer. It is, for that reason.
- ⚠️ The join reads ``sys_history``, which is subject to its own retention
  (:ref:`ADR-064 <adr-064>`). An outcome derived after the history row was
  purged is not "no change"; it is unknown, and must be recorded as neither.

.. _adr-182-revisit:

Revisit when
============

- **A tool writes more than one record per call.** :ref:`ADR-180 <adr-180>` kept
  the one-record rule and named its own trigger; a writer that breaks it makes
  this a list rather than a pair, and the outcome join has to decide what a
  partial edit means.
- **A second reader appears.** One reader is what justifies this record. A
  second one is when the shape should be re-examined rather than extended by
  habit.
- **The idempotency scope finds a consumer.** ADR-122's remaining deferral ends
  the same way this one did: when something asks.
