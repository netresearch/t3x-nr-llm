.. include:: /Includes.rst.txt

.. _adr-187:

============================================================================
ADR-187: AI-write provenance is announced, not implemented
============================================================================

:Status: Proposed
:Date: 2026-09-04
:Authors: Netresearch DTT GmbH

.. _adr-187-context:

Context
=======

:ref:`ADR-182 <adr-182>` made a write name the record it produced, so the
extension finally knows — as a queryable fact rather than as free-form JSON
inside a tool call's arguments — that run *R* wrote ``pages:42``. That fact was
built for one reader, the observed-outcome derivation of
:ref:`ADR-185 <adr-185>`, and it answers a second question nobody has asked the
extension to answer itself: **which records on this installation were written by
an AI, and how should that be shown.**

The answers people want are not the same answer. An Article 50 transparency
label on the frontend, a badge in the page module, a line in an existing
editorial audit trail, a nightly report to a compliance team, a hand-off to an
external system that already owns disclosure — each is a different artefact with
a different owner, and every one of them is a policy decision belonging to the
installation rather than to this extension.

Two paths were open. Implement content labelling here, with configuration for
the cases above; or state the fact and stop. The first would put this extension
in the business of deciding what a site says about itself, which is the site's
business, and would need a configuration surface for every variant — the shape
:ref:`ADR-064 <adr-064>` already refuses for privacy policy.

.. _adr-187-decision:

Decision
========

**The extension says that an AI wrote a record. It never says what anyone should
do about it.**

- A ``final readonly`` PSR-14 event,
  :php:`Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent`, carries three
  values: the correlation id (the run's uuid, :ref:`ADR-153 <adr-153>`), the
  :php:`RecordReference` of the row, and a :php:`WriteKind`.
- **No listener ships in this extension**, and none is planned. An event with a
  shipped listener is a feature with an extension point bolted on; this is an
  extension point.
- **No record payload.** Not the field values, not a before/after, not a
  rendered excerpt, not even the tool's name. A payload is a copy, and a copy of
  editorial content is a second place for it to leak from and a second place for
  it to go stale. The reference names the row; a listener reads it under its own
  permissions at the moment it needs it.
- **The kind is a new enum, not** :php:`ToolEffect`. That enum classifies a
  write by whether repeating it is safe, because its reader is the at-least-once
  queue (:ref:`ADR-111 <adr-111>`). A consumer deciding what to say about a
  record needs to know whether the record was brought into being or changed, and
  the two axes cross: ``move_content_element`` is an idempotent UPDATED,
  ``attach_file_to_content_element`` a non-idempotent CREATED. Neither is
  derivable from the other, so the tool declares both.
- **Two cases, no third.** A deletion would need one and no builtin deletes. The
  case arrives with the first deleting writer — a value nothing emits is a value
  nothing can be tested against.

.. _adr-187-where:

Where it is dispatched, and why there
=====================================

From :php:`ToolLoopService::invoke()`, the single call site of
:php:`ToolInterface::execute()` in this extension. "Exactly once per successful
write" is then a property of the code's shape rather than of seven writers
remembering to dispatch — and it stays true for the eighth.

That placement has three consequences worth stating rather than discovering:

**It fires before the run trace persists the step for the same call.** A
listener must not try to join the run's trace for this write; it is not there
yet. The event is self-sufficient by design, which is what lets it be dispatched
at the earliest honest moment instead of the most convenient one.

**Every editorial write reaches it through the resume path.**
:php:`ToolApprovalRule::requiresApproval()` makes every tool declaring a write
effect approval-bound, so a write never executes on a run's first pass: the loop
suspends, a human approves, and the call executes on resume. Both paths go
through ``invoke()``, which is exactly why the choke point was chosen over the
four call sites around it.

**A throwing listener is logged, not propagated.** This is the one place in the
loop where foreign code runs *after* a side effect. The write has landed and a
human has approved it; letting a consumer's label or report take the run down
would turn a completed editorial write into a failed one, and the model's next
move on a failed write is to try it again. The full ``Throwable`` goes to the
log, so the failure is visible without being contagious.

**It can fire twice for one record.** The agent queue is at-least-once
(:ref:`ADR-104 <adr-104>`): a reaped run may re-execute, and an idempotent write
that runs twice dispatches twice with the same correlation id and reference. A
listener that must act once per record deduplicates on that pair. A
non-idempotent write is never auto-retried, so it cannot double this way.

.. _adr-187-attribution:

What it does not carry, and where that is decided
=================================================

The event names the run, not the person. Which backend user a run acted for is
:php:`AiActorContext`'s business and is persisted on the run; a listener that
needs it resolves the run by its uuid. Putting the actor on the event would make
a compliance consumer's convenience into a second copy of an identity this
extension already stores once, and :ref:`ADR-064 <adr-064>` places that decision
with the privacy model rather than with each emitter.

.. _adr-187-consequences:

Consequences
============

- :php:`ToolResult::withWriteTarget()` gains a required second parameter. It is
  on the frozen surface (:ref:`ADR-127 <adr-127>`) and shipped in 0.34.0, so the
  change is announced as breaking. The alternative — an optional kind defaulting
  to something — would be the extension guessing what a tool did, and the tool
  is the only party that knows whether it minted the uid or was handed it.
- A target and a kind are set by one method and by no other, so "a target
  without a kind" is not a state :php:`ToolResult` can be in. That is what lets
  the dispatch site announce a write without inventing a default.
- A write made outside a persisted run announces nothing. A bare
  :php:`ToolLoopServiceInterface` consumer has no correlation id, and the event
  refuses an empty one: a provenance record pointing at no run looks like a
  record while being none.
- Consumers gain a reason not to poll ``tx_nrllm_agentrun``'s trace, which is an
  internal shape this extension changes freely.
